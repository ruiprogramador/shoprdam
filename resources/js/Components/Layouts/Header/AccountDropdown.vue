<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page            = usePage()
const isAuthenticated = computed(() => !!page.props.auth?.user)

const logout = () => {
    router.post(route('logout'), {}, { preserveScroll: false })
}
</script>

<template>
    <div class="header-action-icon-2">
        <div class="dropdown-content-wrap">
            <div class="dropdown-icon-toggle">
                <i class="fa fa-user"></i>
            </div>
            <div class="lable ml-0 dropdown-icon-text">Account</div>
        </div>

        <div class="cart-dropdown-wrap cart-dropdown-hm2 account-dropdown">
            <ul v-if="isAuthenticated">
                <li><Link href="/account"><i class="fa fa-user mr-10"></i>My Account</Link></li>
                <li><Link href="/order-tracking"><i class="fa fa-map-marker mr-10"></i>Order Tracking</Link></li>
                <li><Link href="/vouchers"><i class="fa fa-gift mr-10"></i>My Vouchers</Link></li>
                <li><Link href="/wishlist"><i class="fa fa-heart mr-10"></i>My Wishlist</Link></li>
                <li><Link href="/settings"><i class="fa fa-cog mr-10"></i>Settings</Link></li>
                <li>
                    <a href="#" @click.prevent="logout">
                        <i class="fa fa-sign-out mr-10"></i>Logout
                    </a>
                </li>
            </ul>
            <ul v-else>
                <li><Link href="/login"><i class="fa fa-sign-in mr-10"></i>Login</Link></li>
                <li><Link href="/register"><i class="fa fa-user-plus mr-10"></i>Register</Link></li>
            </ul>
        </div>
    </div>
</template>