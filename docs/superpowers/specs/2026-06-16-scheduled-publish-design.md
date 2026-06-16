# Scheduled Publish / Unpublish — Design

**Goal:** Let editors schedule an entry+locale to publish or unpublish at a future
time, as **deferred execution of the existing publish/unpublish actions** — no second
publish model, no changes to the draft/version/publication lifecycle or the delivery
read path.

**Status:** Design — for review before the implementation plan.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §1. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) §2 ("Scheduled publish/unpublish is deferred").

---

## Definition of behavior (the contract)

> Scheduled publish is deferred execution of the normal publish action. At the
> scheduled time, Lemma validates and publishes the current draft. If validation
> fails, the entry remains unchanged and the scheduled job records the failure.

Unpublish is the symmetric deferred call to the existing unpublish action.

## Scope decisions (settled during brainstorming)

1. **Deferred publish, not a frozen snapshot.** At `run_at`, run the normal
   `PublishService::publish()` — it validates + snapshots the **current** draft, pins,
   emits `entry.published`, invalidates cache, drives search/webhooks. Editors keep
   working until go-live; whatever is in the draft at the scheduled moment ships. The
   accepted tradeoff: an invalid draft at fire time fails the scheduled action and
   nothing goes live (better than hidden unpublished versions + a second mental model).
