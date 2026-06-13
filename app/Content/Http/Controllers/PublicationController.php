<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Services\PublishService;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class PublicationController
{
    public function __construct(private readonly PublishService $publisher)
    {
    }

    public function publish(Request $request, string $uuid, string $locale): Response
    {
        try {
            $versionUuid = $this->publisher->publish($uuid, $locale, $this->actor($request));
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }
        return Response::success(['version_uuid' => $versionUuid], 'Entry published.');
    }

    public function unpublish(Request $request, string $uuid, string $locale): Response
    {
        $this->publisher->unpublish($uuid, $locale);
        return Response::success([], 'Entry unpublished.');
    }

    public function rollback(Request $request, string $uuid, string $locale): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $versionUuid = is_array($body) ? (string) ($body['version_uuid'] ?? '') : '';
        if ($versionUuid === '') {
            return Response::validation(['version_uuid' => 'required']);
        }
        try {
            $this->publisher->rollback($uuid, $locale, $versionUuid, $this->actor($request));
        } catch (\RuntimeException $e) {
            return Response::validation(['version_uuid' => $e->getMessage()]);
        }
        return Response::success(['version_uuid' => $versionUuid], 'Rolled back to version.');
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
