import { fileURLToPath } from 'node:url'
import { mergeConfig, defineConfig, configDefaults } from 'vitest/config'
import viteConfig from './vite.config'

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/__tests__/setup.ts'],
      exclude: [...configDefaults.exclude, 'e2e/**'],
      root: fileURLToPath(new URL('./', import.meta.url)),
      // Reset mock + global-stub state between test cases so a persistent `mockResolvedValue`
      // (or a leftover `vi.stubGlobal('fetch', ...)`) in one test can't bleed into the next.
      clearMocks: true,
      unstubGlobals: true,
    },
  }),
)
