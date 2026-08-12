<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Menu, X } from '@lucide/vue'
import BrandLogo from '@/Components/BrandLogo.vue'
import { Badge } from '@/components/ui/badge'

interface Props {
    sticky?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    sticky: true,
})

const canManageBlog = computed(() => ['admin', 'editor'].includes(usePage().props.auth?.user?.role ?? ''))

const mobileMenuOpen = ref(false)

function closeMobileMenu() {
    mobileMenuOpen.value = false
}
</script>

<template>
    <nav
        class="border-b border-border bg-card/80 backdrop-blur-sm z-50"
        :class="props.sticky ? 'sticky top-0' : undefined"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <Link href="/" class="flex items-center gap-2" aria-label="iPop360 home">
                    <BrandLogo class="text-[2.25rem]" />
                    <Badge variant="outline" class="text-xs">Beta</Badge>
                </Link>

                <!-- Desktop links -->
                <div class="hidden items-center gap-4 md:flex">
                    <Link
                        href="/restaurants"
                        class="text-sm text-muted-foreground hover:text-primary transition-colors"
                    >
                        Browse
                    </Link>
                    <Link
                        href="/leaderboard"
                        class="text-sm text-muted-foreground hover:text-primary transition-colors"
                    >
                        Leaderboard
                    </Link>
                    <Link
                        href="/blog"
                        class="text-sm text-muted-foreground hover:text-primary transition-colors"
                    >
                        Blog
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/favorites"
                        class="text-sm text-muted-foreground hover:text-primary transition-colors"
                    >
                        Favorites
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/dashboard"
                        class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-if="canManageBlog"
                        :href="route('admin.blog.index')"
                        class="text-sm text-muted-foreground hover:text-primary transition-colors"
                    >
                        Manage Blog
                    </Link>
                    <Link
                        v-else-if="!$page.props.auth?.user"
                        href="/login"
                        class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Login
                    </Link>
                </div>

                <!-- Mobile menu toggle -->
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground transition-colors md:hidden"
                    :aria-label="mobileMenuOpen ? 'Close menu' : 'Open menu'"
                    :aria-expanded="mobileMenuOpen"
                    data-testid="menu-toggle"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <Menu v-if="!mobileMenuOpen" class="h-5 w-5" />
                    <X v-else class="h-5 w-5" />
                </button>
            </div>
        </div>

        <!-- Mobile menu -->
        <div v-if="mobileMenuOpen" class="border-t border-border md:hidden" data-testid="mobile-menu">
            <div class="mx-auto max-w-7xl px-4 py-2 sm:px-6 lg:px-8">
                <div class="flex flex-col pb-2">
                    <Link
                        href="/restaurants"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-primary transition-colors"
                        @click="closeMobileMenu"
                    >
                        Browse
                    </Link>
                    <Link
                        href="/leaderboard"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-primary transition-colors"
                        @click="closeMobileMenu"
                    >
                        Leaderboard
                    </Link>
                    <Link
                        href="/blog"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-primary transition-colors"
                        @click="closeMobileMenu"
                    >
                        Blog
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/favorites"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-primary transition-colors"
                        @click="closeMobileMenu"
                    >
                        Favorites
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/dashboard"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                        @click="closeMobileMenu"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-if="canManageBlog"
                        :href="route('admin.blog.index')"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-primary transition-colors"
                        @click="closeMobileMenu"
                    >
                        Manage Blog
                    </Link>
                    <Link
                        v-else-if="!$page.props.auth?.user"
                        href="/login"
                        class="rounded-md px-2 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                        @click="closeMobileMenu"
                    >
                        Login
                    </Link>
                </div>
            </div>
        </div>
    </nav>
</template>