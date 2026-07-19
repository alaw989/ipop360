<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrandLogo from '@/Components/BrandLogo.vue';
import AppFooter from '@/Components/AppFooter.vue';
import { Badge } from '@/components/ui/badge';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <nav class="border-b border-border bg-card/80 backdrop-blur-sm sticky top-0 z-50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <Link href="/" class="flex items-center gap-2" aria-label="iPop360 home">
                        <BrandLogo class="text-[2.25rem]" />
                        <Badge variant="outline" class="text-xs">Beta</Badge>
                    </Link>

                    <div class="flex items-center gap-4">
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
                            v-else
                            href="/login"
                            class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Login
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <main class="flex-1">
            <slot />
        </main>

        <AppFooter />

    </div>
</template>
