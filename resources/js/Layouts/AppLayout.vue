<script setup lang="ts">
import TopNav from '@/Components/TopNav.vue';
import AppFooter from '@/Components/AppFooter.vue';
import BottomTabBar from '@/Components/BottomTabBar.vue';

interface Props {
    // The home page overlays its transparent, non-sticky nav on the hero.
    navTransparent?: boolean;
    navSticky?: boolean;
}

withDefaults(defineProps<Props>(), {
    navTransparent: false,
    navSticky: true,
});
</script>

<template>
    <!-- relative so the transparent hero nav and any absolutely-positioned
         page banner anchor to the page, not the viewport. -->
    <div class="relative flex min-h-dvh flex-col bg-background">
        <TopNav :transparent="navTransparent ?? false" :sticky="navSticky ?? true" />

        <main class="flex-1">
            <slot />
        </main>

        <AppFooter />

        <BottomTabBar />
    </div>
</template>
