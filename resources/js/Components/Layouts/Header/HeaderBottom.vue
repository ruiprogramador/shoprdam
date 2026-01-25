<script setup>

import { Link, router } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted, computed } from 'vue'

import CategoryMegaMenu from '@/Components/Layouts/Header/CategoryMegaMenu.vue'
import MainMenu from '@/Components/Layouts/Header/MainMenu.vue'
import MobileHeaderActions from '@/Components/Layouts/Header/MobileHeaderActions.vue'

/* import CategoryMegaMenu from './CategoryMegaMenu.vue'
import MainMenu from './MainMenu.vue'
import MobileHeaderActions from './MobileHeaderActions.vue'

export default {
  name: 'HeaderBottom',
  components: {
    CategoryMegaMenu,
    MainMenu,
    MobileHeaderActions
  },
  methods: {
    toggleMobileMenu() {
      this.$emit('toggle-mobile-menu')
    }
  }
} */
</script>
<template>
    <div class="header-bottom header-bottom-bg-color sticky-bar">
        <div class="container">
            <div class="header-wrap header-space-between position-relative">
                <div class="logo logo-width-1 d-block d-lg-none">
                    <Link href="/">
                        <img src="img/logo.png" alt="logo" />
                    </Link>
                </div>

                <div class="header-nav d-none d-lg-flex">
                    <div class="main-categori-wrap d-none d-lg-block">
                        <a class="categories-button-active" href="#">
                            <span class="fi-rs-apps"></span> Trending Categories
                            <i class="fi-rs-angle-down"></i>
                        </a>
                        <CategoryMegaMenu />
                    </div>

                    <MainMenu />
                </div>

                <div class="hotline d-none d-lg-flex">
                    <!-- <img src="/assets/imgs/theme/icons/icon-headphone-white.svg" alt="hotline" /> -->
                    <i class="fa fa-headphones"></i>
                    <p>0000-000<span>24/7 Support Center</span></p>
                </div>

                <div class="header-action-icon-2 d-block d-lg-none">
                    <div class="burger-icon burger-icon-white" @click="toggleMobileMenu">
                        <span class="burger-icon-top"></span>
                        <span class="burger-icon-mid"></span>
                        <span class="burger-icon-bottom"></span>
                    </div>
                </div>

                <MobileHeaderActions class="d-block d-lg-none" />
            </div>
        </div>
    </div>
</template>
