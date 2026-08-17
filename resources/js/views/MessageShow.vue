<script setup>
import { onMounted, ref } from 'vue'
import { showMessage } from '../api/catalogue.js'
import Chip from '../components/Chip.vue'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'

const props = defineProps({ id: { type: String, required: true } })

const message = ref(null)
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
    try {
        message.value = (await showMessage(props.id)).data
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else-if="message">
        <h2><Chip :value="message.direction" /> {{ message.channel }}</h2>

        <div class="panel">
            <dl class="kv">
                <dt>Endpoint</dt><dd class="mono">{{ message.endpoint }}</dd>
                <dt>Transport</dt><dd>{{ message.transport }}<template v-if="message.method"> · {{ message.method }}</template></dd>
                <dt v-if="message.status_code">Status</dt><dd v-if="message.status_code">{{ message.status_code }}</dd>
                <dt v-if="message.duration_ms !== null">Duration</dt><dd v-if="message.duration_ms !== null">{{ message.duration_ms }}ms</dd>
                <dt v-if="message.signature_valid !== null">Signature</dt>
                <dd v-if="message.signature_valid !== null">
                    <Chip :value="message.signature_valid ? 'completed' : 'failed'" />
                </dd>
                <dt>Bytes</dt><dd>{{ message.bytes }}</dd>
                <dt v-if="message.run_id">Run</dt>
                <dd v-if="message.run_id"><RouterLink :to="`/runs/${message.run_id}`">{{ message.run_id }}</RouterLink></dd>
            </dl>
        </div>

        <template v-if="message.headers">
            <h3>Headers</h3>
            <p class="muted">Only the headers the channel was configured to store.</p>
            <pre>{{ JSON.stringify(message.headers, null, 2) }}</pre>
        </template>

        <h3>Body</h3>
        <p v-if="message.body_artifact_id" class="muted mono">
            Full body stored as artifact <strong>{{ message.body_artifact_id }}</strong>.
        </p>
        <pre>{{ message.body_preview ?? '(empty)' }}</pre>
    </template>
</template>
