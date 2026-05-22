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

import { ref } from 'vue'

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

const open  = () => { isOpen.value = true }
const close = () => { isOpen.value = false }
const toggle = () => { isOpen.value = !isOpen.value }

defineExpose({ open, close, toggle, isOpen })
</script>

<template>
    <div class="rsb-wrapper">

        <!-- ── Trigger button ── -->
        <button
            class="rsb-trigger"
            :style="{ '--accent': accentColor }"
            :title="isOpen ? 'Close filters' : 'Open filters'"
            @click="toggle"
        >
            <span class="rsb-trigger-icon">
                <!-- Filter icon -->
                <svg v-if="!isOpen" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21 21 17.25"/>
                </svg>
                <!-- Close icon -->
                <svg v-else xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </span>
            <span v-if="!isOpen && badgeCount > 0" class="rsb-badge">{{ badgeCount }}</span>
        </button>

        <!-- ── Panel ── -->
        <Transition name="rsb-slide">
            <aside v-if="isOpen" class="rsb-panel" :style="{ '--accent': accentColor }">

                <!-- Header -->
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

                <!-- Filter content injected per-view -->
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

<style scoped>
/* ── Wrapper ── */
.rsb-wrapper {
    position: fixed;
    top: 0; right: 0; bottom: 0;
    z-index: 40;
    pointer-events: none;
}
.rsb-wrapper > * { pointer-events: auto; }

/* ── Trigger ── */
.rsb-trigger {
    position: absolute;
    top: 50%; right: 0;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px; height: 56px;
    background: white;
    border: 1px solid #e5e7eb;
    border-right: none;
    border-radius: 10px 0 0 10px;
    box-shadow: -4px 0 16px rgba(0,0,0,.08);
    cursor: pointer;
    transition: all .2s ease;
    color: #6b7280;
}
.rsb-trigger:hover {
    background: var(--accent, #3B82F6);
    color: white;
    border-color: var(--accent, #3B82F6);
    box-shadow: -6px 0 24px color-mix(in srgb, var(--accent, #3B82F6) 40%, transparent);
}
.rsb-trigger-icon { width: 20px; height: 20px; display: flex; }
.rsb-trigger-icon svg { width: 20px; height: 20px; }

.rsb-badge {
    position: absolute;
    top: 8px; right: 6px;
    background: #EF4444;
    color: white;
    font-size: 10px; font-weight: 700;
    width: 16px; height: 16px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
}

/* ── Panel ── */
.rsb-panel {
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 320px;
    background: white;
    border-left: 1px solid #e5e7eb;
    box-shadow: -8px 0 40px rgba(0,0,0,.12);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Header ── */
.rsb-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 16px 16px;
    border-bottom: 1px solid #f3f4f6;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    flex-shrink: 0;
}
.rsb-header-left { display: flex; align-items: center; gap: 12px; }
.rsb-context-icon {
    font-size: 24px;
    width: 40px; height: 40px;
    background: white;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    border: 1px solid #e5e7eb;
}
.rsb-label-tiny {
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em;
    color: #9ca3af; line-height: 1; margin-bottom: 2px;
}
.rsb-title { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.2; }

.rsb-close-btn {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px; border: none; background: transparent;
    color: #9ca3af; cursor: pointer; transition: all .15s;
}
.rsb-close-btn:hover { background: #fee2e2; color: #ef4444; }
.rsb-close-btn svg { width: 16px; height: 16px; }

/* ── Body (scrollable) ── */
.rsb-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    scrollbar-width: thin;
    scrollbar-color: #e5e7eb transparent;
}

/* ── Transitions ── */
.rsb-slide-enter-active { transition: transform .28s cubic-bezier(.4,0,.2,1); }
.rsb-slide-leave-active { transition: transform .22s cubic-bezier(.4,0,1,1); }
.rsb-slide-enter-from,
.rsb-slide-leave-to { transform: translateX(100%); }

.rsb-fade-enter-active { transition: opacity .25s ease; }
.rsb-fade-leave-active { transition: opacity .2s ease; }
.rsb-fade-enter-from,
.rsb-fade-leave-to { opacity: 0; }

/* ── Backdrop ── */
.rsb-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.25);
    z-index: -1;
}
</style>