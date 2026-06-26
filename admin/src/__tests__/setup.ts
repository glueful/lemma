// Vitest global setup.
import { beforeEach, vi } from 'vitest'

// `openapi-fetch`'s createClient() captures `globalThis.fetch`/`globalThis.Request` once, at
// construction. Tests dynamically `await import('@/api/client')` per case and re-stub fetch in
// `beforeEach`, so without a module reset the cached singleton client keeps the first test's
// fetch mock (yielding stale/consumed responses). Resetting the module registry before each test
// makes every `await import()` re-create the client against the current per-test global fetch.
beforeEach(() => {
  vi.resetModules()
})

// In the jsdom environment the global `Request`/`fetch` come from Node's undici, which — unlike
// a real browser — does NOT resolve relative URLs against `location`. openapi-fetch builds a
// `new Request(baseUrl + path)` internally, and the app's runtime config uses a relative apiBase
// (e.g. '/v1/admin'), so the constructor would throw "Invalid URL" before any test assertion.
// This shim makes relative request URLs resolve against the jsdom origin, matching browser behavior.
const OriginalRequest = globalThis.Request

class BaseAwareRequest extends OriginalRequest {
  constructor(input: RequestInfo | URL, init?: RequestInit) {
    if (typeof input === 'string' && input.startsWith('/')) {
      input = new URL(input, globalThis.location?.origin ?? 'http://localhost').toString()
    }
    super(input, init)
  }
}

globalThis.Request = BaseAwareRequest as unknown as typeof Request
