<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import AppHeader from '@/Layouts/AppHeader.vue'
import AppFooter from '@/Layouts/AppFooter.vue'
import MobileHeader from '@/Layouts/Mobile/Header/MobileHeader.vue'
import QuickViewModal from '@/Components/Product/QuickViewModal.vue'

const showMobileMenu  = ref(false)
const showQuickView   = ref(false)
const selectedProduct = ref(null)
const isLoading       = ref(true)

const openQuickView = (product) => {
    selectedProduct.value = product
    showQuickView.value   = true
}

const closeQuickView = () => {
    showQuickView.value   = false
    selectedProduct.value = null
}

// Preloader desaparece quando Inertia termina de carregar — não após tempo fixo
const onPageFinish = () => { isLoading.value = false }

let removeListener: () => void

onMounted(() => {
    removeListener = router.on('finish', onPageFinish)
    // Segurança: se já estiver carregada quando o componente monta
    isLoading.value = false
})
onUnmounted(() => removeListener?.())
</script>

<template>
    <div class="home-page">
        <QuickViewModal
            v-if="showQuickView"
            :product="selectedProduct"
            @close="closeQuickView"
        />

        <AppHeader @toggle-mobile-menu="showMobileMenu = !showMobileMenu" />
        <MobileHeader :is-visible="showMobileMenu" @close="showMobileMenu = false" />

        <slot />

        <AppFooter />

        <!-- Preloader -->
        <Transition name="fade">
            <div v-if="isLoading" id="preloader-active" class="fixed inset-0 z-[9999] flex items-center justify-center bg-white">
                <div class="text-center">
                    <img src="img/loading.gif" alt="Loading" class="mx-auto" />
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.fade-leave-active { transition: opacity 0.3s ease; }
.fade-leave-to    { opacity: 0; }
</style>