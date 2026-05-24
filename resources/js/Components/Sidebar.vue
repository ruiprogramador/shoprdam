<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

defineProps({
    open:     { type: Boolean, default: true  },
    theme:    { type: String,  default: 'light' },
    isMobile: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
</script>

<template>
    <!-- ── OVERLAY (mobile only) ─────────────────────────── -->
    <Transition
        enter-active-class="transition-opacity duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="isMobile && open"
            class="fixed inset-0 z-40 bg-gray-900/60 backdrop-blur-sm"
            @click="emit('close')"
            aria-hidden="true"
        />
    </Transition>

    <!-- ── SIDEBAR PANEL ─────────────────────────────────── -->
    <!--
        MOBILE  → fixed drawer que desliza da esquerda (z-50)
        DESKTOP → bloco flex normal, altura total da área de conteúdo
                  NÃO é fixed nem sticky — faz parte do flow
    -->
    <Transition
        enter-active-class="transition-transform duration-300 ease-out"
        enter-from-class="-translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-200 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="-translate-x-full"
    >
        <aside
            v-show="open"
            class="
                flex flex-col w-64 bg-white border-r border-gray-200
                overflow-y-auto flex-shrink-0

                fixed top-0 left-0 z-50 shadow-lg
                lg:relative lg:top-auto lg:left-auto lg:z-auto lg:shadow-none
            "
            :aria-hidden="!open"
            aria-label="Navegação principal"
        >
            <!-- Logo + botão fechar (mobile) -->
            <div class="flex items-center justify-between h-14 sm:h-16 px-4 border-b border-gray-200 flex-shrink-0">
    
    <!-- Fantasma esquerda — mesma largura do botão para equilibrar -->
    <div class="w-10" />

    <!-- Logo centro -->
    <img src="/storage/img/logo.png" alt="Logo" class="h-12 w-auto" />

    <!-- X direita -->
    <SecondaryButton
        v-if="isMobile"
        aria-label="Fechar menu"
        @click="emit('close')"
    >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </SecondaryButton>

    <!-- Quando não é mobile, não mostra o X mas o fantasma ainda equilibra -->
    <div v-else class="w-10" />

</div>

            <!-- Links de navegação -->
            <nav class="flex-1 overflow-y-auto py-4 px-3">
                <div class="flex flex-col gap-1">
                    <slot name="vertical_menu" />
                </div>
            </nav>
        </aside>
    </Transition>
</template>