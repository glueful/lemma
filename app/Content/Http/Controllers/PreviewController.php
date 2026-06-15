<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Http\DTOs\MintPreviewData;
use App\Content\Preview\PreviewTokenException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
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
    /**
     * @param PreviewMinter $minter issues signed, expiring tokens (mint side)
     * @param PreviewReader $reader verifies a token and resolves its target (read side)
     */
    public function __construct(
        private readonly PreviewMinter $minter,
        private readonly PreviewReader $reader,
    ) {
    }

    /**
     * Mint a preview token for one entry+locale. The route's permission middleware is
     * the access gate; an optional `version_uuid` in the body pins a historical version.
     */
    #[ApiOperation(
        summary: 'Mint a short-lived preview token',
        description: 'Issues a signed, expiring preview token bound to this entry+locale. The token is the '
            . 'capability for the unauthenticated `GET /v1/preview/{token}` endpoint. Body: `version_uuid` '
            . '(optional; preview a specific historical version instead of the current draft). '
            . 'Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Preview token minted.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.read` permission.')]
    public function mint(MintPreviewData $input, Request $request, string $uuid, string $locale): Response
    {
        // version_uuid is optional: absent means "mint from the current draft". Existence /
        // ownership of a pinned version is validated by the reader at read time (domain rule).
        $token = $this->minter->mint($uuid, $locale, $input->version_uuid);
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
    #[ApiOperation(
        summary: 'Read a draft via a signed preview token',
        description: 'Resolves a signed, expiring preview token (minted by '
            . '`POST /v1/admin/entries/{uuid}/preview/{locale}`) and returns the entry\'s current draft — '
            . 'or the specific historical version the token names. This is the ONLY way to read '
            . 'unpublished content. UNAUTHENTICATED by design: the token itself is the capability (no '
            . 'Authorization header). Rate-limited to 60 requests/minute per IP. Fails closed: an '
            . 'invalid/malformed token returns 403, an expired token returns 410, and a token whose '
            . 'target no longer exists returns 404 — all with generic messages.',
        tags: ['Lemma Preview'],
    )]
    #[ApiResponse(200, description: 'The previewed draft (or pinned version).')]
    #[ApiResponse(403, description: 'Invalid or malformed preview token.')]
    #[ApiResponse(404, description: 'The token\'s target entry/version no longer exists.')]
    #[ApiResponse(410, description: 'The preview token has expired.')]
    #[ApiResponse(429, description: 'Rate limit exceeded (60/minute per IP).')]
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
