<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { ArrowRight, Calendar, PenLine, Star, Tag, User } from '@lucide/vue'
import { Card, CardContent } from '@/components/ui/card'
import { useImageFallback } from '@/composables/useImageFallback'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    category: string | null
    featured_image: string | null
    published_at: string | null
    is_featured: boolean
    author?: { id: number; name: string } | null
}

const props = defineProps<{
    posts: BlogPost[]
}>()

const heroPost = computed(() => props.posts[0] ?? null)
const gridPosts = computed(() => props.posts.slice(1))

const { failed: heroImageFailed, markFailed: markHeroImageFailed, reset: resetHeroImageFailed } = useImageFallback()
const failedGridIds = ref<Set<number>>(new Set())

watch(heroPost, resetHeroImageFailed)

function markGridImageFailed(id: number) {
    failedGridIds.value = new Set(failedGridIds.value).add(id)
}

function formatDate(value: string | null): string {
    if (!value) return ''
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}
</script>

<template>
    <section v-if="posts.length > 0" class="w-full bg-background py-12">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-foreground">Featured Restaurant</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Guides, trends, and dining insights</p>
                </div>
                <Link
                    href="/blog"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    View all
                    <ArrowRight class="h-4 w-4" />
                </Link>
            </div>

            <!-- Hero post -->
            <Card
                v-if="heroPost"
                class="group mx-auto mb-6 max-w-[1067px] overflow-hidden transition-shadow hover:shadow-md"
            >
                <Link :href="`/blog/${heroPost.slug}`" class="relative block aspect-video overflow-hidden sm:aspect-[21/9]">
                    <img
                        v-if="heroPost.featured_image && !heroImageFailed"
                        :src="heroPost.featured_image"
                        :alt="heroPost.title"
                        @error="markHeroImageFailed"
                        class="absolute inset-0 h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                    />
                    <div
                        v-else
                        class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/10"
                    >
                        <div class="flex flex-col items-center gap-3 opacity-20">
                            <PenLine class="h-16 w-16 text-foreground sm:h-20 sm:w-20" />
                            <div class="hidden w-32 space-y-1.5 sm:block">
                                <div class="h-1 w-full rounded-full bg-foreground" />
                                <div class="h-1 w-3/4 rounded-full bg-foreground" />
                                <div class="h-1 w-1/2 rounded-full bg-foreground" />
                            </div>
                        </div>
                        <span class="sr-only">No image</span>
                    </div>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent" />
                    <CardContent class="absolute inset-x-0 bottom-0 flex flex-col justify-end p-4 sm:p-6">
                        <div class="mb-2 flex flex-wrap items-center gap-1.5">
                            <span
                                v-if="heroPost.is_featured"
                                class="inline-flex w-fit items-center gap-1 rounded-full bg-amber-400/90 px-2.5 py-0.5 text-xs font-semibold text-amber-950 backdrop-blur-sm"
                            >
                                <Star class="h-3 w-3 fill-current" />
                                Featured
                            </span>
                            <span
                                v-if="heroPost.category"
                                class="inline-flex w-fit items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-medium text-white backdrop-blur-sm"
                            >
                                <Tag class="h-3 w-3" />
                                {{ heroPost.category }}
                            </span>
                        </div>
                        <p class="mb-2 flex items-center gap-1.5 text-xs text-white/80">
                            <Calendar class="h-3.5 w-3.5" />
                            {{ formatDate(heroPost.published_at) }}
                            <template v-if="heroPost.author">
                                <span aria-hidden="true" class="opacity-50">·</span>
                                <User class="h-3.5 w-3.5" />
                                {{ heroPost.author.name }}
                            </template>
                        </p>
                        <h3 class="text-lg font-bold text-white sm:text-xl">{{ heroPost.title }}</h3>
                        <p class="mt-2 line-clamp-4 text-sm text-white/70 sm:line-clamp-2">{{ heroPost.excerpt }}</p>
                        <span class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-white/90 group-hover:underline sm:mt-4">
                            Read more
                            <ArrowRight class="h-3.5 w-3.5" />
                        </span>
                    </CardContent>
                </Link>
            </Card>

            <!-- Grid of remaining posts -->
            <div v-if="gridPosts.length > 0" class="grid gap-5 sm:grid-cols-2">
                <Card
                    v-for="post in gridPosts"
                    :key="post.id"
                    class="group overflow-hidden transition-shadow hover:shadow-md"
                >
                    <Link :href="`/blog/${post.slug}`" class="block h-full">
                        <div class="aspect-video overflow-hidden bg-muted">
                            <img
                                v-if="post.featured_image && !failedGridIds.has(post.id)"
                                :src="post.featured_image"
                                :alt="post.title"
                                @error="() => markGridImageFailed(post.id)"
                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/10"
                            >
                                <div class="flex flex-col items-center gap-2 opacity-20">
                                    <PenLine class="h-10 w-10 text-foreground" />
                                    <div class="hidden w-20 space-y-1 sm:block">
                                        <div class="h-0.5 w-full rounded-full bg-foreground" />
                                        <div class="h-0.5 w-2/3 rounded-full bg-foreground" />
                                    </div>
                                </div>
                                <span class="sr-only">No image</span>
                            </div>
                        </div>
                        <CardContent class="p-5">
                            <div class="mb-2 flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="post.is_featured"
                                    class="inline-flex w-fit items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800"
                                >
                                    <Star class="h-3 w-3 fill-current" />
                                    Featured
                                </span>
                                <span
                                    v-if="post.category"
                                    class="inline-flex w-fit items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary"
                                >
                                    <Tag class="h-3 w-3" />
                                    {{ post.category }}
                                </span>
                            </div>
                            <p class="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Calendar class="h-3.5 w-3.5" />
                                {{ formatDate(post.published_at) }}
                                <template v-if="post.author">
                                    <span aria-hidden="true" class="opacity-50">·</span>
                                    <User class="h-3.5 w-3.5" />
                                    {{ post.author.name }}
                                </template>
                            </p>
                            <h3 class="font-semibold text-foreground group-hover:text-primary">{{ post.title }}</h3>
                            <p class="mt-2 line-clamp-3 text-sm text-muted-foreground">{{ post.excerpt }}</p>
                        </CardContent>
                    </Link>
                </Card>
            </div>
        </div>
    </section>
</template>
