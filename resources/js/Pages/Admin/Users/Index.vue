<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import type { PageProps } from '@/types'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ShieldCheck, Users, X } from '@lucide/vue'

interface UserRow {
    id: number
    name: string
    email: string
    role: string
    email_verified_at: string | null
    created_at: string | null
}

const props = defineProps<{
    users: {
        data: UserRow[]
        links: { url: string | null; label: string; active: boolean }[]
        total: number
    }
    filter: string | null
    roles: string[]
}>()

const page = usePage<PageProps<{ flash?: { success?: string } }>>()
const currentUserId = page.props.auth.user.id
const successFlash = ref<string | null>(page.props.flash?.success ?? null)
const roleError = computed<string | null>(() => {
    const errors = page.props.errors as Record<string, string> | undefined
    return errors?.['role'] ?? null
})

function isSelf(user: UserRow): boolean {
    return user.id === currentUserId
}

function roleBadgeVariant(role: string): 'default' | 'secondary' | 'outline' {
    if (role === 'admin') return 'default'
    if (role === 'editor') return 'secondary'
    return 'outline'
}

function formatDate(value: string | null): string {
    if (!value) return '—'
    return new Date(value).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function setFilter(role: string | null): void {
    router.get(route('admin.users.index'), role ? { role } : {}, { preserveState: true })
}

function updateRole(user: UserRow, role: string): void {
    if (isSelf(user)) return

    router.patch(
        route('admin.users.update', user.id),
        { role },
        {
            preserveScroll: true,
            onSuccess: () => {
                successFlash.value = `Role for ${user.email} updated to ${role}.`
            },
            onError: () => {
                successFlash.value = null
            },
        },
    )
}
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Users</h2>
                <span class="text-sm text-neutral-500">{{ props.users.total }} total</span>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': filter === null }"
                        @click="setFilter(null)"
                    >
                        All
                    </Button>
                    <Button
                        v-for="role in roles"
                        :key="role"
                        variant="outline"
                        size="sm"
                        :class="{ 'border-primary text-primary': filter === role }"
                        @click="setFilter(role)"
                    >
                        {{ role }}
                    </Button>
                </div>

                <div v-if="roleError" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">
                    {{ roleError }}
                </div>

                <div v-if="successFlash" class="mb-4 flex items-center justify-between rounded-lg bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <span>{{ successFlash }}</span>
                    <button
                        type="button"
                        class="text-emerald-600 hover:text-emerald-800"
                        aria-label="Dismiss"
                        @click="successFlash = null"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                                <th class="px-4 py-3 font-medium">Name</th>
                                <th class="px-4 py-3 font-medium">Email</th>
                                <th class="px-4 py-3 font-medium">Role</th>
                                <th class="px-4 py-3 font-medium">Joined</th>
                                <th class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="border-b border-neutral-100 last:border-0 hover:bg-neutral-50"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2 font-medium text-gray-900">
                                        <Users class="h-4 w-4 shrink-0 text-neutral-400" />
                                        <span>{{ user.name }}</span>
                                        <Badge v-if="isSelf(user)" variant="secondary">You</Badge>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ user.email }}</td>
                                <td class="px-4 py-3">
                                    <Badge :variant="roleBadgeVariant(user.role)">{{ user.role }}</Badge>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ formatDate(user.created_at) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <ShieldCheck class="h-4 w-4 text-neutral-400" />
                                        <select
                                            :value="user.role"
                                            :disabled="isSelf(user)"
                                            :title="isSelf(user) ? 'You cannot change your own role' : undefined"
                                            class="rounded-md border border-neutral-300 bg-white px-2 py-1 text-sm text-gray-800 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                            :class="{ 'cursor-not-allowed opacity-50': isSelf(user) }"
                                            @change="updateRole(user, ($event.target as HTMLSelectElement).value)"
                                        >
                                            <option v-for="role in roles" :key="role" :value="role">
                                                {{ role }}
                                            </option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p v-if="users.data.length === 0" class="px-4 py-8 text-center text-sm text-neutral-500">
                        No users found.
                    </p>
                </div>

                <nav v-if="users.links.length > 3" class="mt-4 flex items-center justify-center gap-1">
                    <template v-for="(link, index) in users.links" :key="index">
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
