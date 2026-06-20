import { setupLayouts } from 'virtual:generated-layouts'
import { createRouter, createWebHistory } from 'vue-router'
import { routes, handleHotUpdate } from 'vue-router/auto-routes'
import { installAndAuthGuard } from './guard'

const router = createRouter({
  history: createWebHistory(),
  routes: setupLayouts(routes),
})

router.beforeEach(installAndAuthGuard)

export default router

if (import.meta.hot) {
  handleHotUpdate(router)
}
