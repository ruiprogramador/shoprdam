<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'

defineProps({
    isVisible: { type: Boolean, default: false }
})
defineEmits(['close'])

const searchQuery = ref('')
const handleSearch = () => {
    if (searchQuery.value.trim()) {
        router.get(route('shop.index'), { q: searchQuery.value })
    }
}
</script>

<template>
    <div class="mobile-header-active mobile-header-wrapper-style" :class="{ 'sidebar-visible': isVisible }">
        <div class="mobile-header-wrapper-inner">
            <div class="mobile-header-top">
                <div class="mobile-header-logo">
                    <Link :href="route('home')">
                        <img src="img/logo.png" alt="logo" />
                    </Link>
                </div>
                <div class="mobile-menu-close close-style-wrap close-style-position-inherit">
                    <button class="close-style search-close" @click="$emit('close')" type="button" aria-label="Fechar menu">
                        <i class="icon-top"></i>
                        <i class="icon-bottom"></i>
                    </button>
                </div>
            </div>

            <div class="mobile-header-content-area">
                <div class="mobile-search search-style-3 mobile-header-border">
                    <form @submit.prevent="handleSearch">
                        <input v-model="searchQuery" type="text" placeholder="Search for items…" />
                        <button type="submit"><i class="fa fa-search"></i></button>
                    </form>
                </div>

                <div class="mobile-menu-wrap mobile-header-border">
                    <nav>
                        <ul class="mobile-menu font-heading">
                            <li><Link :href="route('home')">Home</Link></li>
                            <li><a href="#">Shop</a></li>         <!-- toDo: route('shop.index') -->
                            <li><a href="#">Vendors</a></li>      <!-- toDo: route('vendors.index') -->
                            <li><a href="#">Blog</a></li>         <!-- toDo: route('blog.index') -->
                            <li><a href="#">Contact</a></li>      <!-- toDo: route('contact') -->
                        </ul>
                    </nav>
                </div>

                <div class="mobile-header-info-wrap">
                    <div class="single-mobile-header-info">
                        <a href="#"><i class="fa fa-map-marker-alt"></i>Our location</a> <!-- toDo: route('contact') -->
                    </div>
                    <div class="single-mobile-header-info">
                        <Link :href="route('login')"><i class="fa fa-user"></i> Log In / Sign Up</Link>
                    </div>
                </div>

                <div class="site-copyright">Copyright 2026 © ShopRDAM. All rights reserved.</div>
            </div>
        </div>
    </div>
</template>