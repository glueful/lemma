import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import VueRouter from 'vue-router/vite'
import Layouts from 'vite-plugin-vue-layouts-next'
import ui from '@nuxt/ui/vite'

// https://vite.dev/config/
export default defineConfig({
  // The admin SPA is served by the PHP app at /admin (framework serveFrontend() seam), so assets
  // must resolve under /admin/ and deep-link routing uses the HTML5 history fallback there.
  base: '/admin/',
  build: {
    // Compiled bundle ships as public/admin/ (baked into release tags; gitignored in dev).
    outDir: fileURLToPath(new URL('../public/admin', import.meta.url)),
    emptyOutDir: true,
  },
  plugins: [
    VueRouter({
      exclude: ['src/pages/**/components/**'],
    }),
    vue(),
    vueDevTools(),
    Layouts(),
    ui({
      colorMode: false, // Disable color mode support
      ui: {
        colors: {
          primary: 'blue',
        },
        button: {
          slots: {
            base: 'cursor-pointer',
          },
        },
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
