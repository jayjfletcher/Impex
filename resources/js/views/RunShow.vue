<script setup>
import { onMounted, ref } from 'vue'
import { cancelRun, listOwners, listRunMessages, listSteps, retryRun, showRun, signalRun } from '../api/runs.js'
import Chip from '../components/Chip.vue'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'
import TimeAgo from '../components/TimeAgo.vue'

const props = defineProps({ id: { type: String, required: true } })

const run = ref(null)
const steps = ref([])
const owners = ref([])
const messages = ref([])
const loading = ref(true)
const error = ref(null)
const signal = ref({ name: '', payload: '' })

async function load() {
    loading.value = true
    error.value = null

    try {
        const [runResponse, stepResponse, ownerResponse, messageResponse] = await Promise.all([
            showRun(props.id),
            listSteps(props.id),
            listOwners(props.id),
            listRunMessages(props.id),
        ])

        run.value = runResponse.data
        steps.value = stepResponse.data
        owners.value = ownerResponse.data
        messages.value = messageResponse.data
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
}

async function act(fn) {
    error.value = null

    try {
        await fn()
        await load()
    } catch (e) {
        error.value = e
    }
}

const cancel = () => act(() => cancelRun(props.id, 'Cancelled from the dashboard'))
const retry = () => act(() => retryRun(props.id))

const send = () => act(async () => {
    await signalRun(props.id, {
        name: signal.value.name,
        payload: signal.value.payload ? JSON.parse(signal.value.payload) : null,
    })
    signal.value = { name: '', payload: '' }
})

onMounted(load)
</script>

<template>
    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else-if="run">
        <h2>{{ run.flow }} <Chip :value="run.status" /></h2>

        <div class="panel">
            <dl class="kv">
                <dt>Run</dt><dd class="mono">{{ run.id }}</dd>
                <dt>Trigger</dt><dd>{{ run.trigger }}</dd>
                <dt v-if="run.idempotency_key">Idempotency key</dt>
                <dd v-if="run.idempotency_key" class="mono">{{ run.idempotency_key }}</dd>
                <dt>Started</dt><dd><TimeAgo :value="run.started_at" /></dd>
                <dt>Finished</dt><dd><TimeAgo :value="run.finished_at" /></dd>
                <dt v-if="run.parent_run_id">Parent</dt>
                <dd v-if="run.parent_run_id"><RouterLink :to="`/runs/${run.parent_run_id}`">{{ run.parent_run_id }}</RouterLink></dd>
            </dl>
        </div>

        <div v-if="run.error" class="error">
            <strong>{{ run.error.class ?? 'Failed' }}</strong>
            <p style="margin: 4px 0 0">{{ run.error.message }}</p>
        </div>

        <div class="actions">
            <button :disabled="['completed', 'failed', 'cancelled'].includes(run.status)" @click="cancel">Cancel</button>
            <button @click="retry">Retry</button>
        </div>

        <template v-if="run.status === 'waiting'">
            <h3>Deliver a signal</h3>
            <div class="filters">
                <input v-model="signal.name" placeholder="Signal name" />
                <input v-model="signal.payload" placeholder='Payload JSON, e.g. {"approved":true}' style="min-width: 320px" />
                <button class="primary" :disabled="!signal.name" @click="send">Send</button>
            </div>
        </template>

        <h3>Steps</h3>
        <ul class="timeline">
            <li
                v-for="step in steps"
                :key="step.id"
                :class="[`is-${step.status}`, step.phase === 'compensation' ? 'is-compensation' : '']"
            >
                <div class="step-head">
                    <span class="step-name">{{ step.name }}</span>
                    <Chip :value="step.status" />
                    <span v-if="step.phase === 'compensation'" class="chip chip-waiting">rollback</span>
                </div>
                <div class="step-meta">
                    #{{ step.sequence }} · {{ step.type }}
                    <template v-if="step.attempts > 1"> · {{ step.attempts }} attempts</template>
                    <!-- Resumptions are how you spot a step that spanned many
                         invocations rather than one slow one. -->
                    <template v-if="step.resumptions"> · resumed {{ step.resumptions }}×</template>
                    <template v-if="step.compensated"> · compensated</template>
                    <template v-if="step.result_artifact_id"> · result on artifact disk</template>
                </div>
                <div v-if="step.error" class="step-meta" style="color: var(--bad)">
                    {{ step.error.message }}
                </div>
            </li>
        </ul>

        <h3>Owners</h3>
        <p v-if="!owners.length" class="muted">No owners attached.</p>
        <table v-else>
            <thead><tr><th>Role</th><th>Type</th><th>Id</th></tr></thead>
            <tbody>
                <tr v-for="owner in owners" :key="owner.id">
                    <td>{{ owner.role }}</td>
                    <td class="mono">{{ owner.owner_type }}</td>
                    <td class="mono">{{ owner.owner_id }}</td>
                </tr>
            </tbody>
        </table>

        <h3>Messages</h3>
        <p v-if="!messages.length" class="muted">Nothing crossed the boundary for this run.</p>
        <table v-else>
            <thead><tr><th>Direction</th><th>Channel</th><th>Endpoint</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
                <tr v-for="message in messages" :key="message.id">
                    <td><Chip :value="message.direction" /></td>
                    <td>{{ message.channel }}</td>
                    <td class="mono"><RouterLink :to="`/messages/${message.id}`">{{ message.endpoint }}</RouterLink></td>
                    <td class="num">{{ message.status_code ?? '—' }}</td>
                    <td class="muted"><TimeAgo :value="message.occurred_at" /></td>
                </tr>
            </tbody>
        </table>
    </template>
</template>
