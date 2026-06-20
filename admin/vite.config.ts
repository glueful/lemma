import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import VueRouter from "vue-router/vite";
import Layouts from "vite-plugin-vue-layouts-next";
import ui from "@nuxt/ui/vite";

// https://vite.dev/config/
export default defineConfig({
  plugins: [
     VueRouter({
      exclude: ['src/pages/**/components/**'],
    }),
    vue(),
    vueDevTools(),
    Layouts(),
    ui({
      ui: {
        button: {
          slots:{
            base: 'cursor-pointer'
          }
        }
      }
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
