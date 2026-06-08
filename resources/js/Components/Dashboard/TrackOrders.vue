<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from 'vue-toastification'

interface TrackingForm { orderId: string; email: string }

const form         = ref<TrackingForm>({ orderId: '', email: '' })
const trackingInfo = ref(null)
const isLoading    = ref(false)
const toast        = useToast()

const handleTrack = () => {
    isLoading.value = true
    router.post(route('order.track'), form.value, {
        preserveScroll: true,
        onSuccess: (page) => {
            trackingInfo.value = page.props.trackingInfo ?? null
        },
        onError: () => {
            toast.error('Order not found. Please check your details.')
        },
        onFinish: () => {
            isLoading.value = false
        },
    })
}
</script>

<template>
    <div class="card">
        <div class="card-header p-0">
            <h3 class="mb-0">Orders tracking</h3>
        </div>
        <div class="card-body p-0 contact-from-area">
            <p>To track your order please enter your Order ID and billing email.</p>

            <div class="row">
                <div class="col-lg-8">
                    <form class="contact-form-style mt-30 mb-50" @submit.prevent="handleTrack">
                        <div class="input-style mb-20">
                            <label for="track-order-id">Order ID</label>
                            <input id="track-order-id" v-model="form.orderId" name="order-id" placeholder="Found in your order confirmation email" type="text" required />
                        </div>
                        <div class="input-style mb-20">
                            <label for="track-email">Billing email</label>
                            <input id="track-email" v-model="form.email" name="billing-email" placeholder="Email you used during checkout" type="email" required />
                        </div>
                        <button class="btn" type="submit" :disabled="isLoading">
                            {{ isLoading ? 'Searching...' : 'Track' }}
                        </button>
                    </form>
                </div>
            </div>

            <div v-if="trackingInfo" class="row">
                <!-- Resultado real vindo do backend via Inertia props -->
                <pre class="text-sm">{{ trackingInfo }}</pre>
            </div>
        </div>
    </div>
</template>