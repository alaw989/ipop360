<script setup lang="ts">
import { ref, computed } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet'
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
}>()

const emit = defineEmits<{
    select: [payload: { category: string; cuisine?: string; label: string }]
}>()

const { isMobile } = useIsMobile()

const open = ref(false)
const drillCategory = ref<Category | null>(null)
const selectedLabel = ref<string | null>(null)

const displayText = computed(() => selectedLabel.value ?? 'any cuisine')

function selectCategory(cat: Category) {
    drillCategory.value = cat
}

function selectCuisine(cuisine: Cuisine) {
    const cat = drillCategory.value!
    selectedLabel.value = `${cat.name} ▸ ${cuisine.name}`
    open.value = false
    drillCategory.value = null
    emit('select', {
        category: cat.slug,
        cuisine: cuisine.slug,
        label: selectedLabel.value!,
    })
}

function confirmCategory(cat: Category) {
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

const triggerClasses = computed(() => [
    'inline-flex items-center gap-1 border-b-2 px-1 font-semibold transition-colors focus:outline-none',
    props.inverted
        ? 'border-white/30 text-white/70 hover:border-white hover:text-white'
        : 'border-foreground/30 text-foreground hover:border-foreground',
    { 'opacity-60': !selectedLabel.value },
])
</script>

<template>
    <!-- Mobile: bottom sheet -->
    <Sheet v-if="isMobile" v-model:open="open">
        <SheetTrigger as-child>
            <button :class="triggerClasses">
                {{ displayText }}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </SheetTrigger>
        <SheetContent side="bottom" class="h-[85vh] p-0" :show-close-button="false" @open-auto-focus.prevent>
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
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
            <Command v-if="!drillCategory" class="flex flex-1 flex-col">
                <CommandInput placeholder="Search cuisines..." :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>No categories found.</CommandEmpty>
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
                            @select="confirmCategory(drillCategory!)"
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
            <button :class="triggerClasses">
                {{ displayText }}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 p-0 max-md:w-[calc(100vw-1rem)]" align="center">
            <Command v-if="!drillCategory">
                <CommandInput placeholder="Search cuisines..." :autoFocus="false" />
                <CommandList>
                    <CommandEmpty>No categories found.</CommandEmpty>
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
                            @select="confirmCategory(drillCategory!)"
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
