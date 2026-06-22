import './assets/css/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import piniaPluginPersist from './plugins/pinia-persist-plugin'
import ui from '@nuxt/ui/vue-plugin'
import App from './App.vue'
import router from './router'
import { loadRuntimeConfig } from './runtime/config'

async function bootstrap() {
  // Runtime config MUST resolve before mount: the API client's baseUrl and the router guard's
  // `installed` check both read it synchronously after boot. A failure here is fatal — the SPA
  // cannot find its API without it (it is served by the PHP app at /admin, which exposes
  // /admin/config).
  await loadRuntimeConfig()

  const app = createApp(App)

  const pinia = createPinia()
  pinia.use(piniaPluginPersist)
  app.use(pinia)
  app.use(PiniaColada, {
    // Sensible server-state defaults; per-query overrides where needed.
    queryOptions: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
    },
  })
  app.use(router)
  app.use(ui)
  app.mount('#app')
}

void bootstrap()
