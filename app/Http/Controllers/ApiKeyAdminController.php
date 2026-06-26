<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Glueful\Auth\ApiKey\ApiKey;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin API for the framework `api_keys` store (programmatic, non-session access).
 *
 * The framework ships {@see ApiKeyService} (create/rotate/revoke/verify) and a CLI, but no HTTP
 * surface; this is the system-wide console the SPA's Developers › API Keys page drives. It lists
 * EVERY key with its owner, so any admin holding `system.access` can audit and revoke keys across
 * the instance. New keys are always minted for the calling admin — issuing on another user's behalf
 * is intentionally out of scope (and would need its own policy).
 *
 * The plaintext key is shown exactly once (on create/rotate); only its SHA-256 hash is stored, so a
 * lost key can only be rotated or revoked, never re-read. Gated by `system.access` — see
 * routes/lemma_admin.php.
 */
final class ApiKeyAdminController
{
    private const PER_PAGE_MAX = 100;
    private const GRACE_HOURS_DEFAULT = 24;
    private const GRACE_HOURS_MAX = 720; // 30 days

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/api-keys — every key, newest first; optional status + name filters. */
    #[ApiOperation(
        summary: 'List API keys',
        description: 'System-wide, paginated list of API keys with their owner and status. Optional '
            . '`status` (active|expired|revoked), `q` (name search), `page`, `per_page`. Requires the '
            . '`system.access` permission.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, description: 'API key page.')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) $request->query->get('per_page', '30')));

        $query = db($this->context)->table('api_keys')->orderBy('created_at', 'desc');
        $this->applyStatusFilter($query, (string) $request->query->get('status', ''));

        $search = trim((string) $request->query->get('q', ''));
        if ($search !== '') {
            $query->where('name', 'LIKE', '%' . $search . '%');
        }

        /** @var array{data:array<int,array<string,mixed>>,total:int,current_page:int,per_page:int} $result */
        $result = $query->paginate($page, $perPage);
        $rows = array_values($result['data']);
        $owners = $this->ownerLabels($rows);

        return Response::success([
            'api_keys' => array_map(fn (array $r): array => $this->present($r, $owners), $rows),
            'total' => $result['total'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page'],
        ], 'API keys retrieved.');
    }

    /** GET /v1/admin/api-keys/{uuid} — one key (never includes the secret). */
    #[ApiOperation(summary: 'Get an API key', tags: ['API Keys'])]
    #[ApiResponse(200, description: 'API key.')]
    #[ApiResponse(404, description: 'No such key.')]
    public function show(string $uuid): Response
    {
        $row = $this->rowFor($uuid);
        if ($row === null) {
            return Response::notFound('API key not found.');
        }

        return Response::success(
            ['api_key' => $this->present($row, $this->ownerLabels([$row]))],
            'API key retrieved.',
        );
    }

    /** POST /v1/admin/api-keys — mint a key for the calling admin; returns the plaintext ONCE. */
    #[ApiOperation(
        summary: 'Create an API key',
        description: 'Mints a new key owned by the authenticated admin. Body: `name` (required), '
            . 'optional `scopes` (string[]), `allowed_ips` (string[] of IPs/CIDRs), `expires_at` '
            . '(date). The plaintext key is returned once as `plain` and never stored. Requires '
            . '`system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(201, description: 'Created key + one-time plaintext.')]
    #[ApiResponse(422, description: 'Validation failed.')]
    public function store(Request $request): Response
    {
        /** @var array<string,mixed> $input */
        $input = json_decode((string) $request->getContent(), true) ?: [];

        $name = $this->stringField($input, 'name');
        if ($name === null || $name === '') {
            return Response::validation(['name' => 'A name is required.']);
        }

        $userUuid = $this->currentUserUuid($request);
        if ($userUuid === null) {
            return Response::error('Could not resolve the current user.', 401);
        }

        $expiresAt = $this->expiryField($input);
        if ($expiresAt === false) {
            return Response::validation(['expires_at' => 'Could not understand that expiry date.']);
        }

        $scopes = $this->listField($input, 'scopes');
        $allowedIps = $this->listField($input, 'allowed_ips');

        $created = ApiKeyService::create($this->context, [
            'user_uuid' => $userUuid,
            'name' => $name,
            'scopes' => $scopes !== [] ? $scopes : null,
            'allowed_ips' => $allowedIps !== [] ? $allowedIps : null,
            'expires_at' => $expiresAt,
        ]);

        $row = $this->rowFor($created['key']->uuid);

        return Response::created([
            'api_key' => $row !== null ? $this->present($row, $this->ownerLabels([$row])) : null,
            'plain' => $created['plain'],
        ], 'API key created.');
    }

