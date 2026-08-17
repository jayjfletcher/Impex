import { get, post } from './client.js'

export const listFlows = () => get('/flows')
export const runFlow = (flow, body) => post(`/flows/${flow}/runs`, body)
export const listChannels = () => get('/channels')
export const listMessages = (filters) => get('/messages', filters)
export const showMessage = (id) => get(`/messages/${id}`)
