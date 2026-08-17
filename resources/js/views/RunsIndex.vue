<script setup>
import { onMounted, reactive, ref } from 'vue'
import { listRuns } from '../api/runs.js'
import Chip from '../components/Chip.vue'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'
import TimeAgo from '../components/TimeAgo.vue'

const runs = ref([])
const loading = ref(true)
const error = ref(null)
const cursors = ref([])
const nextCursor = ref(null)

const filters = reactive({ status: '', flow: '', trigger: '', owner_type: '', owner_id: '' })

const statuses = ['pending', 'running', 'waiting', 'compensating', 'completed', 'failed', 'cancelled']

async function load(cursor = null) {
    loading.value = true
    error.value = null

    try {
        const response = await listRuns({ ...filters, cursor })
        runs.value = response.data
        nextCursor.value = response.meta?.next_cursor ?? null
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
}

function apply() {
    cursors.value = []
    load()
}

function next() {
    cursors.value.push(nextCursor.value)
    load(nextCursor.value)
}

onMounted(load)
</script>

<template>
    <h2>Runs</h2>

    <div class="filters">
        <select v-model="filters.status" @change="apply">
            <option value="">Any status</option>
            <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
        </select>
        <input v-model="filters.flow" placeholder="Flow slug" @keyup.enter="apply" />
        <input v-model="filters.owner_type" placeholder="Owner type" @keyup.enter="apply" />
        <input v-model="filters.owner_id" placeholder="Owner id" @keyup.enter="apply" />
        <button @click="apply">Filter</button>
    </div>

    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else>
        <p v-if="!runs.length" class="empty">No runs match these filters.</p>

        <table v-else>
            <thead>
                <tr>
                    <th>Flow</th>
                    <th>Status</th>
                    <th>Trigger</th>
                    <th>Tags</th>
                    <th>Started</th>
                    <th>Finished</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="run in runs" :key="run.id">
                    <td><RouterLink :to="`/runs/${run.id}`">{{ run.flow }}</RouterLink></td>
                    <td><Chip :value="run.status" /></td>
                    <td class="muted">{{ run.trigger }}</td>
                    <td class="mono muted">
                        <span v-for="(value, key) in run.tags ?? {}" :key="key">{{ key }}={{ value }} </span>
                    </td>
                    <td class="muted"><TimeAgo :value="run.started_at" /></td>
                    <td class="muted"><TimeAgo :value="run.finished_at" /></td>
                </tr>
            </tbody>
        </table>

        <div class="actions">
            <button :disabled="!nextCursor" @click="next">Next page</button>
        </div>
    </template>
</template>
