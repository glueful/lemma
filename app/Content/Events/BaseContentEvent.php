<?php

declare(strict_types=1);

namespace App\Content\Events;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Base class for every Lemma content domain event.
 *
 * The string returned by {@see name()} is a FROZEN public API contract
 * (V1_DESIGN §5): downstream listeners, webhook subscribers and the
 * `lemma:resync` command key off these names, so they must never drift.
 *
 * Payloads are deliberately minimal — entry/asset identity, actor and
 * timestamp — and NEVER carry a `fields` key. Receivers re-fetch the full
 * record through the delivery API using their own credentials; the event bus
 * is not a content channel.
 *
 * Three concrete shapes extend this base:
 *  - {@see BaseEntryEvent}  entry lifecycle  (entry, type, locale, version)
 *  - {@see BaseModelEvent}  content-type change (type only)
 *  - {@see BaseAssetEvent}  asset attach/detach (asset, source entry)
 */
abstract class BaseContentEvent extends BaseEvent
{
    public function __construct()
    {
        // BaseEvent assigns the event id + timestamp and provides the
        // metadata/propagation helpers.
        parent::__construct();
    }

    /**
     * The frozen event name (e.g. `entry.published`). Public API contract.
     */
    abstract public function name(): string;

    /**
     * The minimal, field-free event payload.
     *
     * Implementations MUST NOT include a `fields` key.
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;
}
