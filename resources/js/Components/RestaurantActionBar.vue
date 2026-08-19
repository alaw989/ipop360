<script setup lang="ts">
import { computed } from 'vue';
import { Phone, Navigation, Globe } from '@lucide/vue';
import { callPhone, openWebsite, trackDirections, directionsUrl } from '@/lib/restaurant';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurant: Restaurant;
}>();

const hasPhone = computed(() => !!props.restaurant.phone);
const hasDirections = computed(() => props.restaurant.lat != null && props.restaurant.lng != null);
const hasWebsite = computed(() => !!props.restaurant.website_url);

const showBar = computed(() => hasPhone.value || hasDirections.value || hasWebsite.value);
</script>

<template>
    <div
        v-if="showBar"
        data-testid="restaurant-action-bar"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-sm pb-[env(safe-area-inset-bottom)] md:hidden"
    >
        <div class="mx-auto flex max-w-7xl items-stretch divide-x divide-border">
            <a
                v-if="hasDirections"
                :href="directionsUrl(restaurant.lat!, restaurant.lng!)"
                target="_blank"
                rel="noopener"
                class="flex flex-1 flex-col items-center gap-1 py-2.5 text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                @click="trackDirections(restaurant.id)"
            >
                <Navigation :size="18" />
                Directions
            </a>
            <button
                v-if="hasPhone"
                class="flex flex-1 flex-col items-center gap-1 py-2.5 text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                @click="() => callPhone(restaurant.phone!, restaurant.id)"
            >
                <Phone :size="18" />
                Call
            </button>
            <button
                v-if="hasWebsite"
                class="flex flex-1 flex-col items-center gap-1 py-2.5 text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                @click="() => openWebsite(restaurant.website_url!, restaurant.id)"
            >
                <Globe :size="18" />
                Website
            </button>
        </div>
    </div>
</template>
