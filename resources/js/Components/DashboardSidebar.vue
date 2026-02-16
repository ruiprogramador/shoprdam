<script setup lang="ts">

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
                    <i class="fa-solid fa-sliders mr-10"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'orders' }"
                    href="#"
                    @click.prevent="changeTab('orders')"
                >
                    <i class="fi-rs-shopping-bag mr-10"></i>Orders
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'track-orders' }"
                    href="#"
                    @click.prevent="changeTab('track-orders')"
                >
                    <i class="fa-solid fa-truck mr-10"></i>Track Your Order
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'address' }"
                    href="#"
                    @click.prevent="changeTab('address')"
                >
                    <i class="fa-solid fa-marker mr-10"></i>My Address
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'account-detail' }"
                    href="#"
                    @click.prevent="changeTab('account-detail')"
                >
                    <i class="fa-solid fa-user mr-10"></i>Account details
                </a>
            </li>
            <li class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === 'wishlist-tab' }"
                    href="#"
                    @click.prevent="changeTab('wishlist-tab')"
                >
                    <i class="fa-solid fa-heart mr-10"></i> Wishlist
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="/login" @click="handleLogout">
                    <i class="fa-solid fa-sign-out mr-10"></i>Logout
                </a>
            </li>
        </ul>
    </div>
</template>
