<script setup>
import { Link } from '@inertiajs/vue3'
import SearchBar from './SearchBar.vue'
import CompareIcon from './CompareIcon.vue'
import WishlistIcon from './WishlistIcon.vue'
import CartDropdown from './CartDropdown.vue'
import AccountDropdown from './AccountDropdown.vue'
</script>

<template>
    <div class="header-middle header-middle-ptb-1 d-none d-lg-block">
        <div class="container">
            <div class="header-wrap">
                <div class="logo logo-width-1">
                    <Link :href="route('home')">
                        <img src="/assets/imgs/theme/logo.svg" alt="logo" />
                    </Link>
                </div>

                <div class="header-right">
                    <SearchBar />

                    <div class="header-action-right">
                        <div class="header-action-2">
                            <CompareIcon />
                            <WishlistIcon />
                            <CartDropdown />
                            <AccountDropdown />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
