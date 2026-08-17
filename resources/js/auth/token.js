import { auth } from '../config.js'

// A bearer token minted server-side by the configured UiTokenResolver, for
// applications whose API sits behind token auth rather than session cookies.
// The token is embedded in the page, so it should be short-lived.
export default {
    headers() {
        return auth.token ? { Authorization: `Bearer ${auth.token}` } : {}
    },
    credentials: 'omit',
}
