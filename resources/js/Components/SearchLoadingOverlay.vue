<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import BrandLogo from '@/Components/BrandLogo.vue'

const MESSAGES = [
    'Fetching the freshest listings…',
    'Checking who\'s actually open right now…',
    'Cross-referencing menus, hours, and reviews…',
    'Making sure the ratings aren\'t fake…',
    'Filtering out anything that isn\'t actually food…',
    'Scouting the neighborhood for hidden gems…',
    'Double-checking distance and directions…',
    'Sorting by how good the food really is…',
]

const messageIndex = ref(0)
const ROTATE_MS = 1800

let timer: ReturnType<typeof setInterval> | null = null

function startTimer() {
    timer = setInterval(() => {
        messageIndex.value = (messageIndex.value + 1) % MESSAGES.length
    }, ROTATE_MS)
}

function stopTimer() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

onMounted(() => {
    startTimer()
})

onUnmounted(() => {
    stopTimer()
})
</script>

<template>
    <div class="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-6 bg-background">
        <BrandLogo class="text-4xl" />

        <!-- `.spinner-enter` (entrance pop) wraps the ring so it does NOT share an
             element with `animate-spin` — both set the `animation` shorthand, so on
             one node only one survives (spec-044 regression). -->
        <span class="spinner-enter">
            <span class="inline-block h-20 w-20 animate-spin rounded-full border-4 border-primary border-t-transparent" />
        </span>

        <div class="message-fade-wrap relative w-full max-w-md text-center">
            <Transition name="message-fade">
                <p
                    :key="messageIndex"
                    role="status"
                    aria-live="polite"
                    class="px-4 text-base text-muted-foreground"
                >
                    {{ MESSAGES[messageIndex] }}
                </p>
            </Transition>
        </div>
    </div>
</template>
