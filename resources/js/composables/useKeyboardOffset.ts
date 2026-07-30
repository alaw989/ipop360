import { ref, onMounted, onUnmounted } from 'vue'

export function useKeyboardOffset() {
  const keyboardHeight = ref(0)

  function update() {
    if (!window.visualViewport) {
      keyboardHeight.value = 0
      return
    }
    keyboardHeight.value = Math.max(
      0,
      window.innerHeight - window.visualViewport.height
    )
  }

  onMounted(() => {
    window.visualViewport?.addEventListener('resize', update)
    window.visualViewport?.addEventListener('scroll', update)
    update()
  })

  onUnmounted(() => {
    window.visualViewport?.removeEventListener('resize', update)
    window.visualViewport?.removeEventListener('scroll', update)
  })

  return { keyboardHeight }
}