    /** POST /v1/admin/api-keys/{uuid}/rotate — issue a successor; old key stays valid for a grace window. */
    #[ApiOperation(
        summary: 'Rotate an API key',
        description: 'Issues a new key inheriting the scopes/IPs/expiry of the old one, and sets the '
            . 'old key to expire after a grace window (body `grace_hours`, default 24, max 720). Both '
            . 'keys work during the window. Returns the new plaintext once. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, description: 'New key + one-time plaintext + old key expiry.')]
    #[ApiResponse(404, description: 'No such key.')]
    #[ApiResponse(409, description: 'Key is revoked.')]
    public function rotate(Request $request, string $uuid): Response
    {
        $key = $this->findKey($uuid);
        if ($key === null) {
            return Response::notFound('API key not found.');
        }
        if ($key->isRevoked()) {
            return Response::error('A revoked key cannot be rotated.', 409);
        }

        /** @var array<string,mixed> $input */
        $input = json_decode((string) $request->getContent(), true) ?: [];
        $grace = (int) ($input['grace_hours'] ?? self::GRACE_HOURS_DEFAULT);
        $grace = min(self::GRACE_HOURS_MAX, max(1, $grace));

        $result = ApiKeyService::rotate($this->context, $key, $grace);

        // rotate() returns the old key's data + the new plaintext, but not the new row; the new key is
        // the most recent one pointing back at this key via rotated_from_id.
        $newRow = db($this->context)->table('api_keys')
            ->where('rotated_from_id', '=', $key->id)
            ->orderBy('created_at', 'desc')
            ->first();

        return Response::success([
            'api_key' => is_array($newRow) ? $this->present($newRow, $this->ownerLabels([$newRow])) : null,
            'plain' => $result['new_plain'],
            'old_expires_at' => $result['old_expires_at'],
        ], 'API key rotated.');
    }

    /** DELETE /v1/admin/api-keys/{uuid} — revoke immediately (soft; the row is kept for audit). */
    #[ApiOperation(
        summary: 'Revoke an API key',
        description: 'Marks the key revoked so it stops authenticating immediately. The row is kept '
            . '(revoked_at is set) for audit. Requires `system.access`.',
        tags: ['API Keys'],
    )]
    #[ApiResponse(200, description: 'Revoked.')]
    #[ApiResponse(404, description: 'No such key.')]
    public function destroy(string $uuid): Response
    {
        $key = $this->findKey($uuid);
        if ($key === null) {
            return Response::notFound('API key not found.');
        }

        ApiKeyService::revoke($this->context, $key);

        return Response::success(['revoked' => true], 'API key revoked.');
    }

    // ---- Helpers --------------------------------------------------------------

    private function applyStatusFilter(object $query, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        match ($status) {
            'revoked' => $query->whereNotNull('revoked_at'),
            'expired' => $query->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', $now),
            'active' => $query->whereNull('revoked_at')
                ->whereRaw('(expires_at IS NULL OR expires_at >= ?)', [$now]),
            default => null,
        };
    }

    private function findKey(string $uuid): ?ApiKey
    {
        $key = ApiKey::query($this->context)->where('uuid', '=', $uuid)->first();

        return $key instanceof ApiKey ? $key : null;
    }

    /** @return array<string,mixed>|null */
    private function rowFor(string $uuid): ?array
    {
        $row = db($this->context)->table('api_keys')->where('uuid', '=', $uuid)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve owner uuids to a username/email label in one query.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array{username:?string,email:?string}>
     */
    private function ownerLabels(array $rows): array
    {
        $uuids = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => (string) ($r['user_uuid'] ?? ''),
            $rows,
        ))));
        if ($uuids === []) {
            return [];
        }

        $map = [];
        foreach (db($this->context)->table('users')->whereIn('uuid', $uuids)->get() as $u) {
            $map[(string) ($u['uuid'] ?? '')] = [
                'username' => is_string($u['username'] ?? null) ? $u['username'] : null,
                'email' => is_string($u['email'] ?? null) ? $u['email'] : null,
            ];
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array{username:?string,email:?string}> $owners
     * @return array<string,mixed>
     */
    private function present(array $row, array $owners): array
    {
        $ownerUuid = (string) ($row['user_uuid'] ?? '');
        $owner = $owners[$ownerUuid] ?? null;

        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            // key_prefix is the public, non-secret part (env tag + 8 random chars); safe to display.
            'key_prefix' => (string) ($row['key_prefix'] ?? ''),
            'owner_uuid' => $ownerUuid,
            'owner_label' => $owner['username'] ?? $owner['email'] ?? ($ownerUuid !== '' ? $ownerUuid : null),
            'scopes' => $this->decodeList($row['scopes'] ?? null),
            'allowed_ips' => $this->decodeList($row['allowed_ips'] ?? null),
            'status' => $this->status($row),
            'is_rotated' => ($row['rotated_from_id'] ?? null) !== null,
            'expires_at' => $row['expires_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row */
    private function status(array $row): string
    {
        if (($row['revoked_at'] ?? null) !== null) {
            return 'revoked';
        }
        $expires = $row['expires_at'] ?? null;
        if (is_string($expires) && $expires !== '') {
            $ts = strtotime($expires);
            if ($ts !== false && $ts < time()) {
                return 'expired';
            }
        }

        return 'active';
    }

    /** @return array<int,string> */
    private function decodeList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function currentUserUuid(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');

        return $user instanceof UserIdentity ? $user->uuid() : null;
    }

    /** @param array<string,mixed> $input */
    private function stringField(array $input, string $key): ?string
    {
        return array_key_exists($key, $input) && is_string($input[$key]) ? trim($input[$key]) : null;
    }

    /**
     * Trimmed, de-duplicated list of non-empty strings (scopes / allowed IPs).
     *
     * @param array<string,mixed> $input
     * @return list<string>
     */
    private function listField(array $input, string $key): array
    {
        $values = $input[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[] = trim($value);
            }
        }

        return array_values(array_unique($clean));
    }

    /**
     * Normalize the optional expiry to 'Y-m-d H:i:s'. Returns null when absent/blank, false when the
     * value is present but unparseable (a validation error, not "no expiry").
     *
     * @param array<string,mixed> $input
     * @return string|null|false
     */
    private function expiryField(array $input): string|null|false
    {
        if (!array_key_exists('expires_at', $input)) {
            return null;
        }
        $raw = $input['expires_at'];
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_string($raw)) {
            return false;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return false;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
