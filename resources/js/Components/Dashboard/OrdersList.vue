<script setup lang="ts">
    import { ref, defineEmits } from 'vue'

    interface Order {
        id: string
        date: string
        status: string
        total: string
        itemCount: number
    }

    const emit = defineEmits<{
        (e: 'view-order', orderId: string): void
    }>()

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

    const getStatusClass = (status: string): string => {
        const statusMap: Record<string, string> = {
            Pending: 'text-warning',
            Cancel: 'text-danger',
            Completed: 'text-primary',
            Processing: 'text-warning',
        }
        return statusMap[status] || ''
    }

    const viewOrder = (orderId: string) => {
        emit('view-order', orderId)
    }
</script>

<template>
    <div class="card">
        <div class="card-header p-0">
            <h3 class="mb-0">Your Orders</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="order_table table m-0 mt-20">
                    <thead>
                        <tr>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id">
                        <td>{{ order.id }}</td>
                        <td>{{ order.date }}</td>
                        <td>
                            <span :class="getStatusClass(order.status)">
                            {{ order.status }}
                            </span>
                        </td>
                        <td>{{ order.total }} for {{ order.itemCount }} item</td>
                        <td>
                            <a
                            href="#"
                            class="btn-small d-block"
                            @click.prevent="viewOrder(order.id)"
                            >
                            View
                            </a>
                        </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

