<script setup>
defineProps({
    label: { type: String, default: 'Payload' },
    value: { type: null, default: null },
    artifactId: { type: String, default: null },
})
</script>

<template>
    <div v-if="value !== null || artifactId">
        <h3>{{ label }}</h3>
        <!-- Large payloads are never inlined by the API; the dashboard shows
             the artifact reference rather than pretending to have the body. -->
        <p v-if="artifactId" class="muted mono">
            Stored as artifact <strong>{{ artifactId }}</strong> — too large to inline.
        </p>
        <pre v-else>{{ JSON.stringify(value, null, 2) }}</pre>
    </div>
</template>
