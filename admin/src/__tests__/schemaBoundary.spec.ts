import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

// THE SCHEMA-BOUNDARY TEST.
//
// The admin AUTHORS content-type schema: it may create types and replace a type's field schema
// wholesale via PATCH /content-types/{slug}/schema (the settings → content-types flow). What it
// must NEVER touch is the DESTRUCTIVE async migration endpoint (/migrations) — renaming/deleting
// fields with data backfill is an out-of-band ops concern, not something the SPA triggers. If this
// test fails, a source file is reaching for /migrations — fix the file, not the test.
const FORBIDDEN: RegExp[] = [/\/content-types\/[^'"`]*\/migrations/]

// The generated OpenAPI types legitimately contain these path strings; only hand-written source
// is scanned (tests + the generated schema are excluded).
const SKIP_DIRS = new Set(['__tests__', 'node_modules'])
const SKIP_FILES = new Set(['schema.d.ts'])

// Vitest runs from the admin/ root, so src/ is resolvable from cwd (import.meta.url is not a
// file:// URL in the jsdom environment).
const ROOT = process.cwd()
const SRC = join(ROOT, 'src')

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    if (SKIP_DIRS.has(entry) || SKIP_FILES.has(entry)) return []
    const p = join(dir, entry)
    if (statSync(p).isDirectory()) return walk(p)
    return p.endsWith('.ts') || p.endsWith('.vue') ? [p] : []
  })
}

describe('schema-boundary (admin never triggers destructive migrations)', () => {
  it('no source file calls the /migrations endpoint', () => {
    const offenders: string[] = []
    for (const file of walk(SRC)) {
      const src = readFileSync(file, 'utf8')
      if (FORBIDDEN.some((re) => re.test(src))) offenders.push(file.replace(ROOT + '/', ''))
    }
    expect(offenders, `forbidden schema-mutation references in: ${offenders.join(', ')}`).toEqual(
      [],
    )
  })
})
