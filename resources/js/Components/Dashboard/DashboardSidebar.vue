<script setup lang="ts">

import { Link } from '@inertiajs/vue3';

const props = defineProps({
    activeTab: {
        type: String,
        required: true,
    },
});


const emit = defineEmits<{
    (e: 'tab-change', tab: string): void
}>()

const changeTab = (tab: string) => {
    emit('tab-change', tab)
}

const handleLogout = (event: Event) => {
    console.log('Logging out...')
}
</script>

<template>
    <div class="dashboard-menu">
        <ul class="nav flex-column" role="tablist">
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'dashboard' }"
                    href="#"
                    @click.prevent="changeTab('dashboard')"
                >
                    <i class="fa-solid fa-sliders mr-10"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'orders' }"
                    href="#"
                    @click.prevent="changeTab('orders')"
                >
                    <i class="fa-solid fa-truck-fast ml-10"></i>
                    <span>Orders</span>
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'track-orders' }"
                    href="#"
                    @click.prevent="changeTab('track-orders')"
                >
                    <i class="fa-solid fa-truck mr-10"></i>
                    <span>Track Your Order</span>
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'address' }"
                    href="#"
                    @click.prevent="changeTab('address')"
                >
                    <i class="fa-solid fa-marker mr-10"></i>
                    <span>My Address</span>
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'account-detail' }"
                    href="#"
                    @click.prevent="changeTab('account-detail')"
                >
                    <i class="fa-solid fa-user mr-10"></i>
                    <span>Account details</span>
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'wishlist-tab' }"
                    href="#"
                    @click.prevent="changeTab('wishlist-tab')"
                >
                    <i class="fa-solid fa-heart mr-10"></i>
                    <span>Wishlist</span>
                </a>
            </li>
            <li class="nav-item">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="nav-link"
                >
                    <i class="fa-solid fa-sign-out mr-10"></i>
                    <span>Logout</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
