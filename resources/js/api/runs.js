import { get, post } from './client.js'

export const listRuns = (filters) => get('/runs', filters)
export const showRun = (id) => get(`/runs/${id}`)
export const listSteps = (id, filters) => get(`/runs/${id}/steps`, filters)
export const listOwners = (id) => get(`/runs/${id}/owners`)
export const listRunMessages = (id) => get('/messages', { run: id })
export const cancelRun = (id, reason) => post(`/runs/${id}/cancel`, { reason })
export const retryRun = (id) => post(`/runs/${id}/retry`)
export const signalRun = (id, body) => post(`/runs/${id}/signals`, body)
