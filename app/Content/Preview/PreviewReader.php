<?php

declare(strict_types=1);

namespace App\Content\Preview;

use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Verifies a preview token and resolves the ONE draft (or pinned version) it names.
 *
 * This is the only door to draft/unpinned content: the public delivery API cannot
 * see drafts at all. The reader therefore fails closed on every error path —
 *  - a verification failure (malformed / bad signature / expired) propagates the
 *    PreviewTokenException unchanged (the controller branches 403 vs 410); it is
 *    NEVER swallowed into a partial read.
 *  - a missing draft, a missing version, or a version that does not belong to the
 *    token's {entry, locale} throws PreviewNotFoundException (controller → 404).
 * It NEVER falls back to published-or-any other content. The only data it returns
 * is the draft/version the verified token explicitly names.
 */
final class PreviewReader
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
    ) {
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    public function read(string $token): array
    {
        // Propagates PreviewTokenException (malformed/invalid/expired). Do NOT catch:
        // the controller maps the kind to the right status. Fail closed.
        $payload = PreviewToken::verify($token, $this->previewKey($this->context), time());

        return $payload->versionUuid !== null
            ? $this->readVersion($payload)
            : $this->readDraft($payload);
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:string,
     *               version:int,schema_version:int,fields:array<string,mixed>}
     */
    private function readVersion(PreviewToken $payload): array
    {
        $version = $this->versions->findVersionByUuid((string) $payload->versionUuid);

        // Security: a pinned-preview token names {entry, locale, version}. The mint
        // endpoint takes version_uuid from the request body, so a caller could name a
        // version belonging to a DIFFERENT entry. The signature stops them re-pointing
        // the token, but the reader must still confirm the version actually belongs to
        // the token's entry+locale before serving it. Mismatch → not-found (never serve
        // another entry's content).
        if (
            $version === null
            || (string) $version['entry_uuid'] !== $payload->entryUuid
            || (string) $version['locale'] !== $payload->locale
        ) {
            throw new PreviewNotFoundException('Preview version not found for this entry/locale.');
        }

        return [
            'entry_uuid' => $payload->entryUuid,
            'locale' => $payload->locale,
            'version_uuid' => (string) $version['uuid'],
            'version' => (int) $version['version'],
            'schema_version' => (int) $version['schema_version'],
            'fields' => (array) $version['fields'],
        ];
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:null,
     *               version:null,schema_version:int,fields:array<string,mixed>}
     */
    private function readDraft(PreviewToken $payload): array
    {
        $draft = $this->entries->findDraft($payload->entryUuid, $payload->locale);
        if ($draft === null) {
            throw new PreviewNotFoundException('Preview draft not found for this entry/locale.');
        }

        return [
            'entry_uuid' => $payload->entryUuid,
            'locale' => $payload->locale,
            'version_uuid' => null,
            'version' => null,
            'schema_version' => (int) $draft['schema_version'],
            'fields' => (array) $draft['fields'],
        ];
    }
}
