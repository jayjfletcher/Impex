import { apiBase } from '../config.js'
import { authDriver } from '../auth/index.js'

export class ApiError extends Error {
    constructor(message, status, errors) {
        super(message)
        this.status = status
        this.errors = errors ?? {}
    }
}

async function request(method, path, { query, body } = {}) {
    const url = new URL(apiBase + path, window.location.origin)

    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') return

        if (typeof value === 'object') {
            Object.entries(value).forEach(([k, v]) => {
                if (v !== '' && v !== null) url.searchParams.set(`${key}[${k}]`, v)
            })
            return
        }

        url.searchParams.set(key, value)
    })

    const driver = authDriver()

    const attempt = async (refresh) => fetch(url, {
        method,
        credentials: driver.credentials ?? 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(await driver.headers(refresh)),
        },
        body: body ? JSON.stringify(body) : undefined,
    })

    let response = await attempt(false)

    // One retry with refreshed credentials, for drivers that can renew — an
    // access token that expired between two clicks should not surface as an
    // error the operator has to reason about.
    if (response.status === 401 && (driver.retriesOn401?.() ?? false)) {
        response = await attempt(true)
    }

    if (response.status === 204) return null

    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new ApiError(
            payload.message ?? `Request failed with status ${response.status}`,
            response.status,
            payload.errors,
        )
    }

    return payload
}

export const get = (path, query) => request('GET', path, { query })
export const post = (path, body) => request('POST', path, { body })
export const del = (path) => request('DELETE', path)
