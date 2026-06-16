<?php

declare(strict_types=1);

namespace App\Content\Http;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Computes delivery cache validators (ETag + Cache-Control + Cache-Tag) and handles
 * conditional `If-None-Match` revalidation.
 *
 * The ETag is a strong validator over the published version identity plus the response
 * selection key (field selection / expansions / sort / filter), so any change to either
 * the published content OR the requested shape produces a new tag:
 *   etag = '"' . sha1(versionUuid . '|' . selectionKey) . '"'
 *
 * For a list response there is no single version uuid, so the ETag hashes the
 * concatenation of every member's version uuid (in result order) plus the selection key.
 *
 * `Cache-Control: public, max-age=<ttl>` is emitted with the per-type TTL (delivery is
 * may be publicly readable but the responses are still cacheable). `Cache-Tag` carries the
 * surrogate keys a CDN/cache layer purges on publish: `lemma:entry:{uuid}` for each
 * member entry plus `lemma:type:{slug}` for the whole type.
 */
final class DeliveryEtag
{
    /**
     * Build the ETag for a single published row.
     */
    public function forItem(string $versionUuid, string $selectionKey): string
    {
        return '"' . sha1($versionUuid . '|' . $selectionKey) . '"';
    }

    /**
     * Build the ETag for a list response from its members' version uuids.
     *
     * @param list<string> $versionUuids in result order
     */
    public function forList(array $versionUuids, string $selectionKey): string
    {
        return '"' . sha1(implode('|', $versionUuids) . '|' . $selectionKey) . '"';
    }

    /**
     * True when the request's `If-None-Match` matches the computed ETag.
     */
    public function matches(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }
        foreach (array_map('trim', explode(',', $ifNoneMatch)) as $candidate) {
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return true;
            }
        }
        return false;
    }

    /**
     * A bodyless 304 Not Modified carrying the validator + cache headers.
     */
    public function notModified(string $etag, int $ttl, string $cacheTag): Response
    {
        $response = new Response();
        // setNotModified() sets 304, strips the body to '' and removes body-only headers.
        $response->setNotModified();
        $this->applyHeaders($response, $etag, $ttl, $cacheTag);
        return $response;
    }

    /**
     * Apply ETag / Cache-Control / Cache-Tag headers to a built response.
     */
    public function applyHeaders(Response $response, string $etag, int $ttl, string $cacheTag): Response
    {
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'public, max-age=' . $ttl);
        $response->headers->set('Cache-Tag', $cacheTag);
        return $response;
    }

    /**
     * Build the `Cache-Tag` header value: a per-entry tag for each member plus the type tag.
     *
     * @param list<string> $entryUuids
     */
    public function cacheTag(array $entryUuids, string $typeSlug): string
    {
        $tags = [];
        foreach ($entryUuids as $uuid) {
            $tags[] = 'lemma:entry:' . $uuid;
        }
        $tags[] = 'lemma:type:' . $typeSlug;
        return implode(', ', $tags);
    }
}
