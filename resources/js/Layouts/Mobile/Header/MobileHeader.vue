<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'

defineProps({
  isVisible: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close'])
const searchQuery = ref('')

// toDo
/* const handleSearch = () => {
  router.get(route('shop.index'), { q: searchQuery.value })
  emit('close')
} */
</script>

<template>
    <div class="mobile-header-active mobile-header-wrapper-style" :class="{ 'sidebar-visible': isVisible }">
        <div class="mobile-header-wrapper-inner">
            <div class="mobile-header-top">
                <div class="mobile-header-logo">
                    <Link :href="route('home')">
                        <img src="/assets/imgs/theme/logo.svg" alt="logo" />
                    </Link>
                </div>
                <div class="mobile-menu-close close-style-wrap close-style-position-inherit">
                    <button class="close-style search-close" @click="$emit('close')">
                        <i class="icon-top"></i>
                        <i class="icon-bottom"></i>
                    </button>
                </div>
            </div>

            <div class="mobile-header-content-area">
                <div class="mobile-search search-style-3 mobile-header-border">
                    <form @submit.prevent="handleSearch">
                        <input v-model="searchQuery" type="text" placeholder="Search for items…" />
                        <button type="submit"><i class="fi-rs-search"></i></button>
                    </form>
                </div>

                <div class="mobile-menu-wrap mobile-header-border">
                <nav>
                    <ul class="mobile-menu font-heading">
                        <li>Home</li>
                        <li>Shop</li>
                        <li>Vendors</li>
                        <li>Blog</li>
                        <li>Contact</li>

                        <!-- toDo -->
                        <!-- <li><Link :href="route('home')">Home</Link></li>
                        <li><Link :href="route('shop.index')">Shop</Link></li>
                        <li><Link :href="route('vendors.index')">Vendors</Link></li>
                        <li><Link :href="route('blog.index')">Blog</Link></li>
                        <li><Link :href="route('contact')">Contact</Link></li> -->
                    </ul>
                </nav>
                </div>

                <div class="mobile-header-info-wrap">
                    <div class="single-mobile-header-info">
                        <!-- toDo -->
                        <!-- <Link :href="route('contact')"><i class="fi-rs-marker"></i> Our location</Link> -->
                    </div>
                    <div class="single-mobile-header-info">~
                        <!-- toDo -->
                        <!-- <Link :href="route('login')"><i class="fi-rs-user"></i>Log In / Sign Up</Link> -->
                    </div>
                </div>

                <div class="site-copyright">Copyright 2025 © ShopRDAM. All rights reserved.</div>
            </div>
        </div>
    </div>
</template>
