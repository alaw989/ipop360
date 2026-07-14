<script setup lang="ts">
defineProps<{
    icon?: string;
    title?: string;
    message?: string;
    actionLabel?: string;
    actionHref?: string;
}>();
</script>

<template>
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <span v-if="icon" class="mb-4 text-5xl">{{ icon }}</span>
        <h3 v-if="title" class="mb-2 text-lg font-semibold text-foreground">{{ title }}</h3>
        <p v-if="message" class="mb-6 max-w-md text-sm text-muted-foreground">{{ message }}</p>
        <a
            v-if="actionLabel && actionHref"
            :href="actionHref"
            class="inline-flex items-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
        >
            {{ actionLabel }}
        </a>
    </div>
</template>
