<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { listFlows, runFlow } from '../api/catalogue.js'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'

const flows = ref([])
const loading = ref(true)
const error = ref(null)
const args = ref({})
const router = useRouter()

async function load() {
    try {
        flows.value = (await listFlows()).data
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
}

async function start(slug) {
    error.value = null

    try {
        const raw = (args.value[slug] ?? '').trim()
        const response = await runFlow(slug, { arguments: raw ? JSON.parse(raw) : [] })

        router.push(`/runs/${response.data.id}`)
    } catch (e) {
        error.value = e
    }
}

onMounted(load)
</script>

<template>
    <h2>Flows</h2>
    <p class="muted" style="margin-top: -6px">
        Code is the source of truth for which flows exist. A disabled flow is
        one whose override row says so.
    </p>

    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else>
        <p v-if="!flows.length" class="empty">No flows registered.</p>

        <table v-else>
            <thead>
                <tr><th>Slug</th><th>Class</th><th>Schedule</th><th>Enabled</th><th>Run</th></tr>
            </thead>
            <tbody>
                <tr v-for="flow in flows" :key="flow.slug">
                    <td class="mono">{{ flow.slug }}</td>
                    <td class="mono muted">{{ flow.class }}</td>
                    <td class="mono">{{ flow.schedule ?? '—' }}</td>
                    <td>
                        <span class="chip" :class="flow.enabled ? 'chip-completed' : 'chip-cancelled'">
                            {{ flow.enabled ? 'enabled' : 'disabled' }}
                        </span>
                    </td>
                    <td>
                        <div class="filters" style="margin: 0">
                            <input
                                v-model="args[flow.slug]"
                                placeholder='Arguments JSON, e.g. ["drill bits", 50]'
                                style="min-width: 260px"
                            />
                            <button class="primary" :disabled="!flow.enabled" @click="start(flow.slug)">Run</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </template>
</template>
