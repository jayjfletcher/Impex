import { createApp } from 'vue'
import App from './App.vue'
import { bootAuth } from './auth/index.js'
import router from './router.js'
import '../css/app.css'

// Boot the auth driver before mounting: the OAuth driver completes its
// authorization-code exchange here, so the router never sees the code and
// state parameters in the URL.
bootAuth()
    .catch(() => {})
    .finally(() => createApp(App).use(router).mount('#impex'))
