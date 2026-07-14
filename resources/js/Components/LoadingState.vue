<script setup lang="ts">
import Skeleton from '@/components/ui/skeleton/Skeleton.vue';

withDefaults(defineProps<{
    count?: number;
    columns?: number;
    height?: string;
}>(), {
    count: 6,
    columns: 3,
    height: 'h-48',
});
</script>

<template>
    <div
        class="grid gap-4"
        :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }"
    >
        <div v-for="i in count" :key="'skeleton-' + i" class="space-y-3">
            <Skeleton :class="height" class="w-full rounded-xl" />
            <Skeleton class="h-4 w-3/4" />
            <Skeleton class="h-3 w-1/2" />
        </div>
    </div>
</template>
