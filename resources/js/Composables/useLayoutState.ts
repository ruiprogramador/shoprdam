/**
 * useLayoutState
 *
 * Partilha o estado do menu principal entre o VerticalLayout (que o controla)
 * e qualquer componente filho (ex: RightFilterSidebar) que precise de o fechar/ler.
 *
 * Uso:
 *   — VerticalLayout.vue  : provide('layoutState', useLayoutState())
 *   — RightFilterSidebar  : const { closeSidebar } = useLayoutStateInject()
 *
 * Alternativa sem provide/inject: passar closeSidebar como prop ao RightFilterSidebar
 * e chamar @open="closeSidebar" no Index.vue. Mais simples se já passas props.
 */

import { inject, type Ref } from 'vue'

export const LAYOUT_STATE_KEY = Symbol('layoutState')

export interface LayoutState {
    sidebarOpen:   Ref<boolean>
    isMobile:      Ref<boolean>
    closeSidebar:  () => void
    toggleSidebar: (value?: boolean) => void
}

/**
 * Injeta o estado do layout.
 * Retorna null se usado fora de um VerticalLayout (seguro — não lança erro).
 */
export function useLayoutStateInject(): LayoutState | null {
    return inject<LayoutState>(LAYOUT_STATE_KEY, null as any)
}