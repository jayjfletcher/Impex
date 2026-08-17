<script setup>
import { RouterLink, RouterView } from 'vue-router'
</script>

<template>
    <div class="impex-shell">
        <nav class="impex-nav">
            <h1>Impex</h1>
            <RouterLink to="/runs">Runs</RouterLink>
            <RouterLink to="/messages">Messages</RouterLink>
            <RouterLink to="/flows">Flows</RouterLink>
            <RouterLink to="/channels">Channels</RouterLink>
        </nav>
        <RouterView />
    </div>
</template>
