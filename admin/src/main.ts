import "./assets/css/main.css";

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPluginPersist from "./plugins/pinia-persist-plugin";
import ui from "@nuxt/ui/vue-plugin";
import App from './App.vue'
import router from './router'

const app = createApp(App)

const pinia = createPinia();
pinia.use(piniaPluginPersist);
app.use(pinia);

app.use(router)
app.use(ui);
app.mount('#app')
