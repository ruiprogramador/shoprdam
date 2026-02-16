
<script setup lang="ts">
    import { ref } from 'vue'

    interface DashboardCard {
        color: string
        icon: string
        count: number
        label: string
    }

    const userName = ref('Rosie')

    const dashboardCards = ref<DashboardCard[]>([
    {
        color: 'blue',
        icon: 'fa-solid fa-cart-shopping',
        count: 27,
        label: 'Total Order',
    },
    {
        color: 'red',
        icon: 'fa-solid fa-xmark',
        count: 24,
        label: 'Cancel Order',
    },
    {
        color: 'orange',
        icon: 'fa-solid fa-spinner',
        count: 30,
        label: 'Pending Order',
    },
    {
        color: 'green',
        icon: 'fi-rs-shopping-bag',
        count: 30,
        label: 'Total Order',
    },
    {
        color: 'pink',
        icon: 'fi-rs-shopping-bag',
        count: 30,
        label: 'Total Order',
    },
    {
        color: 'purple',
        icon: 'fi-rs-shopping-bag',
        count: 30,
        label: 'Total Order',
    },
    ])
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header p-0 pb-10">
                <h3 class="mb-0">Hello {{ userName }}!</h3>
            </div>
            <div class="card-body p-0">
                <p>
                    From your account dashboard. you can easily check &amp; view your
                    <a href="#">recent orders</a>,<br />
                    manage your <a href="#">shipping and billing addresses</a> and
                    <a href="#">edit your password and account details.</a>
                </p>
            </div>
        </div>

        <div class="row">
            <div
                v-for="(card, index) in dashboardCards"
                :key="index"
                class="col-lg-4 col-sm-6"
            >
                <div class="dashboard_card" :class="card.color">
                    <span><i :class="card.icon"></i></span>
                    <h3>{{ card.count }}</h3>
                    <p>{{ card.label }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
