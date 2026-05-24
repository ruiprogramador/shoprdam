<script setup lang="ts">
/**
 * RightFilterSidebar — shell genérico
 *
 * Responsabilidade: layout, trigger, open/close, backdrop.
 * Os filtros concretos são passados via <slot />, cada view monta o seu.
 *
 * Props:
 *   - accentColor : cor CSS (hex/rgb/var) usada no trigger e no header. Default azul.
 *   - icon        : emoji ou string curta para o header.
 *   - label       : título do painel (ex: "KYC Filters").
 *   - badgeCount  : número de filtros activos para mostrar no badge.
 */

/**
 * RightFilterSidebar
 *
 * Em mobile:
 *   — Ao abrir a sidebar de filtros → fecha o menu principal (via useLayoutStateInject)
 *   — O menu principal ao abrir → fecha esta sidebar
 *     (esse lado é tratado no HeaderNavbar via toggleSidebar que emite ao VerticalLayout)
 *
 * O componente não depende do inject para funcionar — se usado fora de um
 * VerticalLayout, funciona normalmente sem fechar nenhum menu.
 */
import { ref, watch } from 'vue'
import { useLayoutStateInject } from '@/Composables/useLayoutState'
 
const props = withDefaults(defineProps<{
    accentColor?: string
    icon?: string
    label?: string
    badgeCount?: number
}>(), {
    accentColor: '#3B82F6',
    icon: '🔍',
    label: 'Filters',
    badgeCount: 0,
})
 
const isOpen = ref(false)
const layout = useLayoutStateInject()
 
const open  = () => {
    isOpen.value = true
    if (layout?.isMobile?.value) layout.closeSidebar()
}
const close  = () => { isOpen.value = false }
const toggle = () => { isOpen.value ? close() : open() }
 
watch(
    () => layout?.sidebarOpen?.value,
    (menuOpen) => {
        if (menuOpen && layout?.isMobile?.value && isOpen.value) close()
    }
)
 
defineExpose({ open, close, toggle, isOpen })
</script>
 
<template>
    <div class="rsb-wrapper">
 
        <!-- Trigger — escondido quando o painel está aberto em mobile -->
        <button
            class="rsb-trigger"
            :class="{ 'rsb-trigger--hidden': isOpen }"
            :style="{ '--accent': accentColor }"
            :title="isOpen ? 'Close filters' : 'Open filters'"
            @click="toggle"
        >
            <span class="rsb-trigger-icon">
                <svg v-if="!isOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21 21 17.25"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </span>
            <span v-if="!isOpen && badgeCount > 0" class="rsb-badge">{{ badgeCount }}</span>
        </button>
 
        <!-- Panel -->
        <Transition name="rsb-slide">
            <aside v-if="isOpen" class="rsb-panel" :style="{ '--accent': accentColor }">
 
                <div class="rsb-header">
                    <div class="rsb-header-left">
                        <span class="rsb-context-icon">{{ icon }}</span>
                        <div>
                            <p class="rsb-label-tiny">Filters</p>
                            <h3 class="rsb-title">{{ label }}</h3>
                        </div>
                    </div>
                    <button class="rsb-close-btn" @click="close">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
 
                <div class="rsb-body">
                    <slot :close="close" />
                </div>
 
            </aside>
        </Transition>
 
        <!-- Backdrop -->
        <Transition name="rsb-fade">
            <div v-if="isOpen" class="rsb-backdrop" @click="close" />
        </Transition>
    </div>
</template>