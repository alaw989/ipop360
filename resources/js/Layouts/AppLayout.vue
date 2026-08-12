<script setup lang="ts">
import TopNav from '@/Components/TopNav.vue';
import AppFooter from '@/Components/AppFooter.vue';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <TopNav />

        <main class="flex-1">
            <slot />
        </main>

        <AppFooter />

    </div>
</template>