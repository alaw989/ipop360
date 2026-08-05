<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <footer class="border-t border-border bg-muted/40 py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                <div class="text-center sm:text-left">
                    <h3 class="text-lg font-semibold text-foreground">iPop360</h3>
                    <p class="text-sm text-muted-foreground">Discover great restaurants near you.</p>
                </div>
                <nav class="flex flex-wrap items-center justify-center gap-4 text-sm sm:justify-end">
                    <Link href="/" class="text-muted-foreground hover:text-foreground transition-colors">
                        Home
                    </Link>
                    <Link href="/restaurants" class="text-muted-foreground hover:text-foreground transition-colors">
                        Browse
                    </Link>
                    <Link href="/leaderboard" class="text-muted-foreground hover:text-foreground transition-colors">
                        Leaderboard
                    </Link>
                    <Link href="/blog" class="text-muted-foreground hover:text-foreground transition-colors">
                        Blog
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/favorites"
                        class="text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Favorites
                    </Link>
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/logout"
                        method="post"
                        class="text-muted-foreground hover:text-foreground transition-colors"
                        as="button"
                    >
                        Logout
                    </Link>
                    <Link
                        v-else
                        href="/login"
                        class="text-muted-foreground hover:text-foreground transition-colors"
                    >
                        Login
                    </Link>
                </nav>
            </div>
            <div class="mt-6 text-center text-xs text-muted-foreground space-y-1">
                <p>&copy; {{ new Date().getFullYear() }} iPop360. All rights reserved.</p>
                <p>
                    <a
                        href="https://lp.similarweb.com/competitive-analysis-youtube/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hover:text-foreground transition-colors"
                    >
                        Competitive analysis by Similarweb
                    </a>
                </p>
            </div>
        </div>
    </footer>
</template>
