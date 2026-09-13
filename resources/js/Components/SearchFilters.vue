<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
    update: [changes: Record<string, string | string[] | undefined>];
    clear: [];
}>();

const PRICE_WORDS: Record<string, string> = { '$': 'Inexpensive', '$$': 'Moderate', '$$$': 'Pricey', '$$$$': 'High-end' };

// One level (old links) or several; always a list here.
function priceList(value: string | string[] | undefined): string[] {
    if (Array.isArray(value)) return value;
    return typeof value === 'string' && value !== '' ? [value] : [];
}

// Updated the moment a level is tapped, not when the search returns: a second
// tap during a slow search must build on the first, or Inertia cancels the
// first visit and that level is lost.
const currentPrices = ref<string[]>(priceList(props.filters["price_range"]));
watch(() => props.filters["price_range"], (value) => {
    currentPrices.value = priceList(value);
});
const currentDistance = computed(() => props.filters["distance"] as string || '25');
const currentCuisine = computed(() => props.filters["cuisine"] as string || '');
const currentCategory = computed(() => props.filters["category"] as string || '');

const hasActiveFilters = computed(() => {
    return !!(currentPrices.value.length || currentDistance.value !== '25' || currentCuisine.value || currentCategory.value);
});

// Several levels can be on at once ($ and $$), as on Yelp.
function togglePrice(price: string) {
    const on = currentPrices.value.includes(price)
        ? currentPrices.value.filter((p) => p !== price)
        : [...currentPrices.value, price];
    const ordered = props.filterOptions.priceOptions.filter((p) => on.includes(p));
    currentPrices.value = ordered;
    emit('update', { price_range: ordered.length ? ordered : undefined });
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

        <!-- Price: one joined control, several levels at once -->
        <div>
            <h3 id="price-filter-label" class="mb-2 text-sm font-medium">Price</h3>
            <div
                role="group"
                aria-labelledby="price-filter-label"
                class="grid overflow-hidden rounded-lg border border-input"
                :style="{ gridTemplateColumns: `repeat(${filterOptions.priceOptions.length}, minmax(0, 1fr))` }"
                data-testid="price-filter"
            >
                <button
                    v-for="(price, i) in filterOptions.priceOptions"
                    :key="price"
                    type="button"
                    :aria-pressed="currentPrices.includes(price)"
                    :aria-label="`${price}, ${(PRICE_WORDS[price] ?? price).toLowerCase()}`"
                    class="flex min-h-12 flex-col items-center justify-center px-1 py-1.5 transition-colors focus-visible:relative focus-visible:z-10"
                    :class="[
                        i > 0 ? 'border-l border-input' : '',
                        currentPrices.includes(price)
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-background text-foreground hover:bg-accent',
                    ]"
                    @click="togglePrice(price)"
                >
                    <span class="text-sm font-semibold">{{ price }}</span>
                    <span
                        v-if="PRICE_WORDS[price]"
                        class="text-[11px] leading-tight"
                        :class="currentPrices.includes(price) ? 'text-primary-foreground/90' : 'text-muted-foreground'"
                    >{{ PRICE_WORDS[price] }}</span>
                </button>
            </div>
            <p v-if="currentPrices.length" class="mt-2 text-xs text-muted-foreground" data-testid="price-hint">
                Only restaurants with a listed price are shown.
            </p>
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
