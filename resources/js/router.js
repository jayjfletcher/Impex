import { createRouter, createWebHistory } from 'vue-router'
import { basePath } from './config.js'

import RunsIndex from './views/RunsIndex.vue'
import RunShow from './views/RunShow.vue'
import MessagesIndex from './views/MessagesIndex.vue'
import MessageShow from './views/MessageShow.vue'
import FlowsIndex from './views/FlowsIndex.vue'
import ChannelsIndex from './views/ChannelsIndex.vue'

export default createRouter({
    history: createWebHistory(basePath),
    routes: [
        { path: '/', redirect: '/runs' },
        { path: '/runs', name: 'runs', component: RunsIndex },
        { path: '/runs/:id', name: 'run', component: RunShow, props: true },
        { path: '/messages', name: 'messages', component: MessagesIndex },
        { path: '/messages/:id', name: 'message', component: MessageShow, props: true },
        { path: '/flows', name: 'flows', component: FlowsIndex },
        { path: '/channels', name: 'channels', component: ChannelsIndex },
    ],
})
