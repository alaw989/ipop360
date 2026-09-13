<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

interface FoundRestaurant {
    id: number;
    name: string;
    city: string | null;
    state: string | null;
    google_rating: number | string | null;
    google_review_count: number;
}

export interface FeaturedAdminData {
    current: {
        restaurant: { id: number; name: string; city: string | null; state: string | null; slug: string };
        story: { id: number; title: string; slug: string } | null;
        image_url: string | null;
        image_credit: string | null;
        starts_at: string;
        ends_at: string | null;
    } | null;
    stories: Array<{ id: number; title: string }>;
}

// Pick the restaurant the home page spotlights, with an optional story and
// a credited photo. With no pick, the home page features the top-ranked
// restaurant near each visitor.
const props = defineProps<{ featured: FeaturedAdminData }>();

const query = ref('');
const results = ref<FoundRestaurant[]>([]);
const chosen = ref<FoundRestaurant | null>(null);
const searching = ref(false);
let debounce: ReturnType<typeof setTimeout> | null = null;

watch(query, (value) => {
    if (debounce) clearTimeout(debounce);
    if (value.trim().length < 2) {
        results.value = [];
        return;
    }
    debounce = setTimeout(async () => {
        searching.value = true;
        try {
            const res = await fetch(`/admin/featured-restaurant/search?q=${encodeURIComponent(value.trim())}`, {
                headers: { Accept: 'application/json' },
            });
            results.value = res.ok ? ((await res.json()) as FoundRestaurant[]) : [];
        } catch {
            results.value = [];
        } finally {
            searching.value = false;
        }
    }, 250);
});

onUnmounted(() => {
    if (debounce) clearTimeout(debounce);
});

const form = useForm({
    restaurant_id: null as number | null,
    blog_post_id: null as number | null,
    image_url: '',
    image_credit: '',
    ends_at: '',
});

function choose(restaurant: FoundRestaurant): void {
    chosen.value = restaurant;
    form.restaurant_id = restaurant.id;
    results.value = [];
    query.value = '';
}

function clearChoice(): void {
    chosen.value = null;
    form.restaurant_id = null;
}

function submit(): void {
    form
        .transform((data) => ({
            ...data,
            image_url: data.image_url || null,
            image_credit: data.image_credit || null,
            ends_at: data.ends_at || null,
        }))
        .post('/admin/featured-restaurant', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                chosen.value = null;
            },
        });
}

function stop(): void {
    router.delete('/admin/featured-restaurant', { preserveScroll: true });
}

function place(r: { city: string | null; state: string | null }): string {
    return [r.city, r.state].filter(Boolean).join(', ');
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

const inputClass = 'mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring';
</script>

<template>
    <Card data-testid="featured-picker">
        <CardHeader>
            <CardTitle class="text-base">Featured restaurant</CardTitle>
        </CardHeader>
        <CardContent class="space-y-5">
            <div
                v-if="props.featured.current"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted p-3 text-sm"
                data-testid="featured-current"
            >
                <p>
                    The home page features
                    <a :href="`/restaurants/${props.featured.current.restaurant.slug}`" class="font-semibold text-primary hover:underline">{{ props.featured.current.restaurant.name }}</a>
                    <template v-if="place(props.featured.current.restaurant)"> ({{ place(props.featured.current.restaurant) }})</template><template v-if="props.featured.current.story">, with the story “{{ props.featured.current.story.title }}”</template>, since {{ formatDate(props.featured.current.starts_at) }}<template v-if="props.featured.current.ends_at">, until {{ formatDate(props.featured.current.ends_at) }}</template>.
                </p>
                <Button type="button" variant="outline" size="sm" data-testid="featured-stop" @click="stop">Stop featuring</Button>
            </div>
            <p v-else class="text-sm text-muted-foreground" data-testid="featured-none">
                Nothing is picked. The home page features the top-ranked restaurant near each visitor.
            </p>

            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <div class="sm:col-span-2">
                    <label for="featured-search" class="text-sm font-medium">Restaurant</label>
                    <div v-if="chosen" class="mt-1 flex items-center gap-3 text-sm" data-testid="featured-chosen">
                        <span><strong>{{ chosen.name }}</strong> <span class="text-muted-foreground">{{ place(chosen) }}</span></span>
                        <button type="button" class="text-primary hover:underline" @click="clearChoice">Change</button>
                    </div>
                    <template v-else>
                        <input
                            id="featured-search"
                            v-model="query"
                            type="search"
                            autocomplete="off"
                            placeholder="Search by name…"
                            :class="inputClass"
                        />
                        <p v-if="searching" class="mt-1 text-xs text-muted-foreground">Searching…</p>
                        <ul v-if="results.length" class="mt-1 max-h-64 overflow-y-auto rounded-md border" data-testid="featured-results">
                            <li v-for="r in results" :key="r.id">
                                <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-accent" @click="choose(r)">
                                    <span class="font-medium">{{ r.name }}</span>
                                    <span class="text-muted-foreground">
                                        {{ place(r) }}<template v-if="Number(r.google_rating) > 0"> · {{ Number(r.google_rating).toFixed(1) }} ({{ r.google_review_count.toLocaleString() }})</template>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </template>
                    <p v-if="form.errors.restaurant_id" class="mt-1 text-sm text-destructive">{{ form.errors.restaurant_id }}</p>
                </div>

                <div>
                    <label for="featured-story" class="text-sm font-medium">Story (optional)</label>
                    <select id="featured-story" v-model="form.blog_post_id" :class="inputClass">
                        <option :value="null">No story</option>
                        <option v-for="story in props.featured.stories" :key="story.id" :value="story.id">{{ story.title }}</option>
                    </select>
                </div>

                <div>
                    <label for="featured-ends" class="text-sm font-medium">Feature until (optional)</label>
                    <input id="featured-ends" v-model="form.ends_at" type="date" :class="inputClass" />
                    <p v-if="form.errors.ends_at" class="mt-1 text-sm text-destructive">{{ form.errors.ends_at }}</p>
                </div>

                <div>
                    <label for="featured-image" class="text-sm font-medium">Photo URL (optional)</label>
                    <input id="featured-image" v-model="form.image_url" type="url" placeholder="https://…" :class="inputClass" />
                    <p v-if="form.errors.image_url" class="mt-1 text-sm text-destructive">{{ form.errors.image_url }}</p>
                </div>

                <div>
                    <label for="featured-credit" class="text-sm font-medium">Photo credit</label>
                    <input id="featured-credit" v-model="form.image_credit" type="text" placeholder="Photo: name, license, source" :class="inputClass" />
                    <p v-if="form.errors.image_credit" class="mt-1 text-sm text-destructive">{{ form.errors.image_credit }}</p>
                </div>

                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="!form.restaurant_id || form.processing" data-testid="featured-submit">Feature this restaurant</Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
