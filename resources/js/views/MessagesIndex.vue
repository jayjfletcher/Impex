<script setup>
import { onMounted, reactive, ref } from 'vue'
import { listMessages } from '../api/catalogue.js'
import Chip from '../components/Chip.vue'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'
import TimeAgo from '../components/TimeAgo.vue'

const messages = ref([])
const loading = ref(true)
const error = ref(null)
const nextCursor = ref(null)

const filters = reactive({ direction: '', channel: '' })

async function load(cursor = null) {
    loading.value = true
    error.value = null

    try {
        const response = await listMessages({ ...filters, cursor })
        messages.value = response.data
        nextCursor.value = response.meta?.next_cursor ?? null
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<template>
    <h2>Messages</h2>
    <p class="muted" style="margin-top: -6px">
        Every payload that has crossed this application's boundary, in either direction.
    </p>

    <div class="filters">
        <select v-model="filters.direction" @change="load()">
            <option value="">Both directions</option>
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
        </select>
        <input v-model="filters.channel" placeholder="Channel" @keyup.enter="load()" />
        <button @click="load()">Filter</button>
    </div>

    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else>
        <p v-if="!messages.length" class="empty">Nothing recorded yet.</p>

        <table v-else>
            <thead>
                <tr>
                    <th></th>
                    <th>Channel</th>
                    <th>Endpoint</th>
                    <th>Status</th>
                    <th class="num">Bytes</th>
                    <th>Run</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="message in messages" :key="message.id">
                    <td><Chip :value="message.direction" /></td>
                    <td>{{ message.channel }}</td>
                    <td class="mono"><RouterLink :to="`/messages/${message.id}`">{{ message.endpoint }}</RouterLink></td>
                    <td class="num">
                        <span v-if="message.signature_valid === false" class="chip chip-failed">bad signature</span>
                        <template v-else>{{ message.status_code ?? '—' }}</template>
                    </td>
                    <td class="num muted">{{ message.bytes }}</td>
                    <td class="mono">
                        <RouterLink v-if="message.run_id" :to="`/runs/${message.run_id}`">run</RouterLink>
                        <span v-else class="muted">—</span>
                    </td>
                    <td class="muted"><TimeAgo :value="message.occurred_at" /></td>
                </tr>
            </tbody>
        </table>

        <div class="actions">
            <button :disabled="!nextCursor" @click="load(nextCursor)">Next page</button>
        </div>
    </template>
</template>
