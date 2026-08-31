<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { ArrowLeft, Calendar, PenLine, User } from '@lucide/vue'
import { useSeo, generateArticleJsonLd } from '@/composables/useSeo'
import { useBaseUrl } from '@/composables/useBaseUrl'
import { useImageFallback } from '@/composables/useImageFallback'
import JsonLd from '@/Components/JsonLd.vue'
import SeoMeta from '@/Components/SeoMeta.vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    body: string
    featured_image: string | null
    published_at: string | null
    author: { name: string } | null
}

const props = defineProps<{
    post: BlogPost
}>()

const baseUrl = useBaseUrl()

const { failed: imageFailed, markFailed: markImageFailed } = useImageFallback()

const publishedLabel = computed(() => {
    if (!props.post.published_at) return ''
    return new Date(props.post.published_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    })
})

const seoData = useSeo({
    title: `${props.post.title} | iPop360 Blog`,
    description: props.post.excerpt,
    url: `${baseUrl.value}/blog/${props.post.slug}`,
    type: 'article',
    ...(props.post.featured_image ? { image: props.post.featured_image } : {}),
})

const jsonLd = generateArticleJsonLd({
    title: props.post.title,
    url: `${baseUrl.value}/blog/${props.post.slug}`,
    ...(props.post.featured_image ? { image: props.post.featured_image } : {}),
    ...(props.post.published_at ? { publishedAt: props.post.published_at } : {}),
    ...(props.post.author?.name ? { author: props.post.author.name } : {}),
    excerpt: props.post.excerpt,
})
</script>

<template>
    <Head :title="post.title" />
    <SeoMeta :seo-data="seoData" />
    <JsonLd :data="jsonLd" />

    <AppLayout>
        <article class="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
            <Link
                href="/blog"
                class="mb-6 inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-primary"
            >
                <ArrowLeft class="h-4 w-4" />
                Back to blog
            </Link>

            <header class="mb-8">
                <h1 class="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">{{ post.title }}</h1>
                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    <span v-if="post.author?.name" class="inline-flex items-center gap-1.5">
                        <User class="h-4 w-4" />
                        {{ post.author.name }}
                    </span>
                    <span v-if="publishedLabel" class="inline-flex items-center gap-1.5">
                        <Calendar class="h-4 w-4" />
                        {{ publishedLabel }}
                    </span>
                </div>
            </header>

            <img
                v-if="post.featured_image && !imageFailed"
                :src="post.featured_image"
                :alt="post.title"
                @error="markImageFailed"
                class="mb-8 aspect-video w-full rounded-xl object-cover"
            />
            <div
                v-else-if="post.featured_image"
                class="mb-8 flex aspect-video w-full items-center justify-center rounded-xl bg-gradient-to-br from-muted/50 via-muted/30 to-muted/10"
            >
                <PenLine class="h-16 w-16 text-foreground opacity-20" />
            </div>

            <div
                class="prose prose-neutral max-w-none dark:prose-invert"
                v-html="post.body"
            />
        </article>
    </AppLayout>
</template>
