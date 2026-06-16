<?php

declare(strict_types=1);

namespace App\Content\Http;

use App\Content\Repositories\ContentTypeRepository;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gates delivery reads by content-type scope or explicit public opt-in.
 *
 * Accepted scoped keys:
 * - read:content for all types
 * - read:content:{type} for one type
 * - wildcard forms accepted by ApiKeyService::scopeSatisfies(), e.g. read:content:*
 *
 * Anonymous reads are allowed only when the requested content type has public_delivery=true.
 */
final class DeliveryAccessMiddleware implements RouteMiddleware
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $type = $this->routeType($request);
        if ($type === '') {
            return Response::forbidden('Content type required');
        }

        $row = $this->types->findBySlug($type);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }

        if ($request->attributes->has('api_key_scopes')) {
            /** @var list<string> $granted */
            $granted = array_values(array_filter(
                (array) $request->attributes->get('api_key_scopes', []),
                'is_string'
            ));

            if (
                ApiKeyService::scopeSatisfies($granted, 'read:content')
                || ApiKeyService::scopeSatisfies($granted, 'read:content:' . $type)
            ) {
                return $next($request);
            }
        }

        if ((bool) ($row['public_delivery'] ?? false)) {
            return $next($request);
        }

        return Response::forbidden('This content type requires a scoped API key');
    }

    private function routeType(Request $request): string
    {
        $params = $request->attributes->get('_route_params', []);
        $type = is_array($params) ? ($params['type'] ?? '') : '';
        return is_string($type) ? trim($type) : '';
    }
}
