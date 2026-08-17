<script setup>
import { computed } from 'vue'

const props = defineProps({ value: { type: String, default: null } })

const formatted = computed(() => {
    if (!props.value) return '—'

    const date = new Date(props.value)
    const seconds = Math.round((Date.now() - date.getTime()) / 1000)

    if (seconds < 60) return `${seconds}s ago`
    if (seconds < 3600) return `${Math.round(seconds / 60)}m ago`
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`

    return date.toLocaleDateString()
})

const title = computed(() => (props.value ? new Date(props.value).toLocaleString() : ''))
</script>

<template>
    <span :title="title">{{ formatted }}</span>
</template>
