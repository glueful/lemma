<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Services\PublishService;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class PublicationController
{
    public function __construct(private readonly PublishService $publisher)
    {
    }

    #[ApiOperation(
        summary: 'Publish an entry\'s draft',
        description: 'Snapshots the current draft into an immutable version and pins it as the published '
            . 'version for the locale, making it visible to the delivery API. Validates fields against '
            . 'the schema. Requires the `lemma.entries.publish` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Entry published.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.publish` permission.')]
    #[ApiResponse(404, description: 'No entry/draft to publish.')]
    #[ApiResponse(422, description: 'Draft fields fail schema validation.')]
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

    #[ApiOperation(
        summary: 'Unpublish an entry',
        description: 'Removes the publication pin for the entry+locale so it is no longer served by the '
            . 'delivery API. The versions themselves are retained. '
            . 'Requires the `lemma.entries.publish` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Entry unpublished.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.publish` permission.')]
    public function unpublish(Request $request, string $uuid, string $locale): Response
    {
        $this->publisher->unpublish($uuid, $locale);
        return Response::success([], 'Entry unpublished.');
    }

    #[ApiOperation(
        summary: 'Roll back to a previous version',
        description: 'Re-pins a previously published version as the current published version for the '
            . 'entry+locale (re-publish of an existing version). Body: `version_uuid` (required; UUID of '
            . 'the version to re-publish). Requires the `lemma.entries.publish` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Rolled back to the named version.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.publish` permission.')]
    #[ApiResponse(422, description: 'Missing or invalid version_uuid (or it does not belong to this entry+locale).')]
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
