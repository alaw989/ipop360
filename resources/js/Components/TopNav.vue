<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Menu, Search, X } from '@lucide/vue'
import BrandLogo from '@/Components/BrandLogo.vue'
import SiteSearch from '@/Components/SiteSearch.vue'
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

// Search on every page, as on Yelp. The home page (transparent header over
// the hero) leaves it out: the hero has the same search, larger.
const mobileSearchOpen = ref(false)
</script>

<template>
    <nav
        aria-label="Main"
        class="z-50 pt-[env(safe-area-inset-top)]"
        :class="[
            props.transparent
                ? 'absolute inset-x-0 top-0 bg-transparent'
                : 'border-b border-border bg-card/80 backdrop-blur-sm',
            props.sticky ? 'sticky top-0' : undefined,
        ]"
    >
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <Link href="/" class="flex shrink-0 items-center gap-2" aria-label="iPop360 home">
                    <BrandLogo
                        class="text-[2.25rem]"
                        :class="props.transparent ? 'text-white' : undefined"
                    />
                    <Badge
                        variant="outline"
                        class="hidden text-xs sm:inline-flex"
                        aria-hidden="true"
                        :class="props.transparent ? 'border-white/50 text-white' : undefined"
                    >Beta</Badge>
                </Link>

                <template v-if="!props.transparent">
                    <!-- Desktop: the two-part search inline -->
                    <div class="hidden min-w-0 flex-1 px-6 lg:block" data-testid="header-search">
                        <SiteSearch class="max-w-2xl" />
                    </div>

                    <!-- Phone and tablet: a pill that opens a full-screen search -->
                    <Sheet v-model:open="mobileSearchOpen">
                        <SheetTrigger as-child>
                            <button
                                type="button"
                                class="mx-3 flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-full border border-border bg-background px-4 text-left text-[15px] text-muted-foreground shadow-sm transition-colors hover:bg-accent/60 lg:hidden"
                                data-testid="header-search-pill"
                            >
                                <Search class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                                <span class="truncate">Search restaurants</span>
                            </button>
                        </SheetTrigger>
                        <SheetContent side="top" class="p-0 pt-[env(safe-area-inset-top)]" :show-close-button="false">
                            <div class="flex items-center justify-between px-4 pt-3">
                                <SheetTitle class="font-heading text-base">Search restaurants</SheetTitle>
                                <button
                                    type="button"
                                    class="flex h-11 w-11 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    aria-label="Close search"
                                    @click="mobileSearchOpen = false"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <SheetDescription class="sr-only">Pick a cuisine and a place, then search</SheetDescription>
                            <div class="px-4 pb-5 pt-2">
                                <SiteSearch stacked @searched="mobileSearchOpen = false" />
                            </div>
                        </SheetContent>
                    </Sheet>
                </template>

                <!-- Desktop links -->
                <div class="hidden shrink-0 items-center gap-4 md:flex">
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
                        <nav aria-label="Menu" class="flex flex-col gap-1 px-3 py-3" data-testid="mobile-menu">
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
