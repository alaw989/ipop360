import { ref } from 'vue'

export function useImageFallback() {
    const failed = ref(false)

    function markFailed() {
        failed.value = true
    }

    function reset() {
        failed.value = false
    }

    return { failed, markFailed, reset }
}
