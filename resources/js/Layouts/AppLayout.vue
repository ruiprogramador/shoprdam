
<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppHeader from '@/Layouts/AppHeader.vue'
import AppFooter from '@/Layouts/AppFooter.vue'
import MobileHeader from '@/Layouts/Mobile/Header/MobileHeader.vue'
import QuickViewModal from '@/Components/Product/QuickViewModal.vue'

const showMobileMenu = ref(false)
const showQuickView = ref(false)
const selectedProduct = ref(null)

const page = usePage()

const openQuickView = (product) => {
  selectedProduct.value = product
  showQuickView.value = true
}

const closeQuickView = () => {
  showQuickView.value = false
  selectedProduct.value = null
}
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
    <div id="preloader-active">
      <div class="preloader d-flex align-items-center justify-content-center">
        <div class="preloader-inner position-relative">
          <div class="text-center">
            <img src="/assets/imgs/theme/loading.gif" alt="Loading" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
