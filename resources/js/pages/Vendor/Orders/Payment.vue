<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { useToast } from 'vue-toastification'
import VerticalLayout from '@/Layouts/VerticalLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

type AttemptStatus = 'pending' | 'claimed' | 'succeeded' | 'failed' | 'needs_attention'

const props = defineProps<{
    order: { id: number; amount: string; currency: string; status: string | null }
    methods: Record<string, string>
    payment: {
        status: 'pending' | 'paid' | 'failed' | 'refunded'
        attempt: { method: string; status: AttemptStatus; is_blocking: boolean; is_terminal: boolean } | null
    } | null
}>()

const page = usePage()
const toast = useToast()

onMounted(() => {
    const flash = page.props.flash as { success?: string; error?: string }
    if (flash?.success) toast.success(flash.success)
    if (flash?.error) toast.error(flash.error)
})

// The Payment itself (not just its current attempt) is authoritative: once
// it's resolved (paid/failed/refunded), no method can ever be selected
// again — matches PaymentService::startAttempt()'s own PaymentAlreadyResolvedException guard.
const isResolved = computed(() => props.payment !== null && props.payment.status !== 'pending')

// A blocking attempt (pending/claimed/succeeded/needs_attention) must be
// waited out or resumed, never raced with a fresh selection — see
// PaymentAttemptStatus::blocksNewAttempt().
const blockingAttempt = computed(() => {
    const attempt = props.payment?.attempt ?? null
    return attempt && attempt.is_blocking ? attempt : null
})

const form = useForm({ method: '' })

function selectMethod(method: string) {
    form.method = method
    form.post(route('vendor.orders.payment.store', props.order.id), {
        preserveScroll: true,
    })
}
</script>

<template>
<VerticalLayout>
<div class="max-w-xl mx-auto py-6 px-4 space-y-4">

    <div class="bg-white border border-gray-200 rounded-xl p-6 space-y-1">
        <h1 class="text-base font-semibold text-gray-800">Order #{{ order.id }}</h1>
        <p class="text-sm text-gray-500">{{ order.amount }} {{ order.currency }}</p>
    </div>

    <!-- RESOLVED -->
    <div v-if="isResolved" class="bg-white border border-gray-200 rounded-xl p-6 text-center space-y-1">
        <p class="text-sm font-medium text-gray-800">
            This order is {{ payment?.status }}.
        </p>
        <p class="text-xs text-gray-500">No further payment method can be selected.</p>
    </div>

    <!-- ACTIVE / BLOCKING ATTEMPT -->
    <div v-else-if="blockingAttempt" class="bg-amber-50 border border-amber-200 rounded-xl p-6 text-center space-y-1">
        <p class="text-sm font-medium text-amber-800">
            Payment via {{ methods[blockingAttempt.method] ?? blockingAttempt.method }} is {{ blockingAttempt.status }}.
        </p>
        <p class="text-xs text-amber-700">We're waiting for it to complete before another method can be started.</p>
    </div>

    <!-- METHOD SELECTION (first attempt, or retry after a terminal failure) -->
    <div v-else class="bg-white border border-gray-200 rounded-xl p-6 space-y-3">
        <p v-if="payment?.attempt?.is_terminal" class="text-xs text-red-600">
            Your last attempt via {{ methods[payment.attempt.method] ?? payment.attempt.method }} did not succeed. Choose another method to try again.
        </p>
        <p class="text-sm font-medium text-gray-800">Choose a payment method</p>

        <div class="grid gap-2">
            <PrimaryButton
                v-for="(label, method) in methods"
                :key="method"
                :disabled="form.processing"
                @click="selectMethod(method)"
            >
                {{ label }}
            </PrimaryButton>
        </div>

        <p v-if="form.errors.method" class="text-xs text-red-600">{{ form.errors.method }}</p>
    </div>

</div>
</VerticalLayout>
</template>
