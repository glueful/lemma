<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;

/**
 * Mirrors DeliveryAccessMiddleware for search: read:content ⇒ all-access; else explicit
 * read:content:{slug} scopes → scoped type uuids; anonymous (null scopes) ⇒ public-only.
 */
final class VisibilityResolver
{
    public function __construct(private readonly ContentTypeReader $types)
    {
    }

    /** @param list<string>|null $grantedScopes null = anonymous (no api_key_scopes attribute). */
    public function resolve(?array $grantedScopes): VisibilityContext
    {
        if ($grantedScopes === null) {
            return new VisibilityContext(false, []);
        }

        if (ApiKeyService::scopeSatisfies($grantedScopes, 'read:content')) {
            return new VisibilityContext(true, []);
        }

        $scoped = [];
        foreach ($grantedScopes as $scope) {
            if (!is_string($scope) || !str_starts_with($scope, 'read:content:')) {
                continue;
            }
            $slug = substr($scope, strlen('read:content:'));
            // Only explicit slugs (no wildcard) resolve to a scoped uuid.
            if ($slug === '' || str_contains($slug, '*')) {
                continue;
            }
            $uuid = $this->types->findUuidBySlug($slug);
            if ($uuid !== null && !in_array($uuid, $scoped, true)) {
                $scoped[] = $uuid;
            }
        }

        return new VisibilityContext(false, $scoped);
    }

    /** Delivery-parity accessibility for a PROVIDED type: all-access, scoped, or public. */
    public function isTypeAccessible(VisibilityContext $ctx, string $typeUuid): bool
    {
        return $ctx->allAccess
            || in_array($typeUuid, $ctx->scopedTypeUuids, true)
            || $this->types->isPublicDelivery($typeUuid);
    }
}
