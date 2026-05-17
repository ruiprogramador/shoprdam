<script setup lang="ts">
import { computed } from 'vue'
import { usePage }  from '@inertiajs/vue3'

interface DashboardCard { color: string; icon: string; count: number; label: string }

const page     = usePage()
const userName = computed(() => page.props.auth?.user?.name ?? 'there')

const dashboardCards: DashboardCard[] = [
    { color: 'blue',   icon: 'fa-solid fa-cart-shopping', count: 27, label: 'Total Order'   },
    { color: 'red',    icon: 'fa-solid fa-xmark',         count: 24, label: 'Cancel Order'  },
    { color: 'orange', icon: 'fa-solid fa-spinner',       count: 30, label: 'Pending Order' },
    { color: 'green',  icon: 'fa-solid fa-check-circle', count: 30, label: 'Completed'     },
    { color: 'pink',   icon: 'fa-solid fa-star',          count: 30, label: 'Reviews'       },
    { color: 'purple', icon: 'fa-solid fa-heart',         count: 30, label: 'Wishlist'      },
]
</script>

<template>
    <div>
        <div class="card">
            <div class="card-header p-0 pb-10">
                <h3 class="mb-0">Hello {{ userName }}!</h3>
            </div>
            <div class="card-body p-0">
                <p>
                    From your account dashboard you can check your
                    <a href="#">recent orders</a>,
                    manage your <a href="#">shipping and billing addresses</a> and
                    <a href="#">edit your password and account details</a>.
                </p>
            </div>
        </div>

        <div class="row">
            <div v-for="(card, index) in dashboardCards" :key="index" class="col-lg-4 col-sm-6">
                <div class="dashboard_card" :class="card.color">
                    <span><i :class="card.icon"></i></span>
                    <h3>{{ card.count }}</h3>
                    <p>{{ card.label }}</p>
                </div>
            </div>
        </div>
    </div>
</template>