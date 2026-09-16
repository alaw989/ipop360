<script setup lang="ts">
import { ref, computed } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Sheet, SheetContent, SheetTrigger, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command'
import { useIsMobile } from '@/composables/useIsMobile'

interface Cuisine {
    id: number
    name: string
    slug: string
    icon: string | null
}

interface Category {
    id: number
    name: string
    slug: string
    icon: string | null
    cuisines: Cuisine[]
}

const props = defineProps<{
    categories: Category[]
    inverted?: boolean
    // 'field' renders the trigger as one half of the two-part search bar
    // (SearchBarShell): a small "What" label over the current value.
    variant?: 'inline' | 'field'
    fieldLabel?: string
    size?: 'md' | 'lg'
    initialLabel?: string | null
    loading?: boolean
}>()

const emit = defineEmits<{
    select: [payload: { category: string; cuisine?: string; label: string }]
}>()

const { isMobile } = useIsMobile()

const open = ref(false)
const drillCategory = ref<Category | null>(null)
const selectedLabel = ref<string | null>(props.initialLabel ?? null)

const isField = computed(() => props.variant === 'field')

// The field shows just the cuisine ("Japanese"), not "Asian ▸ Japanese".
const displayText = computed(() => {
    if (!isField.value) return selectedLabel.value ?? 'any cuisine'
    return selectedLabel.value?.split(' ▸ ').pop() ?? 'Any cuisine'
})

const emptyText = computed(() => (props.loading ? 'Loading cuisines…' : 'No categories found.'))

function selectCategory(cat: Category) {
    drillCategory.value = cat
}

function selectCuisine(cuisine: Cuisine) {
    const cat = drillCategory.value
    if (!cat) return
    selectedLabel.value = `${cat.name} ▸ ${cuisine.name}`
    open.value = false
    drillCategory.value = null
    emit('select', {
        category: cat.slug,
        cuisine: cuisine.slug,
        label: selectedLabel.value!,
    })
}

function confirmCategory() {
    const cat = drillCategory.value
    if (!cat) return
    selectedLabel.value = cat.name
    open.value = false
    drillCategory.value = null
    emit('select', {
        category: cat.slug,
        label: selectedLabel.value!,
    })
}

function goBack() {
    drillCategory.value = null
}

function clearSelection() {
    selectedLabel.value = null
    drillCategory.value = null
    open.value = false
    emit('select', { category: '', label: 'any cuisine' })
}

const triggerClasses = computed(() => isField.value
    ? [
        'flex h-full w-full flex-col items-start justify-center text-left transition-colors hover:bg-accent/60 focus:outline-none focus-visible:bg-accent/60',
        props.size === 'lg' ? 'min-h-[3.75rem] px-5' : 'min-h-11 px-4',
    ]
    : [
        'inline-flex items-center gap-1 border-b-2 px-1 font-semibold transition-colors focus:outline-none',
        props.inverted
            ? 'border-white/30 text-white/70 hover:border-white hover:text-white'
            : 'border-foreground/30 text-foreground hover:border-foreground',
        { 'opacity-60': !selectedLabel.value },
    ])

defineExpose({ selectCuisine, confirmCategory })
</script>

