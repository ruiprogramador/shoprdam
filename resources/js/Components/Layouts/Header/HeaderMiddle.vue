<script setup>
import { Link }        from '@inertiajs/vue3'
import SearchBar       from '@/Components/Layouts/Header/SearchBar.vue'
import CompareIcon     from '@/Components/Layouts/Header/CompareIcon.vue'
import WishlistIcon    from '@/Components/Layouts/Header/WishlistIcon.vue'
import CartDropdown    from '@/Components/Layouts/Header/CartDropdown.vue'
import AccountDropdown from '@/Components/Layouts/Header/AccountDropdown.vue'
</script>

<template>
    <div class="header-middle header-middle-ptb-1 d-none d-xl-block">
        <div class="container">
            <div class="header-wrap">
                <div class="header-left logo logo-width-1">
                    <Link :href="route('home')">
                        <img src="/img/logo.png" alt="logo" />
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