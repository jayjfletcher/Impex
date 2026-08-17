import { csrfToken } from '../config.js'

// Same-origin cookies plus the CSRF token. The default, and the right choice
// when the dashboard is mounted behind the application's own `web` middleware.
export default {
    headers() {
        return csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}
    },
    credentials: 'same-origin',
}
