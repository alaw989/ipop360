<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Calendar, Eye, FileText, Pencil, Plus, Trash2 } from '@lucide/vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    status: string
    featured_image: string | null
    published_at: string | null
    author: { name: string } | null
}

defineProps<{
    posts: {
        data: BlogPost[]
        links: { url: string | null; label: string; active: boolean }[]
        total: number
    }
    filter: string | null
}>()

function formatDate(value: string | null): string {
    if (!value) return '—'
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function setFilter(status: string | null): void {
    router.get(route('admin.blog.index'), status ? { status } : {}, { preserveState: true })
}

function destroy(post: BlogPost): void {
    if (window.confirm(`Delete "${post.title}"?`)) {
        router.delete(route('admin.blog.destroy', post.id))
    }
}
</script>

<template>
    <Head title="Blog Posts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Blog Posts</h2>
                <Button as-child size="sm">
                    <Link :href="route('admin.blog.create')">
                        <Plus class="mr-1 h-4 w-4" />
                        New Post
                    </Link>
                </Button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': filter === null }"
                        @click="setFilter(null)"
                    >
                        All
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': filter === 'published' }"
                        @click="setFilter('published')"
                    >
                        Published
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': filter === 'draft' }"
                        @click="setFilter('draft')"
                    >
                        Drafts
                    </Button>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                                <th class="px-4 py-3 font-medium">Title</th>
                                <th class="px-4 py-3 font-medium">Author</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Published</th>
                                <th class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="post in posts.data"
                                :key="post.id"
                                class="border-b border-neutral-100 last:border-0 hover:bg-neutral-50"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 font-medium text-gray-900">
                                        <FileText class="h-4 w-4 shrink-0 text-neutral-400" />
                                        <span>{{ post.title }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ post.author?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="post.status === 'published' ? 'default' : 'secondary'">
                                        {{ post.status }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">
                                    <span class="flex items-center gap-1">
                                        <Calendar class="h-3.5 w-3.5 text-neutral-400" />
                                        {{ formatDate(post.published_at) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <Link
                                            v-if="post.status === 'published'"
                                            :href="`/blog/${post.slug}`"
                                            title="View"
                                            class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700"
                                        >
                                            <Eye class="h-4 w-4" />
                                        </Link>
                                        <Link
                                            :href="route('admin.blog.edit', post.id)"
                                            title="Edit"
                                            class="rounded p-1.5 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700"
                                        >
                                            <Pencil class="h-4 w-4" />
                                        </Link>
                                        <button
                                            type="button"
                                            title="Delete"
                                            class="rounded p-1.5 text-neutral-500 hover:bg-red-50 hover:text-red-600"
                                            @click="destroy(post)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="posts.data.length === 0" class="px-4 py-8 text-center text-sm text-neutral-500">
                        No blog posts found.
                    </p>
                </div>

                <nav v-if="posts.links.length > 3" class="mt-4 flex items-center justify-center gap-1">
                    <template v-for="(link, index) in posts.links" :key="index">
                        <span v-if="link.url === null" class="px-3 py-1 text-sm text-neutral-400">{{ link.label }}</span>
                        <Link
                            v-else
                            :href="link.url"
                            class="px-3 py-1 text-sm"
                            :class="link.active ? 'font-semibold text-primary' : 'text-neutral-600 hover:text-primary'"
                        >
                            {{ link.label }}
                        </Link>
                    </template>
                </nav>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
