<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { useToast } from 'vue-toastification'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

type Attempt = {
    id: number
    payment_id: number
    order_id: number | null
    provider: string
    method: string
    status: string
    provider_reference: string | null
    recovery_attempts: number
    // The recovery error's own text is never sent to the browser — see
    // App\Domain\Payments\RecoveryErrorFormatter — only whether one exists.
    has_last_recovery_error: boolean
    last_attempted_at: string | null
    locked_until: string | null
    created_at: string
}

type PendingEvent = {
    id: number
    event_type: string
    replay_attempts: number
    has_last_replay_error: boolean
    created_at: string
}

type HistoryEntry = {
    id: number
    action: string
    outcome: string
    detail: string | null
    admin: string | null
    created_at: string
}

const props = defineProps<{
    attempt: Attempt
    pending_events: PendingEvent[]
    can_retry: boolean
    history: HistoryEntry[]
}>()

const page = usePage()
const toast = useToast()

onMounted(() => {
    const flash = page.props.flash as { success?: string; error?: string; info?: string }
    if (flash?.success) toast.success(flash.success)
    if (flash?.error) toast.error(flash.error)
    if (flash?.info) toast.info(flash.info)
})

const retryLabel = computed(() => {
    if (props.attempt.status === 'pending') return 'Retry recovery'
    if (props.attempt.provider_reference) return 'Replay pending events'
    return 'No safe action available'
})

const form = useForm({})

function retry() {
    form.post(route('admin.payments.recovery.retry', props.attempt.id), { preserveScroll: true })
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 mb-0.5">Admin · Payment Recovery</p>
                <h2 class="text-base font-bold text-gray-900 leading-tight">Attempt #{{ attempt.id }}</h2>
            </div>
        </template>

        <div class="py-6 px-4 sm:px-6 max-w-3xl mx-auto space-y-6">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 grid grid-cols-2 gap-3 text-sm">
                <div><span class="text-gray-500">Status</span><br>{{ attempt.status }}</div>
                <div><span class="text-gray-500">Payment</span><br>#{{ attempt.payment_id }}</div>
                <div><span class="text-gray-500">Order</span><br>{{ attempt.order_id ? `#${attempt.order_id}` : '—' }}</div>
                <div><span class="text-gray-500">Provider / method</span><br>{{ attempt.provider }} / {{ attempt.method }}</div>
                <div><span class="text-gray-500">Provider reference</span><br>{{ attempt.provider_reference ?? '—' }}</div>
                <div><span class="text-gray-500">Recovery attempts</span><br>{{ attempt.recovery_attempts }}</div>
                <div><span class="text-gray-500">Locked until</span><br>{{ attempt.locked_until ?? '—' }}</div>
                <div><span class="text-gray-500">Last attempted</span><br>{{ attempt.last_attempted_at ?? '—' }}</div>
                <div class="col-span-2" v-if="attempt.has_last_recovery_error">
                    <span class="text-gray-500">Last recovery error</span><br>
                    <span class="text-red-700">An error is on record for this attempt — see server logs for detail.</span>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <button
                    v-if="can_retry"
                    class="inline-flex items-center px-4 py-2 rounded-md bg-gray-800 text-white text-xs font-semibold uppercase tracking-widest hover:bg-gray-700 disabled:opacity-25"
                    :disabled="form.processing"
                    @click="retry"
                >
                    {{ retryLabel }}
                </button>
                <p v-else class="text-sm text-gray-500">
                    No safe recovery action is available for this attempt — it needs manual investigation outside this tool.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-800">Pending provider events ({{ pending_events.length }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Type</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Replay attempts</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Last error</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Since</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="pending_events.length === 0">
                                <td colspan="4" class="px-4 py-4 text-center text-gray-400">None.</td>
                            </tr>
                            <tr v-for="event in pending_events" :key="event.id">
                                <td class="px-4 py-2">{{ event.event_type }}</td>
                                <td class="px-4 py-2">{{ event.replay_attempts }}</td>
                                <td class="px-4 py-2 text-red-700">{{ event.has_last_replay_error ? 'Recorded — see server logs' : '—' }}</td>
                                <td class="px-4 py-2">{{ event.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-800">Recovery action history ({{ history.length }})</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">When</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Admin</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Action</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Outcome</th>
                                <th class="px-4 py-2 text-left font-medium text-gray-500">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="history.length === 0">
                                <td colspan="5" class="px-4 py-4 text-center text-gray-400">No manual recovery actions yet.</td>
                            </tr>
                            <tr v-for="entry in history" :key="entry.id">
                                <td class="px-4 py-2">{{ entry.created_at }}</td>
                                <td class="px-4 py-2">{{ entry.admin ?? '—' }}</td>
                                <td class="px-4 py-2">{{ entry.action }}</td>
                                <td class="px-4 py-2">{{ entry.outcome }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ entry.detail ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
