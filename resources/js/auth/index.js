import { auth } from '../config.js'
import oauth from './oauth.js'
import session from './session.js'
import token from './token.js'

/**
 * Auth driver contract:
 *
 *   headers(refresh)  -> request headers, may be async (required)
 *   credentials       -> fetch credentials mode (optional, defaults same-origin)
 *   boot()            -> async setup before the app mounts (optional)
 *   retriesOn401()    -> whether a 401 warrants one retry with refresh (optional)
 *
 * A host page bridges its own scheme by setting the mode to 'custom' and
 * defining window.ImpexAuth with this shape before the dashboard script loads.
 */
const drivers = { session, token, oauth }

export function authDriver() {
    if (auth.mode === 'custom' && typeof window.ImpexAuth === 'object' && window.ImpexAuth !== null) {
        return window.ImpexAuth
    }

    return drivers[auth.mode] ?? drivers.session
}

export async function bootAuth() {
    await authDriver().boot?.()
}
