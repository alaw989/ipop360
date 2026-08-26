<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Menu, X } from '@lucide/vue'
import BrandLogo from '@/Components/BrandLogo.vue'
import { Badge } from '@/components/ui/badge'
import { Sheet, SheetContent, SheetTrigger, SheetTitle, SheetDescription } from '@/components/ui/sheet'

interface Props {
    sticky?: boolean
    transparent?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    sticky: true,
    transparent: false,
})

const canManageBlog = computed(() => ['admin', 'editor'].includes(usePage().props.auth?.user?.role ?? ''))

const mobileMenuOpen = ref(false)

function closeMobileMenu() {
    mobileMenuOpen.value = false
}
</script>

<template>
    <nav
        class="z-50"
        :class="[
            props.transparent
                ? 'absolute inset-x-0 top-0 bg-transparent'
                : 'border-b border-border bg-card/80 backdrop-blur-sm',
            props.sticky ? 'sticky top-0' : undefined,
        ]"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <Link href="/" class="flex items-center gap-2" aria-label="iPop360 home">
                    <BrandLogo
                        class="text-[2.25rem]"
                        :class="props.transparent ? 'text-white' : undefined"
                    />
                    <Badge
                        variant="outline"
                        class="text-xs"
                        aria-hidden="true"
                        :class="props.transparent ? 'border-white/50 text-white' : undefined"
                    >Beta</Badge>
                </Link>

                <!-- Desktop links -->
                <div class="hidden items-center gap-4 md:flex">
                    <Link
                        href="/restaurants"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-primary'"
                    >
                        Browse
                    </Link>
                    <Link
                        href="/leaderboard"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-primary'"
                    >
                        Leaderboard
                    </Link>
                    <Link
                        href="/blog"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-primary'"
                    >
                        Blog
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/favorites"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-primary'"
                    >
                        Favorites
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/dashboard"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-foreground'"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-if="canManageBlog"
                        :href="route('admin.blog.index')"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-primary'"
                    >
                        Manage Blog
                    </Link>
                    <Link
                        v-else-if="!$page.props.auth?.user"
                        href="/login"
                        class="text-sm transition-colors"
                        :class="props.transparent
                            ? 'text-white/80 hover:text-white'
                            : 'text-muted-foreground hover:text-foreground'"
                    >
                        Login
                    </Link>
                </div>

                <!-- Mobile menu drawer -->
                <Sheet v-model:open="mobileMenuOpen">
                    <SheetTrigger as-child>
                        <button
                            type="button"
                            class="flex h-10 w-10 items-center justify-center rounded-md transition-colors md:hidden"
                            :class="props.transparent
                                ? 'text-white/80 hover:bg-white/10 hover:text-white'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                            :aria-label="mobileMenuOpen ? 'Close menu' : 'Open menu'"
                            :aria-expanded="mobileMenuOpen"
                            data-testid="menu-toggle"
                        >
                            <Menu v-if="!mobileMenuOpen" class="h-5 w-5" />
                            <X v-else class="h-5 w-5" />
                        </button>
                    </SheetTrigger>
                    <SheetContent side="right" class="w-80 p-0 sm:w-80" :show-close-button="false">
                        <div class="flex h-16 items-center justify-between border-b border-border px-4">
                            <SheetTitle class="text-sm">Menu</SheetTitle>
                            <button
                                type="button"
                                class="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                aria-label="Close menu"
                                data-testid="mobile-menu-close"
                                @click="closeMobileMenu"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                        <SheetDescription class="sr-only">Site navigation</SheetDescription>
                        <nav class="flex flex-col gap-1 px-3 py-3" data-testid="mobile-menu">
                            <Link
                                href="/restaurants"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Browse
                            </Link>
                            <Link
                                href="/leaderboard"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Leaderboard
                            </Link>
                            <Link
                                href="/blog"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Blog
                            </Link>
                            <Link
                                v-if="$page.props.auth?.user"
                                href="/favorites"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Favorites
                            </Link>
                            <Link
                                v-if="$page.props.auth?.user"
                                href="/dashboard"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Dashboard
                            </Link>
                            <Link
                                v-if="canManageBlog"
                                :href="route('admin.blog.index')"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Manage Blog
                            </Link>
                            <Link
                                v-else-if="!$page.props.auth?.user"
                                href="/login"
                                class="rounded-md px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted hover:text-primary"
                                @click="closeMobileMenu"
                            >
                                Login
                            </Link>
                        </nav>
                    </SheetContent>
                </Sheet>
            </div>
        </div>
    </nav>
</template>
