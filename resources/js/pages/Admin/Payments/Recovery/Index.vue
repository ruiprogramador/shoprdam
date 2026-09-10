<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

type AttemptRow = {
    id: number
    payment_id: number
    order_id: number | null
    provider: string
    method: string
    created_at?: string
    age_minutes?: number
}

defineProps<{
    needs_attention: AttemptRow[]
    stale_pending: AttemptRow[]
}>()
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 mb-0.5">Admin</p>
                <h2 class="text-base font-bold text-gray-900 leading-tight">Payment Recovery</h2>
            </div>
        </template>

        <div class="py-6 px-4 sm:px-6 max-w-5xl mx-auto space-y-6">

            <p class="text-xs text-gray-500">
                Read-mostly recovery for payment attempts <code>php artisan payments:health</code> already flags.
                Nothing here mutates a Payment or Wallet directly — see each attempt's own page for what a retry
                actually does.
            </p>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-red-50/50">
                    <h3 class="text-sm font-semibold text-red-800">Needs attention ({{ needs_attention.length }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Attempt</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Order</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Provider</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Method</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="needs_attention.length === 0">
                                <td colspan="5" class="px-4 py-4 text-center text-gray-400">None right now.</td>
                            </tr>
                            <tr v-for="row in needs_attention" :key="row.id">
                                <td class="px-4 py-2">#{{ row.id }}</td>
                                <td class="px-4 py-2">{{ row.order_id ? `#${row.order_id}` : '—' }}</td>
                                <td class="px-4 py-2">{{ row.provider }}</td>
                                <td class="px-4 py-2">{{ row.method }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="route('admin.payments.recovery.show', row.id)" class="text-blue-600 hover:underline">Inspect →</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-amber-50/50">
                    <h3 class="text-sm font-semibold text-amber-800">Stale pending ({{ stale_pending.length }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Attempt</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Order</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Provider</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Method</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Age</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="stale_pending.length === 0">
                                <td colspan="6" class="px-4 py-4 text-center text-gray-400">None right now.</td>
                            </tr>
                            <tr v-for="row in stale_pending" :key="row.id">
                                <td class="px-4 py-2">#{{ row.id }}</td>
                                <td class="px-4 py-2">{{ row.order_id ? `#${row.order_id}` : '—' }}</td>
                                <td class="px-4 py-2">{{ row.provider }}</td>
                                <td class="px-4 py-2">{{ row.method }}</td>
                                <td class="px-4 py-2">{{ row.age_minutes }}m</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="route('admin.payments.recovery.show', row.id)" class="text-blue-600 hover:underline">Inspect →</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
