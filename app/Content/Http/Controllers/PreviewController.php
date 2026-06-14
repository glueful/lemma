<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Preview\PreviewTokenException;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The narrow preview door (V1_DESIGN §6). Two endpoints, two trust models:
 *
 *  - mint()  POST /v1/admin/entries/{uuid}/preview/{locale} — permission-gated at the
 *    route (`lemma_permission:lemma.entries.read`). Issues a short-lived HMAC-signed
 *    token bound to one {entry, locale, ?version}. Minting is authoritative-free (the
 *    reader validates existence), so the actor identity is not needed here — the route
 *    middleware is the gate.
 *
 *  - show()  GET /v1/preview/{token} — UNAUTHENTICATED by design: the token IS the
 *    capability. It carries no user, so the route is rate-limited by IP instead. Every
 *    token problem fails CLOSED: the catch arms are exhaustive, so a malformed, forged,
 *    or expired token can never yield a 200 or a partial read. Error messages are generic
 *    ("Invalid preview token" / "expired") and never leak signature internals.
 *
 * HTTP mapping (fail-closed):
 *   expired            -> 410 Gone
 *   invalid / malformed-> 403 Forbidden
 *   target not found   -> 404 Not Found
 *   valid              -> 200 { preview: {...} }
 */
final class PreviewController
{
    public function __construct(
        private readonly PreviewMinter $minter,
        private readonly PreviewReader $reader,
    ) {
    }

    /**
     * Mint a preview token for one entry+locale. The route's permission middleware is
     * the access gate; an optional `version_uuid` in the body pins a historical version.
     */
    public function mint(Request $request, string $uuid, string $locale): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $versionUuid = is_array($body) && isset($body['version_uuid']) && is_string($body['version_uuid'])
            ? $body['version_uuid']
            : null;

        $token = $this->minter->mint($uuid, $locale, $versionUuid);
        $ttl = $this->minter->ttlSeconds();
        $exp = time() + $ttl;

        return Response::success([
            'token' => $token,
            'expires_at' => date('c', $exp),
            'expires_in' => $ttl,
        ], 'Preview token minted.');
    }

    /**
     * Verify a preview token and return the one draft (or pinned version) it names.
     * Public + rate-limited (no auth). Fails closed on every token/target problem.
     */
    public function show(Request $request, string $token): Response
    {
        try {
            $payload = $this->reader->read($token);
        } catch (PreviewTokenException $e) {
            // Exhaustive: expired -> 410 Gone, every other token fault -> 403 Forbidden.
            // Generic messages — never disclose signature/shape internals.
            return $e->isExpired()
                ? Response::error('Preview link expired', 410)
                : Response::forbidden('Invalid preview token');
        } catch (PreviewNotFoundException) {
            // Valid, unexpired token but the draft/version it names is gone or out of bounds.
            return Response::notFound('Preview target not found');
        }

        return Response::success(['preview' => $payload], 'Preview retrieved.');
    }
}
