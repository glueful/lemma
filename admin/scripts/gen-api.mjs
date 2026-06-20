// Generates the typed admin API schema (src/api/schema.d.ts) from the OpenAPI spec.
//
// The spec lists admin endpoints under the /v1/admin prefix, but the typed client uses apiBase
// (/v1/admin) as its baseUrl. So we strip that prefix here, making the schema paths RELATIVE to
// apiBase — queries read clean: client.GET('/content-types'), not '/v1/admin/content-types'.
//
// Non-admin paths (auth, rbac, i18n, …) are dropped: the typed client only ever calls admin
// endpoints (auth uses raw fetch in the session store), so they'd just be dead, mis-based types.
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import openapiTS, { astToString } from 'openapi-typescript'

const PREFIX = '/v1/admin'
const specPath = resolve(process.cwd(), '../docs/openapi.json')
const outPath = resolve(process.cwd(), 'src/api/schema.d.ts')

const spec = JSON.parse(readFileSync(specPath, 'utf8'))

const paths = {}
for (const [key, value] of Object.entries(spec.paths ?? {})) {
  if (!key.startsWith(PREFIX)) continue
  paths[key.slice(PREFIX.length) || '/'] = value
}
spec.paths = paths

const ast = await openapiTS(spec)
writeFileSync(outPath, astToString(ast))
console.log(`Generated ${outPath} — ${Object.keys(paths).length} admin paths (${PREFIX} stripped)`)