<template>
    <!-- Mobile: bottom sheet -->
    <Sheet v-if="isMobile" v-model:open="open">
        <SheetTrigger as-child>
            <button type="button" :class="triggerClasses" data-testid="cuisine-trigger">
                <template v-if="isField">
                    <span class="text-xs font-semibold text-foreground">{{ fieldLabel ?? 'What' }}</span>
                    <span
                        class="w-full truncate"
                        :class="[size === 'lg' ? 'text-base' : 'text-[15px]', selectedLabel ? 'text-foreground' : 'text-muted-foreground']"
                    >{{ displayText }}</span>
                </template>
                <template v-else>
                    {{ displayText }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </template>
            </button>
        </SheetTrigger>
        <SheetContent side="bottom" class="h-[85dvh] p-0 pb-[env(safe-area-inset-bottom)]" :show-close-button="false" @open-auto-focus.prevent>
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <SheetTitle class="text-sm">Choose a cuisine</SheetTitle>
                <div class="mx-auto h-1 w-10 rounded-full bg-muted-foreground/30" />
                <button
                    class="flex h-7 w-7 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                    @click="open = false"
                    aria-label="Close"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <SheetDescription class="sr-only">Choose a cuisine from the list</SheetDescription>
            <Command v-if="!drillCategory" class="flex flex-1 flex-col">
                <CommandInput placeholder="Search cuisines..." :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup heading="Categories">
                        <CommandItem
                            v-for="cat in categories"
                            :key="cat.id"
                            :value="cat.name"
                            @select="selectCategory(cat)"
                        >
                            <span class="mr-2">{{ cat.icon }}</span>
                            <span class="flex-1">{{ cat.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ cat.cuisines.length }}</span>
                        </CommandItem>
                    </CommandGroup>
                    <CommandGroup v-if="selectedLabel">
                        <CommandItem value="__clear" @select="clearSelection" class="text-muted-foreground">
                            ✕ Clear selection
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
    <Command class="flex flex-1 flex-col" v-else>
                <CommandInput :placeholder="`Search ${drillCategory.name} cuisines...`" :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>No cuisines found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem value="__back" @select="goBack" class="text-muted-foreground">
                            ← Back to categories
                        </CommandItem>
                        <CommandItem
                            :value="`all ${drillCategory.name}`"
                            @select="confirmCategory"
                        >
                            <span class="mr-2">{{ drillCategory.icon }}</span>
                            <span class="font-medium">All {{ drillCategory.name }}</span>
                        </CommandItem>
                        <CommandItem
                            v-for="cuisine in drillCategory.cuisines"
                            :key="cuisine.id"
                            :value="cuisine.name"
                            @select="selectCuisine(cuisine)"
                        >
                            <span class="mr-2">{{ cuisine.icon || '•' }}</span>
                            {{ cuisine.name }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </SheetContent>
    </Sheet>

    <!-- Desktop: floating popover -->
    <Popover v-else v-model:open="open">
        <PopoverTrigger as-child>
            <button type="button" :class="triggerClasses" data-testid="cuisine-trigger">
                <template v-if="isField">
                    <span class="text-xs font-semibold text-foreground">{{ fieldLabel ?? 'What' }}</span>
                    <span
                        class="w-full truncate"
                        :class="[size === 'lg' ? 'text-base' : 'text-[15px]', selectedLabel ? 'text-foreground' : 'text-muted-foreground']"
                    >{{ displayText }}</span>
                </template>
                <template v-else>
                    {{ displayText }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </template>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 p-0" :align="isField ? 'start' : 'center'">
            <Command v-if="!drillCategory">
                <CommandInput placeholder="Search cuisines..." :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup heading="Categories">
                        <CommandItem
                            v-for="cat in categories"
                            :key="cat.id"
                            :value="cat.name"
                            @select="selectCategory(cat)"
                        >
                            <span class="mr-2">{{ cat.icon }}</span>
                            <span class="flex-1">{{ cat.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ cat.cuisines.length }}</span>
                        </CommandItem>
                    </CommandGroup>
                    <CommandGroup v-if="selectedLabel">
                        <CommandItem value="__clear" @select="clearSelection" class="text-muted-foreground">
                            ✕ Clear selection
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
            <Command v-else>
                <CommandInput :placeholder="`Search ${drillCategory.name} cuisines...`" :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>No cuisines found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem value="__back" @select="goBack" class="text-muted-foreground">
                            ← Back to categories
                        </CommandItem>
                        <CommandItem
                            :value="`all ${drillCategory.name}`"
                            @select="confirmCategory"
                        >
                            <span class="mr-2">{{ drillCategory.icon }}</span>
                            <span class="font-medium">All {{ drillCategory.name }}</span>
                        </CommandItem>
                        <CommandItem
                            v-for="cuisine in drillCategory.cuisines"
                            :key="cuisine.id"
                            :value="cuisine.name"
                            @select="selectCuisine(cuisine)"
                        >
                            <span class="mr-2">{{ cuisine.icon || '•' }}</span>
                            {{ cuisine.name }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
