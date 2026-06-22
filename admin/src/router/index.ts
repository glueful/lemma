import { setupLayouts } from 'virtual:generated-layouts'
import { createRouter, createWebHistory } from 'vue-router'
import { routes, handleHotUpdate } from 'vue-router/auto-routes'
import { installAndAuthGuard } from './guard'

const router = createRouter({
  // Match Vite's `base` (/admin/) so routes resolve under /admin/ in both dev and production (the
  // SPA is served at /admin via serveFrontend). Without this, navigation drops the prefix and a
  // hard refresh lands outside the dev server's base ("did you mean /admin/setup?").
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: setupLayouts(routes),
})

router.beforeEach(installAndAuthGuard)

export default router

if (import.meta.hot) {
  handleHotUpdate(router)
}
