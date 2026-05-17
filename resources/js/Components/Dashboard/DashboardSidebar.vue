<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

const props = defineProps<{ activeTab: string }>()
const emit  = defineEmits<{ (e: 'tab-change', tab: string): void }>()

const changeTab = (tab: string) => emit('tab-change', tab)

const navItems = [
    { id: 'dashboard',      icon: 'fa-solid fa-sliders',    label: 'Dashboard'         },
    { id: 'orders',         icon: 'fa-solid fa-truck-fast', label: 'Orders'            },
    { id: 'track-orders',   icon: 'fa-solid fa-truck',      label: 'Track Your Order'  },
    { id: 'address',        icon: 'fa-solid fa-marker',     label: 'My Address'        },
    { id: 'account-detail', icon: 'fa-solid fa-user',       label: 'Account details'   },
    { id: 'wishlist-tab',   icon: 'fa-solid fa-heart',      label: 'Wishlist'          },
]
</script>

<template>
    <div class="dashboard-menu">
        <ul class="nav flex-column" role="tablist">
            <li v-for="item in navItems" :key="item.id" class="nav-item">
                <a
                    class="nav-link"
                    :class="{ active: props.activeTab === item.id }"
                    href="#"
                    :aria-current="props.activeTab === item.id ? 'page' : undefined"
                    @click.prevent="changeTab(item.id)"
                >
                    <i :class="[item.icon, 'mr-10']"></i>
                    <span>{{ item.label }}</span>
                </a>
            </li>

            <li class="nav-item">
                <Link :href="route('logout')" method="post" as="button" class="nav-link">
                    <i class="fa-solid fa-sign-out mr-10"></i>
                    <span>Logout</span>
                </Link>
            </li>
        </ul>
    </div>
</template>