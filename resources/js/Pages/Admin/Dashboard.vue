<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { AlertCircle, CheckCircle2, Clock, Globe, Image, Loader2, Share2, Utensils } from '@lucide/vue';

defineProps<{
    serpapiQuota: {
        calls_used: number;
        free_quota: number;
        remaining: number;
        pct_used: number;
        circuit_breaker_threshold: number;
        circuit_breaker_tripped: boolean;
        enrich_budget: number;
        enrich_budget_exhausted: boolean;
    };
    scrapeHealth: {
        last_social_scrape: string | null;
        hours_since_social_scrape: number | null;
        total_social_links: number;
    };
    dataQuality: {
        total_restaurants: number;
        with_website: number;
        with_website_pct: number;
        with_social_links: number;
        with_social_links_pct: number;
        with_opening_hours: number;
        with_opening_hours_pct: number;
        with_photo: number;
        with_photo_pct: number;
        missing_data: {
            id: number;
            name: string;
            slug: string;
            gaps: string[];
            gap_count: number;
        }[];
    };
}>();

function quotaColor(pct: number): string {
    if (pct >= 90) return 'text-red-600 dark:text-red-400';
    if (pct >= 70) return 'text-amber-600 dark:text-amber-400';
    return 'text-emerald-600 dark:text-emerald-400';
}

function pctColor(pct: number): string {
    if (pct >= 80) return 'text-emerald-600 dark:text-emerald-400';
    if (pct >= 50) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
}

function gapBadgeVariant(gap: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (gap) {
        case 'website': return 'destructive';
        case 'social': return 'destructive';
        case 'hours': return 'secondary';
        case 'photo': return 'outline';
        default: return 'secondary';
    }
}
</script>

<template>
    <Head title="Admin Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Admin Dashboard</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- SerpApi Quota -->
                <div class="mb-8">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900">SerpApi Quota</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Usage</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div :class="['text-2xl font-bold', quotaColor(serpapiQuota.pct_used)]">
                                    {{ serpapiQuota.calls_used }} / {{ serpapiQuota.free_quota }}
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ serpapiQuota.remaining }} remaining ({{ serpapiQuota.pct_used }}%)
                                </p>
                                <div class="mt-2 h-2 w-full rounded-full bg-neutral-200 dark:bg-neutral-700">
                                    <div
                                        class="h-2 rounded-full transition-all"
                                        :class="serpapiQuota.pct_used >= 90 ? 'bg-red-500' : serpapiQuota.pct_used >= 70 ? 'bg-amber-500' : 'bg-emerald-500'"
                                        :style="{ width: Math.min(serpapiQuota.pct_used, 100) + '%' }"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Circuit Breaker</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <CheckCircle2 v-if="!serpapiQuota.circuit_breaker_tripped" class="h-5 w-5 text-emerald-500" />
                                    <AlertCircle v-else class="h-5 w-5 text-amber-500" />
                                    <span :class="['text-sm font-medium', serpapiQuota.circuit_breaker_tripped ? 'text-amber-600' : 'text-emerald-600']">
                                        {{ serpapiQuota.circuit_breaker_tripped ? 'Tripped' : 'Open' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Threshold: {{ serpapiQuota.circuit_breaker_threshold }} calls
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Enrich Budget</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <CheckCircle2 v-if="!serpapiQuota.enrich_budget_exhausted" class="h-5 w-5 text-emerald-500" />
                                    <AlertCircle v-else class="h-5 w-5 text-amber-500" />
                                    <span :class="['text-sm font-medium', serpapiQuota.enrich_budget_exhausted ? 'text-amber-600' : 'text-emerald-600']">
                                        {{ serpapiQuota.enrich_budget_exhausted ? 'Exhausted' : 'Available' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Budget: {{ serpapiQuota.enrich_budget }} / month
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Live Read Path</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <CheckCircle2 v-if="!serpapiQuota.circuit_breaker_tripped" class="h-5 w-5 text-emerald-500" />
                                    <Loader2 v-else class="h-5 w-5 text-amber-500 animate-spin" />
                                    <span :class="['text-sm font-medium', serpapiQuota.circuit_breaker_tripped ? 'text-amber-600' : 'text-emerald-600']">
                                        {{ serpapiQuota.circuit_breaker_tripped ? 'Cache only' : 'Live' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ serpapiQuota.remaining }} calls left this cycle
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Scrape Health -->
                <div class="mb-8">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900">Scrape Health</h3>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Last Social Scrape</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Clock class="h-5 w-5 text-muted-foreground" />
                                    <span v-if="scrapeHealth.last_social_scrape" class="text-sm font-medium">
                                        {{ scrapeHealth.last_social_scrape }}
                                    </span>
                                    <span v-else class="text-sm text-muted-foreground">Never</span>
                                </div>
                                <p v-if="scrapeHealth.hours_since_social_scrape !== null" class="mt-1 text-xs text-muted-foreground">
                                    {{ scrapeHealth.hours_since_social_scrape }} hours ago
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Total Social Links</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Share2 class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ scrapeHealth.total_social_links }}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Data Quality -->
                <div class="mb-8">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900">Data Quality</h3>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">Total</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Utensils class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ dataQuality.total_restaurants }}</span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">With Website</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Globe class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ dataQuality.with_website }}</span>
                                </div>
                                <p :class="['mt-1 text-xs', pctColor(dataQuality.with_website_pct)]">
                                    {{ dataQuality.with_website_pct }}%
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">With Social Links</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Share2 class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ dataQuality.with_social_links }}</span>
                                </div>
                                <p :class="['mt-1 text-xs', pctColor(dataQuality.with_social_links_pct)]">
                                    {{ dataQuality.with_social_links_pct }}%
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">With Opening Hours</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Clock class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ dataQuality.with_opening_hours }}</span>
                                </div>
                                <p :class="['mt-1 text-xs', pctColor(dataQuality.with_opening_hours_pct)]">
                                    {{ dataQuality.with_opening_hours_pct }}%
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader class="pb-2">
                                <CardTitle class="text-sm font-medium text-muted-foreground">With Photo</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex items-center gap-2">
                                    <Image class="h-5 w-5 text-muted-foreground" />
                                    <span class="text-2xl font-bold">{{ dataQuality.with_photo }}</span>
                                </div>
                                <p :class="['mt-1 text-xs', pctColor(dataQuality.with_photo_pct)]">
                                    {{ dataQuality.with_photo_pct }}%
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Missing Data Table -->
                <div>
                    <h3 class="mb-4 text-lg font-semibold text-gray-900">Restaurants Missing Data</h3>
                    <Card v-if="dataQuality.missing_data.length > 0">
                        <CardContent class="p-0">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-neutral-200 dark:border-neutral-700">
                                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                                            <th class="px-4 py-3 text-left font-medium text-muted-foreground">Gaps</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in dataQuality.missing_data"
                                            :key="row.id"
                                            class="border-b border-neutral-100 last:border-0 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                        >
                                            <td class="px-4 py-3">
                                                <Link
                                                    :href="`/restaurants/${row.slug}`"
                                                    class="font-medium text-primary hover:underline"
                                                >
                                                    {{ row.name }}
                                                </Link>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex flex-wrap gap-1.5">
                                                    <Badge
                                                        v-for="gap in row.gaps"
                                                        :key="gap"
                                                        :variant="gapBadgeVariant(gap)"
                                                    >
                                                        {{ gap }}
                                                    </Badge>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                    <p v-else class="text-sm text-muted-foreground">
                        All restaurants have complete data!
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
