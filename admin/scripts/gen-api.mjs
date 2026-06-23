// Generates two typed API schemas from the OpenAPI spec (docs/openapi.json):
//
//   src/api/schema.d.ts       — the ADMIN surface: paths under /v1/admin, with that prefix STRIPPED
//                               so the admin client (baseUrl /v1/admin) reads clean:
//                               client.GET('/content-types').
//
//   src/api/core-schema.d.ts  — EVERYTHING ELSE (auth, account, 2FA, me, users, blobs, rbac, i18n,
//                               …): paths kept at their FULL spec value so the core client
//                               (baseUrl '') reads exactly the spec: core.POST('/v1/auth/login').
//
// Splitting by /v1/admin means no path is hand-written in the app — both surfaces are spec-typed,
// so a backend prefix change can never silently drift the client.
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import openapiTS, { astToString } from 'openapi-typescript'

const PREFIX = '/v1/admin'
const specPath = resolve(process.cwd(), '../docs/openapi.json')
const spec = JSON.parse(readFileSync(specPath, 'utf8'))
const entries = Object.entries(spec.paths ?? {})

async function emit(outFile, paths, label) {
  const out = resolve(process.cwd(), outFile)
  const ast = await openapiTS({ ...spec, paths })
  writeFileSync(out, astToString(ast))
  console.log(`Generated ${out} — ${Object.keys(paths).length} ${label}`)
}

// Admin: keep /v1/admin, strip the prefix so paths are relative to apiBase.
const adminPaths = Object.fromEntries(
  entries
    .filter(([key]) => key.startsWith(PREFIX))
    .map(([key, value]) => [key.slice(PREFIX.length) || '/', value]),
)
await emit('src/api/schema.d.ts', adminPaths, `admin paths (${PREFIX} stripped)`)

// Core: everything else, kept at full spec paths.
const corePaths = Object.fromEntries(entries.filter(([key]) => !key.startsWith(PREFIX)))
await emit('src/api/core-schema.d.ts', corePaths, 'core paths (full)')
