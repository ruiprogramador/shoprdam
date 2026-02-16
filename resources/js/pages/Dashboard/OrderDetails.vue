
<script setup lang="ts">
    import { Link, router } from '@inertiajs/vue3'
    import DashboardSidebar from '@/Components/Dashboard/DashboardSidebar.vue'
    import type { OrderDetails } from '@/types/dashboard'

    interface Props {
        order: OrderDetails
    }

    defineProps<Props>()

    const handleTabChange = (tab: string) => {
        router.visit(route('dashboard.index'), {
            data: { tab },
            preserveState: true,
        })
    }

    const getStatusClass = (status: string): string => {
        const statusMap: Record<string, string> = {
            Pending: 'text-warning',
            Cancel: 'text-danger',
            Completed: 'text-primary',
            Processing: 'text-warning',
        }
        return statusMap[status] || ''
    }
</script>

<style scoped>
    .wsus__invoice_header {
        background-color: #f8f9fa;
        padding: 20px;
        border-radius: 5px;
    }

    .wsus__invoice_single {
        margin-bottom: 15px;
    }

    .wsus__invoice_single h5 {
        font-size: 14px;
        color: #666;
        margin-bottom: 5px;
    }

    .wsus__invoice_address {
        background-color: #f8f9fa;
        padding: 20px;
        border-radius: 5px;
        height: 100%;
    }

    .wsus__invoice_address h6 {
        font-size: 16px;
        margin-bottom: 15px;
        font-weight: 600;
    }

    .wsus__invoice_product {
        margin-bottom: 30px;
    }

    .order_img img {
        width: 80px;
        height: 80px;
        object-fit: cover;
    }

    .wsus__invoice_final_total {
        background-color: #f8f9fa;
        padding: 20px;
        border-radius: 5px;
    }

    .wsus__invoice_final_total_right {
        text-align: right;
    }

    .wsus__invoice_final_total_right h6,
    .wsus__invoice_final_total_right h5 {
        display: flex;
        justify-content: flex-end;
        gap: 20px;
        margin-bottom: 10px;
    }

    .wsus__invoice_final_total_right h5 {
        font-size: 20px;
        font-weight: 700;
        border-top: 2px solid #ddd;
        padding-top: 10px;
        margin-top: 10px;
    }
</style>

<template>
    <div class="main pages">
        <div class="page-content pt-70 pb-60">
            <div class="container">
                <div class="row">
                    <div class="col-12">
                        <div class="row">
                            <div class="col-md-3">
                                <DashboardSidebar
                                    :active-tab="'orders'"
                                    @tab-change="handleTabChange"
                                />
                            </div>

                            <div class="col-md-9">
                                <div class="tab-content account dashboard-content pl-50">
                                    <div class="tab-pane fade active show">
                                        <div class="card">
                                            <div class="card-header p-0 pb-10">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <h3 class="mb-0">Order Details</h3>
                                                    <Link
                                                        :href="route('dashboard.index')"
                                                        class="btn btn-sm"
                                                        :data="{ tab: 'orders' }"
                                                        preserve-state
                                                    >
                                                        <i class="fa fa-arrow-left mr-2"></i> Back to Orders
                                                    </Link>
                                                </div>
                                            </div>

                                            <div class="card-body p-0">
                                                <!-- Order Info Header -->
                                                <div class="wsus__invoice_header mb-30">
                                                    <div class="row">
                                                        <div class="col-xl-3 col-md-6">
                                                            <div class="wsus__invoice_single">
                                                                <h5>Order ID</h5>
                                                                <p>{{ order.orderId }}</p>
                                                            </div>
                                                        </div>
                                                        <div class="col-xl-3 col-md-6">
                                                            <div class="wsus__invoice_single">
                                                                <h5>Date</h5>
                                                                <p>{{ order.date }}</p>
                                                            </div>
                                                        </div>
                                                        <div class="col-xl-3 col-md-6">
                                                            <div class="wsus__invoice_single">
                                                                <h5>Status</h5>
                                                                <p>
                                                                    <span :class="getStatusClass(order.status)">
                                                                        {{ order.status }}
                                                                    </span>
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div class="col-xl-3 col-md-6">
                                                            <div class="wsus__invoice_single">
                                                                <h5>Total Amount</h5>
                                                                <p>{{ order.total }}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Shipping & Billing Info -->
                                                <div class="row mb-30">
                                                    <div class="col-md-6">
                                                        <div class="wsus__invoice_address">
                                                            <h6>Billing Address</h6>
                                                            <p>{{ order.billingAddress.name }}</p>
                                                            <p>{{ order.billingAddress.address }}</p>
                                                            <p>{{ order.billingAddress.city }}, {{ order.billingAddress.zip }}</p>
                                                            <p>{{ order.billingAddress.country }}</p>
                                                            <p>Phone: {{ order.billingAddress.phone }}</p>
                                                            <p v-if="order.billingAddress.email">
                                                                Email: {{ order.billingAddress.email }}
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="wsus__invoice_address">
                                                            <h6>Shipping Address</h6>
                                                            <p>{{ order.shippingAddress.name }}</p>
                                                            <p>{{ order.shippingAddress.address }}</p>
                                                            <p>{{ order.shippingAddress.city }}, {{ order.shippingAddress.zip }}</p>
                                                            <p>{{ order.shippingAddress.country }}</p>
                                                            <p>Phone: {{ order.shippingAddress.phone }}</p>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Order Items Table -->
                                                <div class="wsus__invoice_product">
                                                    <div class="table-responsive">
                                                        <table class="table m-0">
                                                            <thead>
                                                                <tr>
                                                                <th class="order_img">Product</th>
                                                                <th class="description">Description</th>
                                                                <th class="price">Price</th>
                                                                <th class="quantity">Quantity</th>
                                                                <th class="total">Total</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr v-for="item in order.items" :key="item.id">
                                                                    <td class="order_img">
                                                                        <img :src="item.image" :alt="item.name" />
                                                                    </td>
                                                                    <td class="description">
                                                                        <h6>
                                                                            <a :href="`/product/${item.id}`">{{ item.name }}</a>
                                                                        </h6>
                                                                        <p v-if="item.color">
                                                                            <b>Color:</b> {{ item.color }}
                                                                        </p>
                                                                        <p v-if="item.size">
                                                                            <b>Size:</b> {{ item.size }}
                                                                        </p>
                                                                    </td>
                                                                    <td class="price">
                                                                        <p>{{ item.price }}</p>
                                                                    </td>
                                                                    <td class="quantity">
                                                                        <p>{{ item.quantity }}</p>
                                                                    </td>
                                                                    <td class="total">
                                                                        <p>{{ item.total }}</p>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Order Summary -->
                                                <div class="wsus__invoice_final_total">
                                                    <div class="row">
                                                        <div class="col-xl-6">
                                                            <div class="wsus__invoice_final_total_left">
                                                                <p>Thank you for your business</p>
                                                            </div>
                                                        </div>
                                                        <div class="col-xl-6">
                                                            <div class="wsus__invoice_final_total_right">
                                                                <h6>Subtotal:<span>{{ order.subtotal }}</span></h6>
                                                                <h6>Tax:<span>{{ order.tax }}</span></h6>
                                                                <h6 v-if="order.shipping">
                                                                    Shipping:<span>{{ order.shipping }}</span>
                                                                </h6>
                                                                <h5>Total: <span>{{ order.total }}</span></h5>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