2. **Dedicated `entry_schedules` table**, not `publish_at`/`unpublish_at` columns —
   supports publish AND unpublish (incl. a publish-at-T1 / unpublish-at-T2 window),
   cancel/reschedule, and an audit trail. Leaves `entry_drafts`/`entry_publications`
   untouched (§2's "no special publication states").
3. **Dedicated `schedules` sub-resource API**, not an overloaded `publish?run_at=`.
4. **The §5 event taxonomy is unchanged.** Firing emits the existing
   `entry.published`/`entry.unpublished`; scheduling/canceling emits no content event.
5. **`run_at` is stored and returned as UTC ISO-8601.** The API accepts an absolute
   ISO-8601 timestamp *with timezone* and normalizes to UTC; naive/local times are
   rejected.

## Architecture

Three units, each independently testable:

- **`entry_schedules` table + `ScheduleRepository`** — persistence and the due-row claim
  (the claim query is repository-owned raw PDO with bound parameters; see below).
- **`lemma:schedules:run` console command** — the executable unit that claims due rows
  and fires them through `PublishService`. It is the primary runner: deterministic,
  manually/admin-invokable, and the direct test target (Lemma's command + test harness
  is more proven than app schedule registration). `config/schedule.php` optionally
  registers it to run every minute as the cron target.
- **`ScheduleController` + routes** — the admin API to create/list/cancel schedules.

`PublishService::publish(string $entryUuid, string $locale, ?string $actor): string`
and `unpublish(string $entryUuid, string $locale): void` are the existing entry points
reused verbatim.

## Data model — `entry_schedules`

New migration `database/migrations/010_CreateEntrySchedulesTable.php` (confirm the next
number during planning):

```
entry_schedules
  id              bigint pk autoincrement
  uuid            char(12)        -- public id (nanoid); used by the cancel endpoint
  entry_uuid      char(12)        -- target (publish granularity = entry + locale)
  locale          varchar
  action          varchar         -- CHECK (action IN ('publish','unpublish'))
  run_at          timestamptz     -- when to fire (stored UTC)
  status          varchar         -- CHECK (status IN ('pending','done','failed','canceled'))
  attempts        int default 0   -- incremented per fire attempt; diagnostics + future manual retry
  failure_reason  text null       -- set when status='failed'
  created_by      char(12) null
  created_at      timestamptz
  updated_at      timestamptz null
  canceled_at     timestamptz null
  canceled_by     char(12) null
  INDEX (status, run_at)                                       -- the poller scan
  UNIQUE (entry_uuid, locale, action) WHERE status = 'pending' -- one pending publish + one pending unpublish per (entry, locale)
```

Notes:
- `action`/`status` use DB `CHECK` constraints (the Glueful schema builder's enum/check
  support) plus app-level enums (`App\Content\Enums\ScheduleAction`, `ScheduleStatus`).
- `timestamptz` is the intent; implement via whatever the Glueful schema builder maps to
  a PostgreSQL timestamp-with-time-zone column.
- The partial unique allows at most **one pending publish and one pending unpublish per
  (entry, locale)**; terminal rows (`done`/`failed`/`canceled`) accumulate as history.

## Firing mechanism — `lemma:schedules:run`

The executable unit is a console command, `lemma:schedules:run` — a deterministic
runner that can be invoked manually/by an admin, used as the cron target, and tested
directly. `config/schedule.php` optionally registers the command to run every minute
(`'schedule' => '* * * * *'`, gated by an env flag e.g. `LEMMA_SCHEDULER_ENABLED`); the
schedule entry just invokes the command, so the command stays the unit of logic and of
testing.

Per run (one "tick"):
1. **Claim** a bounded batch of due rows via `ScheduleRepository` —
   `status='pending' AND run_at <= now()`, ordered by `run_at`, with
   `SELECT … FOR UPDATE SKIP LOCKED`. The row lock is the claim, so overlapping runs /
   multiple workers never double-fire (no extra `processing` status needed). **This
   claim query is owned by `ScheduleRepository` and may use raw PDO with bound
   parameters** — the Glueful query builder doesn't expose `FOR UPDATE SKIP LOCKED`
   safely, and the feature's concurrency guarantee depends on it. **Postgres-only**,
   consistent with the V1 Postgres requirement.
2. **Execute** each row in its own try/catch:
   - `publish` → `PublishService::publish(entry_uuid, locale, created_by)`.
   - `unpublish` → `PublishService::unpublish(entry_uuid, locale)`.
3. **Record outcome** (write survives an action failure — see transaction shape):
   - success → `status='done'`;
   - exception → `status='failed'`, `failure_reason` = the exception message, entry
     unchanged;
   - target entry soft-deleted → `status='canceled'` (target intentionally gone, not an
     operational failure);
   - `attempts` incremented in all cases.

**Behaviors:**
- **No auto-retry in V1.** A `failed` row stays failed (a stale "publish at 9am" must
  not surprise-publish at noon). `attempts` is recorded for diagnostics and a future
  manual-retry endpoint; the editor fixes the draft and re-schedules.
- **Missed runs catch up** — polling `run_at <= now()` fires a schedule whose time
  passed while the worker was down, on the next tick. No separate catch-up logic.
- **Idempotency** — only `pending` rows are claimed; `done`/`failed`/`canceled` are
  terminal and never re-fired.
- **`unpublish` of an already-unpublished entry → `done`** (desired end-state reached;
  no false error).

**Transaction shape (pinned):** claim a bounded batch with `FOR UPDATE SKIP LOCKED`;
execute each schedule in its own try/catch; write `done`/`failed`/`canceled` in a
transaction scope that **survives the action failure** (a `PublishService` exception
must not roll back the status write). `PublishService::publish` runs its own
`db()->transaction()` with `afterCommit` effects; the framework promotes `afterCommit`
to the outermost commit. The plan must verify the nested-transaction semantics with a
test (publish exception → `failed` recorded; success → `afterCommit` events fire once).

## API surface

A `schedules` sub-resource under the existing `/v1/admin` group (consistent with
`/draft`, `/routes`, `/locales`, `/versions`). All under `auth` + `lemma_permission`.

- **`POST /v1/admin/entries/{uuid}/schedules/{locale}`** — Perm `lemma.entries.publish`.
  Body `{ "action": "publish"|"unpublish", "run_at": "<ISO-8601 with offset>" }`.
  - Validation: `action` ∈ {publish, unpublish}; `run_at` must be an **absolute ISO-8601
    timestamp with timezone** and in the **future** (reject past + naive/no-offset →
    422); `locale` validated via `ContentLocaleService`. `run_at` is **normalized to UTC**
    before storage.
  - **Create replaces the existing pending action** of that type (against the partial
    unique): the existing *pending* row is updated/replaced (re-POST = reschedule).
    **Terminal rows (`done`/`failed`/`canceled`) are never touched** — history is
    preserved.
  - Response: the schedule row — `uuid`, `entry_uuid`, `locale`, `action`, `run_at`
    (UTC ISO-8601), `status`, `created_by`, `created_at` — plus a `replaced` boolean
    indicating whether a pending row already existed.
- **`GET /v1/admin/entries/{uuid}/schedules`** — Perm `lemma.entries.read`. Lists this
  entry's schedules across locales: pending rows + recent terminal rows (history), with
  `failure_reason` where present.
- **`DELETE /v1/admin/entries/{uuid}/schedules/{scheduleUuid}`** — Perm
  `lemma.entries.publish`. Cancels a **pending** row → `status='canceled'`,
  `canceled_at`/`canceled_by` set. A terminal row → **409** (not a silent success).

Request DTO: `App\Content\Http\DTOs\ScheduleData` (`action`, `run_at`) — a hydrated
`RequestData` with `#[Rule]`s; the with-timezone + future + UTC-normalization logic
lives in the controller/repository (it's beyond a built-in rule).

## Events

No change to the §5 frozen taxonomy. Firing runs `PublishService`, emitting the existing
`entry.published`/`entry.unpublished` (downstream cannot distinguish scheduled from
manual — correct). Scheduling and canceling are pending-intent changes, **not** content
state changes, and emit no content event.

## Status visibility

- **`GET …/schedules`** — full detail + history (incl. `failure_reason`).
- **`GET /v1/admin/entries/{uuid}/locales`** (existing summary) gains a per-locale
  `scheduled` block: the next pending publish/unpublish + `run_at`, and the last failure
  (status + `failure_reason`) if any — so scheduling state sits alongside
  draft/publication/route state and isn't hidden behind a separate call.

## Testing (Postgres, `LemmaTestCase`)

- **Schema:** table + `CHECK` guards + partial-unique behavior (a 2nd pending publish is
  rejected/replaced; pending publish + pending unpublish coexist).
- **API:** POST creates pending, returns the row with `replaced=false`; 2nd POST same
  action → `replaced=true`, moves `run_at`, terminal rows untouched; POST rejects past
  `run_at`, naive (no-offset) `run_at`, bad `action`, disabled locale (422); GET lists
  pending + terminal history; DELETE cancels a pending row (`canceled` + `canceled_at/by`);
  DELETE on a terminal row → 409; permission gating (publish for POST/DELETE, read for GET).
- **Command `lemma:schedules:run`** (invoked directly — the deterministic test unit):
  claims `run_at <= now()` not future; `publish` → version + pin +
  `entry.published` (recording listener); `unpublish` → publication removed +
  `entry.unpublished`; invalid draft → `failed` + `failure_reason`, entry unchanged;
  soft-deleted entry → `canceled`; unpublish-already-unpublished → `done`; missed run
  fires on tick; terminal rows never re-fired; `attempts` incremented.
- **Nested-transaction test:** a publish exception inside the claim still records
  `failed` (status write survives the action's rollback); on success, `afterCommit`
  events fire exactly once.
- **Concurrency-claim test:** with a held `FOR UPDATE SKIP LOCKED` transaction on a due
  row, a second claim does not see/fire that row — the same pending row cannot be
  claimed/fired twice.
- **Timezone normalization test:** POST with a valid offset timestamp (e.g.
  `2026-07-01T09:00:00+02:00`); assert the stored + returned `run_at` is the consistent
  UTC ISO-8601 equivalent (`2026-07-01T07:00:00Z`).
- **Locales summary:** `GET …/locales` includes the per-locale `scheduled` block.

## Out of scope / follow-ups

- **Auto-retry** of failed schedules and a **manual-retry endpoint** (`attempts` is
  carried for this).
- **Failure notifications** (email/webhook when a scheduled publish fails) — a
  notification concern, surfaced only via the read APIs in V1.
- **Recurring schedules** (cron-like repeating publishes) — V1 is one-shot per row.

## Success criteria

- An entry+locale can be scheduled to publish/unpublish at a future UTC time; the tick
  fires it through the existing `PublishService` path with identical events/cache/webhook
  behavior.
- Invalid-draft-at-fire-time leaves the entry unchanged and records `failed` +
  `failure_reason`, visible in the admin.
- Cancel, reschedule (replace pending), and the publish/unpublish window all work; the
  §5 event taxonomy and the delivery read path are unchanged.
- Full suite green on Postgres CI; the concurrency, nested-transaction, and timezone
  tests pass.
