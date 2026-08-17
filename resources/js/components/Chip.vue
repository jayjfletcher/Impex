<script setup>
defineProps({ value: { type: String, required: true } })
</script>

<template>
    <!-- The label ships with the colour: colour alone is not accessible. -->
    <span class="chip" :class="`chip-${value}`">{{ value }}</span>
</template>
