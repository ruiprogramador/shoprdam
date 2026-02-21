import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { Order, Address, WishlistItem } from '@/types/dashboard'

export const useDashboardStore = defineStore(
    'dashboard',
    () => {
        // State
        const activeTab = ref('dashboard')
        const userName = ref('Rosie')

        // Orders
        const orders = ref<Order[]>([
            {
                id: '#1357',
                date: 'March 45, 2020',
                status: 'Pending',
                total: '$125.00',
                itemCount: 2,
            },
            {
                id: '#2468',
                date: 'June 29, 2020',
                status: 'Cancel',
                total: '$364.00',
                itemCount: 5,
            },
            {
                id: '#2366',
                date: 'August 02, 2020',
                status: 'Completed',
                total: '$280.00',
                itemCount: 3,
            },
            {
                id: '#1357',
                date: 'March 45, 2020',
                status: 'Processing',
                total: '$125.00',
                itemCount: 2,
            },
        ])

        // Addresses
        const addresses = ref<Address[]>([
            {
                id: '1',
                type: 'Billing Address',
                name: 'John Doe',
                address: '3522 Interstate 75 Business Spur',
                city: 'Sault Ste. Marie',
                zip: 'MI 49783',
                country: 'United States',
                phone: '906-632-1500',
                email: 'john@example.com',
            },
        ])

        // Wishlist
        const wishlistItems = ref<WishlistItem[]>([
            {
                id: '1',
                name: 'Organic Cage-Free Grade A Large Brown Eggs',
                price: '$28.85',
                image: '/img/product-1.png',
                inStock: true,
            },
            {
                id: '2',
                name: 'Seeds of Change Organic Quinoa',
                price: '$32.85',
                image: '/img/product-2.png',
                inStock: true,
            },
        ])

        // Computed
        const dashboardStats = computed(() => ({
            totalOrders: orders.value.length,
            pendingOrders: orders.value.filter((o) => o.status === 'Pending').length,
            completedOrders: orders.value.filter((o) => o.status === 'Completed')
                .length,
            cancelledOrders: orders.value.filter((o) => o.status === 'Cancel').length,
        }))

        // Actions
        function setActiveTab(tab: string) {
            activeTab.value = tab
        }

        function addAddress(address: Address) {
            addresses.value.push({
                ...address,
                id: Date.now().toString(),
            })
        }

        function updateAddress(id: string, address: Address) {
            const index = addresses.value.findIndex((a) => a.id === id)
            if (index !== -1) {
                addresses.value[index] = { ...address, id }
            }
        }

        function deleteAddress(id: string) {
            addresses.value = addresses.value.filter((a) => a.id !== id)
        }

        function addToWishlist(item: WishlistItem) {
            const exists = wishlistItems.value.find((i) => i.id === item.id)
            if (!exists) {
                wishlistItems.value.push(item)
            }
        }

        function removeFromWishlist(id: string) {
            wishlistItems.value = wishlistItems.value.filter((i) => i.id !== id)
        }

        return {
            // State
            activeTab,
            userName,
            orders,
            addresses,
            wishlistItems,

            // Computed
            dashboardStats,

            // Actions
            setActiveTab,
            addAddress,
            updateAddress,
            deleteAddress,
            addToWishlist,
            removeFromWishlist,
        }
    },
    {
        persist: true, // Persist state to localStorage
    },
)
