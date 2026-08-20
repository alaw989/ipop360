import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Sheet from '@/components/ui/sheet/Sheet.vue'

export const mountInSheet = (
    Component: Parameters<typeof h>[0],
    props: Record<string, unknown> = {},
    slot = '',
) =>
    mount(Sheet, {
        slots: { default: () => h(Component, { ...props }, () => slot) },
    })
