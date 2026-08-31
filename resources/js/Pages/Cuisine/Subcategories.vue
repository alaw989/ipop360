<script setup lang="ts">
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SubcategoryCard from '@/Components/SubcategoryCard.vue';
import SeoMeta from '@/Components/SeoMeta.vue';
import JsonLd from '@/Components/JsonLd.vue';
import { useSeo, generateItemListJsonLd } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';

const props = defineProps<{
    category: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        icon: string | null;
        cuisines: Array<{
            id: number;
            name: string;
            slug: string;
            description: string | null;
            icon: string | null;
        }>;
    };
    coords: {
        lat?: string;
        lng?: string;
    };
}>();

function selectCuisine(slug: string) {
    const data: Record<string, string> = { cuisine: slug };
    if (props.coords.lat && props.coords.lng) {
        data["lat"] = props.coords.lat;
        data["lng"] = props.coords.lng;
    }

    router.visit('/restaurants', { data });
}

// SEO
const baseUrl = useBaseUrl();

const seoData = computed(() => {
    const cuisineNames = props.category.cuisines.slice(0, 5).map(c => c.name).join(', ');
    const description = props.category.description
        || `Explore ${props.category.cuisines.length} ${props.category.name} cuisines on iPop360, including ${cuisineNames}. Find and compare top-rated restaurants near you.`;

    return useSeo({
        title: `${props.category.name} Cuisine`,
        description,
        url: `${baseUrl.value}${usePage().url}`,
        type: 'website',
    });
});

const structuredData = computed(() => generateItemListJsonLd(
    props.category.cuisines.map((cuisine, index) => ({
        name: cuisine.name,
        url: `${baseUrl.value}/restaurants?cuisine=${cuisine.slug}`,
        position: index + 1,
    })),
));
</script>

<template>
    <AppLayout>
        <SeoMeta :seoData="seoData" />
        <JsonLd :data="structuredData" />

        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="mb-8">
                <a
                    href="/"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    &larr; Back to categories
                </a>
                <div class="mt-4 flex items-center gap-3">
                    <span class="text-4xl">{{ category.icon }}</span>
                    <div>
                        <h1 class="text-3xl font-bold text-foreground">{{ category.name }} Cuisine</h1>
                        <p v-if="category.description" class="mt-1 text-muted-foreground">
                            {{ category.description }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                <SubcategoryCard
                    v-for="cuisine in category.cuisines"
                    :key="cuisine.id"
                    :cuisine="cuisine"
                    @select="selectCuisine"
                />
            </div>
        </div>
    </AppLayout>
</template>
