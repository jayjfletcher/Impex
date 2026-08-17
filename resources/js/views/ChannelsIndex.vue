<script setup>
import { onMounted, ref } from 'vue'
import { listChannels } from '../api/catalogue.js'
import ErrorBox from '../components/ErrorBox.vue'
import Loading from '../components/Loading.vue'

const channels = ref([])
const loading = ref(true)
const error = ref(null)

onMounted(async () => {
    try {
        channels.value = (await listChannels()).data
    } catch (e) {
        error.value = e
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <h2>Channels</h2>
    <p class="muted" style="margin-top: -6px">
        Inbound endpoints and the flow each one starts. Signing secrets are never
        returned by the API.
    </p>

    <ErrorBox :error="error" />
    <Loading v-if="loading" />

    <template v-else>
        <p v-if="!channels.length" class="empty">No channels configured.</p>

        <table v-else>
            <thead>
                <tr><th>Name</th><th>Path</th><th>Flow</th><th>Signature</th></tr>
            </thead>
            <tbody>
                <tr v-for="channel in channels" :key="channel.name">
                    <td class="mono">{{ channel.name }}</td>
                    <td class="mono muted">{{ channel.path ?? `channels/${channel.name}` }}</td>
                    <td class="mono">{{ channel.flow ?? '—' }}</td>
                    <td>
                        <!-- An unsigned channel accepts anything that can reach
                             the URL, which is worth flagging loudly. -->
                        <span class="chip" :class="channel.verifies_signatures ? 'chip-completed' : 'chip-failed'">
                            {{ channel.verifies_signatures ? 'verified' : 'unsigned' }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </template>
</template>
