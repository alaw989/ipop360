<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Calendar, ChevronRight } from '@lucide/vue'
import { useSeo } from '@/composables/useSeo'
import { useBaseUrl } from '@/composables/useBaseUrl'
import SeoMeta from '@/Components/SeoMeta.vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    featured_image: string | null
    published_at: string | null
    author: { name: string } | null
}

defineProps<{
    posts: {
        data: BlogPost[]
        links: { url: string | null; label: string; active: boolean }[]
    }
}>()

const baseUrl = useBaseUrl()

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
}

const seoData = useSeo({
    title: 'Blog | iPop360',
    description: 'Restaurant insights, food trends, and city dining guides from the iPop360 team.',
    url: `${baseUrl}/blog`,
})
</script>

<template>
    <Head title="Blog" />
    <SeoMeta :seo-data="seoData" />

    <AppLayout>
        <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
            <header class="mb-10">
                <h1 class="text-3xl font-bold tracking-tight text-foreground">Blog</h1>
                <p class="mt-2 text-muted-foreground">
                    Restaurant insights, food trends, and dining guides.
                </p>
            </header>

            <div v-if="posts.data.length > 0" class="grid gap-6 sm:grid-cols-2">
                <article
                    v-for="post in posts.data"
                    :key="post.id"
                    class="group overflow-hidden rounded-xl border border-border bg-card transition-shadow hover:shadow-md"
                >
                    <Link :href="`/blog/${post.slug}`">
                        <div v-if="post.featured_image" class="aspect-video overflow-hidden bg-muted">
                            <img
                                :src="post.featured_image"
                                :alt="post.title"
                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                        </div>
                        <div class="p-5">
                            <p class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Calendar class="h-3.5 w-3.5" />
                                {{ formatDate(post.published_at) }}
                            </p>
                            <h2 class="text-lg font-semibold text-foreground group-hover:text-primary">
                                {{ post.title }}
                            </h2>
                            <p class="mt-2 line-clamp-3 text-sm text-muted-foreground">{{ post.excerpt }}</p>
                            <span class="mt-3 inline-flex items-center text-sm font-medium text-primary">
                                Read more
                                <ChevronRight class="ml-1 h-4 w-4" />
                            </span>
                        </div>
                    </Link>
                </article>
            </div>

            <p v-else class="py-16 text-center text-muted-foreground">
                No articles yet — check back soon.
            </p>

            <nav v-if="posts.links.length > 3" class="mt-10 flex items-center justify-center gap-1">
                <template v-for="(link, index) in posts.links" :key="index">
                    <span v-if="link.url === null" class="px-3 py-1 text-sm text-muted-foreground">{{ link.label }}</span>
                    <Link
                        v-else
                        :href="link.url"
                        class="px-3 py-1 text-sm"
                        :class="link.active ? 'font-semibold text-primary' : 'text-muted-foreground hover:text-primary'"
                    >
                        {{ link.label }}
                    </Link>
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
