import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

// THE SCHEMA-BOUNDARY TEST.
//
// Phase 1 CONSUMES content-type schema (reads it to drive the field editor); it must NEVER MUTATE
// it. These endpoint shapes are the boundary: a PATCH to a content type, or any /schema or
// /migrations sub-resource. If this test fails, a Phase-1 file is reaching past the boundary —
// fix the file, not the test.
const FORBIDDEN: RegExp[] = [
  /\/content-types\/[^'"`]*\/schema/,
  /\/content-types\/[^'"`]*\/migrations/,
  /\.(PATCH|PUT)\(\s*['"`]\/content-types/,
]

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

describe('schema-boundary (Phase 1 never mutates content-type schema)', () => {
  it('no source file calls the schema-mutation endpoints', () => {
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
