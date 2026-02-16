<script setup lang="ts">
    import { ref } from 'vue'

    interface TrackingForm {
        orderId: string
        email: string
    }

    interface TrackingInfo {
        estimatedDelivery: string
        shippingBy: string
        status: string
        trackingNumber: string
    }

    interface TrackingStep {
        label: string
        class: string
    }

    interface TrackingProduct {
        id: string
        name: string
        description: string
        image: string
        price: string
        quantity: number
        total: string
    }

    const trackingForm = ref<TrackingForm>({
        orderId: '',
        email: '',
    })

    const trackingInfo = ref<TrackingInfo | null>(null)

    const trackingSteps = ref<TrackingStep[]>([
        { label: 'Order pending', class: 'check_mark' },
        { label: 'order Processing', class: '' },
        { label: 'on the way', class: '' },
        { label: 'ready for delivery', class: 'red_mark' },
    ])

    const trackingProducts = ref<TrackingProduct[]>([
        {
            id: '1',
            name: 'NFTMAX- NFT React Admin & Dashboard Template',
            description: 'Item by WSUS',
            image: '/assets/imgs/shop/thumbnail-1.jpg',
            price: '$30.00',
            quantity: 2,
            total: '$30.00',
        },
        {
            id: '2',
            name: 'Oifolio - Digital Marketing Agency Theme',
            description: 'Item by WSUS',
            image: '/assets/imgs/shop/thumbnail-2.jpg',
            price: '$40.00',
            quantity: 3,
            total: '$28.93',
        },
        {
            id: '3',
            name: 'Anone - Minimal Portfolio Template',
            description: 'Item by WSUS',
            image: '/assets/imgs/shop/thumbnail-3.jpg',
            price: '$99.00',
            quantity: 5,
            total: '$28.93',
        },
    ])

    const handleTrack = () => {
        trackingInfo.value = {
            estimatedDelivery: '20 nov 2021',
            shippingBy: 'one shop',
            status: 'order Processing',
            trackingNumber: 'BD096547155HGU',
        }
    }
</script>

<template>
    <div class="card">
        <div class="card-header p-0">
            <h3 class="mb-0">Orders tracking</h3>
        </div>
        <div class="card-body p-0 contact-from-area">
            <p>
                To track your order please enter your OrderID in the box below and press
                "Track" button. This was given to you on your receipt and in the
                confirmation email you should have received.
            </p>
            <div class="row">
                <div class="col-lg-8">
                    <form class="contact-form-style mt-30 mb-50" @submit.prevent="handleTrack">
                        <div class="input-style mb-20">
                            <label>Order ID</label>
                            <input
                                v-model="trackingForm.orderId"
                                name="order-id"
                                placeholder="Found in your order confirmation email"
                                type="text"
                            />
                        </div>
                        <div class="input-style mb-20">
                            <label>Billing email</label>
                                <input
                                    v-model="trackingForm.email"
                                    name="billing-email"
                                    placeholder="Email you used during checkout"
                                    type="email"
                                />
                        </div>
                        <button class="btn" type="submit">Track</button>
                    </form>
                </div>
            </div>

            <div v-if="trackingInfo" class="row">
                <div class="col-xl-12">
                    <div class="wsus__track_header">
                        <div class="wsus__track_header_text">
                            <div class="row">
                                <div class="col-xl-3 col-sm-6 col-lg-3">
                                    <div class="wsus__track_header_single">
                                        <h5>estimated delivery time:</h5>
                                        <p>{{ trackingInfo.estimatedDelivery }}</p>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-sm-6 col-lg-3">
                                    <div class="wsus__track_header_single">
                                        <h5>shopping by:</h5>
                                        <p>{{ trackingInfo.shippingBy }}</p>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-sm-6 col-lg-3">
                                    <div class="wsus__track_header_single">
                                        <h5>status:</h5>
                                        <p>{{ trackingInfo.status }}</p>
                                    </div>
                                </div>
                                <div class="col-xl-3 col-sm-6 col-lg-3">
                                    <div class="wsus__track_header_single">
                                        <h5>tracking:</h5>
                                        <p>{{ trackingInfo.trackingNumber }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-12">
                    <ul class="pro_trckr">
                        <li
                            v-for="(step, index) in trackingSteps"
                            :key="index"
                            :class="step.class"
                        >
                            {{ step.label }}
                        </li>
                    </ul>
                </div>

                <div class="col-12">
                    <div class="track_pro_table">
                        <div class="table-responsive">
                            <table class="table m-0">
                                <thead>
                                    <tr>
                                        <th class="img">Product</th>
                                        <th class="description"></th>
                                        <th class="price">price</th>
                                        <th class="discount">Quantity</th>
                                        <th class="total">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="product in trackingProducts" :key="product.id">
                                        <td class="img">
                                            <a href="#">
                                                <img class="img" :src="product.image" :alt="product.name" />
                                            </a>
                                        </td>
                                        <td class="description">
                                            <h3><a href="#">{{ product.name }}</a></h3>
                                            <p>{{ product.description }}</p>
                                        </td>
                                        <td class="price">
                                            <p>{{ product.price }}</p>
                                        </td>
                                        <td class="discount">
                                            <p>{{ product.quantity }}</p>
                                        </td>
                                        <td class="total">
                                            <p>{{ product.total }}</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
