// Handed to the page by UiController, so the dashboard never guesses where the
// API lives or how it should authenticate.
const config = window.ImpexConfig ?? {}

export const apiBase = (config.apiBase ?? '/impex').replace(/\/$/, '')
export const basePath = config.basePath ?? '/impex/ui'
export const auth = config.auth ?? { mode: 'session', token: null, oauth: null }
export const csrfToken = config.csrfToken ?? null
