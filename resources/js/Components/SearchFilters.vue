<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    filters: Record<string, string | string[] | undefined>;
    filterOptions: {
        categories: Array<{ id: number; name: string; slug: string; restaurants_count: number }>;
        priceOptions: string[];
        distanceOptions: number[];
    };
}>();

const emit = defineEmits<{
    update: [changes: Record<string, string | undefined>];
    clear: [];
}>();

const currentPrice = computed(() => props.filters["price_range"] as string || '');
const currentDistance = computed(() => props.filters["distance"] as string || '25');
const currentCuisine = computed(() => props.filters["cuisine"] as string || '');
const currentCategory = computed(() => props.filters["category"] as string || '');

const hasActiveFilters = computed(() => {
    return !!(currentPrice.value || currentDistance.value !== '25' || currentCuisine.value || currentCategory.value);
});

function togglePrice(price: string) {
    if (currentPrice.value === price) {
        emit('update', { price_range: undefined });
    } else {
        emit('update', { price_range: price });
    }
}

function setDistance(mi: number) {
    emit('update', { distance: String(mi) });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Filters</h2>
            <Button
                v-if="hasActiveFilters"
                variant="ghost"
                size="sm"
                class="h-auto p-0 text-xs text-muted-foreground hover:text-foreground"
                @click="emit('clear')"
            >
                Clear all
            </Button>
        </div>

        <!-- Price range -->
        <div>
            <h3 class="mb-2 text-sm font-medium">Price</h3>
            <div class="flex gap-1">
                <button
                    v-for="price in filterOptions.priceOptions"
                    :key="price"
                    class="flex h-9 w-9 items-center justify-center rounded-lg border text-sm font-semibold transition-colors"
                    :class="currentPrice === price
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-input bg-background text-muted-foreground hover:border-muted-foreground'"
                    @click="togglePrice(price)"
                >
                    {{ price }}
                </button>
            </div>
        </div>

        <!-- Categories -->
        <div>
            <h3 class="mb-2 text-sm font-medium">Category</h3>
            <div class="space-y-1">
                <Link
                    v-for="cat in filterOptions.categories"
                    :key="cat.id"
                    :href="`/search?category=${cat.slug}`"
                    class="flex items-center justify-between rounded-lg px-3 py-2 text-sm transition-colors hover:bg-muted"
                    :class="{ 'bg-primary/10 font-medium text-primary': currentCategory === cat.slug }"
                >
                    <span>{{ cat.name }}</span>
                    <span class="text-xs text-muted-foreground">{{ cat.restaurants_count }}</span>
                </Link>
            </div>
        </div>

        <!-- Distance -->
        <div>
            <h3 class="mb-2 text-sm font-medium">Distance</h3>
            <div class="space-y-1">
                <label
                    v-for="mi in filterOptions.distanceOptions"
                    :key="mi"
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-muted"
                    :class="{ 'bg-primary/10': currentDistance === String(mi) }"
                >
                    <input
                        type="radio"
                        name="distance"
                        :value="mi"
                        :checked="currentDistance === String(mi)"
                        class="text-primary"
                        @change="setDistance(mi)"
                    />
                    <span>{{ mi === 1 ? '1 mi' : mi >= 50 ? '50+ mi' : `${mi} mi` }}</span>
                </label>
                <label
                    class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-muted"
                    :class="{ 'bg-primary/10': currentDistance === '0' }"
                >
                    <input
                        type="radio"
                        name="distance"
                        value="0"
                        :checked="currentDistance === '0'"
                        class="text-primary"
                        @change="emit('update', { distance: undefined })"
                    />
                    <span>Auto</span>
                </label>
            </div>
        </div>
    </div>
</template>
