<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import BlogEditor from '@/Components/BlogEditor.vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    category: string | null
    body: string
    featured_image: string | null
    status: string
    published_at: string | null
    author: { name: string } | null
}

const props = defineProps<{
    post: BlogPost | null
}>()

const isEditing = computed(() => props.post !== null)

const form = useForm({
    title: props.post?.title ?? '',
    excerpt: props.post?.excerpt ?? '',
    category: props.post?.category ?? '',
    body: props.post?.body ?? '',
    featured_image: props.post?.featured_image ?? '',
    status: props.post?.status ?? 'draft',
})

function submit(): void {
    if (isEditing.value) {
        form.put(route('admin.blog.update', props.post!.id))
    } else {
        form.post(route('admin.blog.store'))
    }
}
</script>

<template>
    <Head :title="isEditing ? 'Edit Blog Post' : 'New Blog Post'" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ isEditing ? 'Edit Blog Post' : 'New Blog Post' }}
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit">
                        <div class="space-y-6 p-6">
                            <div>
                                <label for="title" class="mb-1 block text-sm font-medium text-gray-700">Title</label>
                                <Input id="title" v-model="form.title" type="text" placeholder="Article title" required />
                                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                            </div>

                            <div>
                                <label for="excerpt" class="mb-1 block text-sm font-medium text-gray-700">Excerpt</label>
                                <textarea
                                    id="excerpt"
                                    v-model="form.excerpt"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
                                    placeholder="Short summary shown on the blog index and homepage"
                                    required
                                />
                                <p v-if="form.errors.excerpt" class="mt-1 text-sm text-red-600">{{ form.errors.excerpt }}</p>
                            </div>

                            <div>
                                <label for="category" class="mb-1 block text-sm font-medium text-gray-700">
                                    Category <span class="font-normal text-neutral-400">(optional)</span>
                                </label>
                                <Input id="category" v-model="form.category" type="text" placeholder="e.g. News, Guide, Review" maxlength="100" />
                                <p v-if="form.errors.category" class="mt-1 text-sm text-red-600">{{ form.errors.category }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Body</label>
                                <BlogEditor v-model="form.body" />
                                <p v-if="form.errors.body" class="mt-1 text-sm text-red-600">{{ form.errors.body }}</p>
                            </div>

                            <div>
                                <label for="featured_image" class="mb-1 block text-sm font-medium text-gray-700">
                                    Featured Image URL <span class="font-normal text-neutral-400">(optional)</span>
                                </label>
                                <Input id="featured_image" v-model="form.featured_image" type="url" placeholder="https://…" />
                                <p v-if="form.errors.featured_image" class="mt-1 text-sm text-red-600">{{ form.errors.featured_image }}</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Status</label>
                                <select
                                    v-model="form.status"
                                    class="mt-1 block w-full rounded-md border-neutral-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
                                >
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                </select>
                            </div>

                            <div v-if="form.errors.body" class="rounded-md bg-red-50 p-3 text-sm text-red-600">
                                {{ form.errors.body }}
                            </div>
                        </div>

                        <div class="flex items-center justify-between border-t border-neutral-200 bg-neutral-50 px-6 py-4">
                            <Link :href="route('admin.blog.index')" class="text-sm text-neutral-500 hover:text-neutral-700">
                                Cancel
                            </Link>
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Saving…' : isEditing ? 'Update Post' : 'Create Post' }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
