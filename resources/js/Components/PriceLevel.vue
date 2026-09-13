<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    price: string | null | undefined;
    // 'inverse' for white text over a darkened photo.
    tone?: 'default' | 'inverse';
}>();

const LEVEL_WORDS = ['Inexpensive', 'Moderate', 'Pricey', 'High-end'] as const;

// A stored price is one to four of the same currency sign ("$$", "€€€").
// Shown as all four signs with the restaurant's level dark and the rest
// light, so "$$" reads as 2 of 4 at a glance. Anything else is shown as
// stored.
const level = computed(() => {
    const value = (props.price ?? '').trim();
    const match = /^([$€£¥₩])\1{0,3}$/u.exec(value);
    if (!match) return null;

    const symbol = match[1] as string;
    const count = Array.from(value).length;

    return { symbol, count, word: LEVEL_WORDS[count - 1] as string };
});
</script>

<template>
    <span
        v-if="level"
        class="inline-flex font-semibold tracking-[0.02em]"
        role="img"
        :aria-label="`Price: ${level.word.toLowerCase()}`"
        :title="level.word"
        data-testid="price-level"
    >
        <span :class="tone === 'inverse' ? 'text-white' : 'text-foreground'" aria-hidden="true">{{ level.symbol.repeat(level.count) }}</span>
        <span :class="tone === 'inverse' ? 'text-white/40' : 'text-muted-foreground/40'" aria-hidden="true">{{ level.symbol.repeat(4 - level.count) }}</span>
    </span>
    <span v-else-if="price" class="text-muted-foreground">{{ price }}</span>
</template>
