<script setup>
import { Link }            from '@inertiajs/vue3'
import CategoryDrilldown    from '@/Components/CategoryDrilldown.vue'
import MainMenu            from '@/Components/Layouts/Header/MainMenu.vue'
import MobileHeaderActions from '@/Components/Layouts/Header/MobileHeaderActions.vue'

const emit = defineEmits(['toggle-mobile-menu'])
</script>

<template>
    <div class="header-bottom header-bottom-bg-color sticky-bar">
        <div class="container">
            <div class="header-wrap header-space-between position-relative">

                <!-- ① HAMBURGER — mobile esquerda (abaixo de xl) -->
                <div class="d-flex d-xl-none align-items-center">
                    <div
                        class="burger-icon burger-icon-white"
                        role="button"
                        aria-label="Abrir menu"
                        @click="emit('toggle-mobile-menu')"
                    >
                        <span class="burger-icon-top"></span>
                        <span class="burger-icon-mid"></span>
                        <span class="burger-icon-bottom"></span>
                    </div>
                </div>

                <!-- ② LOGO MOBILE — centro absoluto (abaixo de xl) -->
                <div
                    class="logo logo-width-1 d-block d-xl-none"
                    style="position:absolute; left:50%; transform:translateX(-50%); z-index:1;"
                >
                    <Link :href="route('home')">
                        <img src="/img/logo.png" alt="ShopRDAM logo" />
                    </Link>
                </div>

                <!-- ③ MENU DESKTOP — esquerda (xl+) -->
                <div class="header-nav d-none d-xl-flex" style="gap: 16px; align-items: center;">
                    <div class="main-categori-wrap d-none d-xl-block" style="display:flex !important; align-items:center;">
                        <i class="fa fa-list me-2" style="color:#fff; flex-shrink:0;"></i>
                        <CategoryDrilldown
                            :categories="categories"
                            placeholder="Trending Categories"
                            v-model="selectedCategory"
                        />
                    </div>
                    <MainMenu />
                </div>

                <!-- ④ HOTLINE DESKTOP — direita (xl+) -->
                <div class="hotline d-none d-xl-flex align-items-center gap-2">
                    <i class="fa fa-headphones" style="font-size:20px;"></i>
                    <p>0000-000<span>24/7 Support Center</span></p>
                </div>

                <!-- ⑤ ÍCONES MOBILE — direita (abaixo de xl) -->
                <MobileHeaderActions class="d-block d-xl-none" />

            </div>
        </div>
    </div>
</template>