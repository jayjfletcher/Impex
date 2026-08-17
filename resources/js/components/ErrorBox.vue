<script setup>
defineProps({ error: { type: Object, default: null } })
</script>

<template>
    <div v-if="error" class="error">
        <strong>{{ error.message }}</strong>
        <ul v-if="Object.keys(error.errors ?? {}).length" style="margin: 6px 0 0 18px">
            <li v-for="(messages, field) in error.errors" :key="field">
                {{ field }}: {{ messages.join(' ') }}
            </li>
        </ul>
    </div>
</template>
