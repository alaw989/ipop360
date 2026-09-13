<script setup lang="ts">
import { computed } from 'vue';
import { Search } from '@lucide/vue';

// The two-part search bar (what + where + search button) shared by the header
// and the home page hero. It only lays the fields out; the pickers go in the
// `what` and `where` slots and the owner handles `submit`.
//   row:        one line (the desktop header)
//   stacked:    fields over each other, full-width button (the phone search sheet)
//   responsive: stacked on phones, one line from 640px up (the hero)
const props = withDefaults(defineProps<{
    size?: 'md' | 'lg';
    layout?: 'row' | 'stacked' | 'responsive';
    busy?: boolean;
}>(), {
    size: 'md',
    layout: 'row',
    busy: false,
});

defineEmits<{ submit: [] }>();

const classes = computed(() => {
    const lg = props.size === 'lg';
    switch (props.layout) {
        case 'stacked':
            return {
                form: 'flex flex-col',
                divider: 'mx-4 h-px',
                action: 'p-2 pt-1',
                button: 'h-12 w-full rounded-lg text-base',
                label: '',
            };
        case 'responsive':
            return {
                form: 'flex flex-col sm:flex-row sm:items-stretch',
                divider: 'mx-4 h-px sm:mx-0 sm:my-3 sm:h-auto sm:w-px sm:self-stretch',
                action: 'p-2 pt-1 sm:flex sm:p-0',
                button: 'h-12 w-full rounded-lg text-base sm:h-auto sm:w-auto sm:rounded-none sm:px-7',
                label: '',
            };
        default:
            return {
                form: 'flex items-stretch',
                divider: 'my-2.5 w-px self-stretch',
                action: 'flex',
                button: lg ? 'min-w-[3.75rem] px-6 text-base' : 'w-12',
                label: lg ? '' : 'sr-only',
            };
    }
});
</script>

<template>
    <form
        role="search"
        aria-label="Search restaurants"
        class="overflow-hidden rounded-xl border border-border bg-background text-foreground shadow-sm"
        :class="classes.form"
        @submit.prevent="$emit('submit')"
    >
        <div class="min-w-0 flex-1" data-testid="search-what">
            <slot name="what" />
        </div>
        <div aria-hidden="true" class="bg-border" :class="classes.divider" />
        <div class="min-w-0 flex-1" data-testid="search-where">
            <slot name="where" />
        </div>
        <div :class="classes.action">
            <button
                type="submit"
                :disabled="busy"
                data-testid="search-submit"
                class="inline-flex items-center justify-center gap-2 bg-primary font-semibold text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-70"
                :class="classes.button"
            >
                <Search class="h-5 w-5" aria-hidden="true" />
                <span :class="classes.label">Search</span>
            </button>
        </div>
    </form>
</template>
