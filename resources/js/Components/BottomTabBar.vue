<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Search, UtensilsCrossed, Trophy, Heart, User } from '@lucide/vue'

/**
 * Persistent bottom navigation, the app-shell signature of every food app: a
 * fixed tab bar on phones (hidden at md and up, where TopNav's links take over).
 *
 * Auth-gated tabs (Saved, Account) are always shown; signed-out visitors are
 * sent to /login rather than having the tabs disappear.
 */
interface Tab {
    label: string
    href: string
    icon: unknown
    authOnly: boolean
    /** Path prefixes that light this tab. */
    matches: string[]
}

const tabs: Tab[] = [
    { label: 'Search', href: '/search', icon: Search, authOnly: false, matches: ['/search'] },
    { label: 'Browse', href: '/restaurants', icon: UtensilsCrossed, authOnly: false, matches: ['/restaurants'] },
    { label: 'Leaderboard', href: '/leaderboard', icon: Trophy, authOnly: false, matches: ['/leaderboard'] },
    { label: 'Saved', href: '/favorites', icon: Heart, authOnly: true, matches: ['/favorites'] },
    { label: 'Account', href: '/dashboard', icon: User, authOnly: true, matches: ['/dashboard'] },
]

const page = usePage()
const path = computed(() => (page.url ?? '/').split('?')[0] || '/')
const isAuthed = computed(() => !!page.props.auth?.user)

function hrefFor(tab: Tab): string {
    return tab.authOnly && !isAuthed.value ? '/login' : tab.href
}

function isActive(tab: Tab): boolean {
    return tab.matches.some((prefix) => path.value === prefix || path.value.startsWith(prefix + '/'))
}
</script>

<template>
    <nav
        aria-label="Primary"
        data-testid="bottom-tab-bar"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-card/95 backdrop-blur-sm pb-[env(safe-area-inset-bottom)] md:hidden"
    >
        <div class="mx-auto flex h-14 max-w-7xl items-stretch divide-x divide-border">
            <Link
                v-for="tab in tabs"
                :key="tab.label"
                :href="hrefFor(tab)"
                class="flex min-h-11 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[0.7rem] font-medium leading-none transition-colors"
                :class="isActive(tab) ? 'text-primary' : 'text-muted-foreground hover:text-foreground'"
                :aria-current="isActive(tab) ? 'page' : undefined"
            >
                <component :is="tab.icon" :size="20" aria-hidden="true" />
                <span>{{ tab.label }}</span>
            </Link>
        </div>
    </nav>
</template>
