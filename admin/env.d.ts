/// <reference types="vite/client" />
/// <reference types="vite-plugin-vue-layouts-next/client" />

interface ImportMetaEnv {
  // Build-time secret used to derive the AES-GCM key that encrypts the persisted session in
  // localStorage. Client-side encryption-at-rest is obfuscation, not secrecy.
  readonly VITE_ADMIN_PERSIST_SECRET?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
