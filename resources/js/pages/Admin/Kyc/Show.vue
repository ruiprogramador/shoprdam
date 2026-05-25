<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Modal from '@/Components/Modal.vue'
import { useKycStatus } from '@/Composables/useKycStatus'
import { useFormatDate } from '@/Composables/useFormatDate'

const props = defineProps<{ kyc: any }>()

const { statusColor } = useKycStatus()
const { formatDate, formatDateTime } = useFormatDate()

const showApproveModal = ref(false)
const showRejectModal  = ref(false)

const approveForm = useForm({ action: 'approve', notes: '' })
const rejectForm  = useForm({ action: 'reject',  notes: '' })

const submitApprove = () => {
    approveForm.post(route('admin.kyc.review', props.kyc.id), {
        onSuccess: () => { showApproveModal.value = false },
    })
}

const submitReject = () => {
    rejectForm.post(route('admin.kyc.review', props.kyc.id), {
        onSuccess: () => { showRejectModal.value = false },
    })
}

const Field = ({ label, value }: { label: string; value: string }) => ({ label, value })
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <!-- Left: back + title + status -->
                <div class="flex items-center gap-3 min-w-0">
                    <Link
                        :href="route('admin.kyc.index')"
                        class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:text-gray-700 hover:bg-gray-50 transition-colors flex-shrink-0"
                    >
                        <i class="fa fa-arrow-left text-xs"></i>
                    </Link>
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 mb-0.5">KYC Review</p>
                        <h2 class="text-base font-bold text-gray-900 truncate leading-tight">{{ kyc.user?.name }}</h2>
                    </div>
                    <span
                        class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold border flex-shrink-0"
                        :class="statusColor(kyc.status?.slug)"
                    >
                        {{ kyc.status?.name }}
                    </span>
                </div>

                <!-- Right: actions -->
                <div v-if="kyc.can_be_reviewed" class="flex items-center gap-2 flex-shrink-0">
                    <button
                        @click="showRejectModal = true"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 hover:border-red-300 rounded-lg transition-all duration-150"
                    >
                        <i class="fa fa-times-circle"></i> Reject
                    </button>
                    <button
                        @click="showApproveModal = true"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white bg-green-600 hover:bg-green-700 border border-green-600 rounded-lg transition-all duration-150 shadow-sm"
                    >
                        <i class="fa fa-check-circle"></i> Approve
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6 px-4 sm:px-6 max-w-4xl mx-auto space-y-4">

            <!-- Rejection notice -->
            <div v-if="kyc.status?.slug === 'rejected' && kyc.rejection_reason" class="flex gap-3 items-start p-4 bg-red-50 border border-red-200 rounded-xl">
                <i class="fa fa-exclamation-circle text-red-400 mt-0.5 flex-shrink-0"></i>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-red-500 mb-1">Rejection Reason</p>
                    <p class="text-sm text-red-700">{{ kyc.rejection_reason }}</p>
                </div>
            </div>

            <!-- Personal Information -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                        <i class="fa fa-user text-gray-300"></i> Personal Information
                    </h3>
                </div>
                <div class="p-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Vendor</p>
                        <p class="text-sm font-semibold text-gray-900">{{ kyc.user?.name }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ kyc.user?.email }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Full Name</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.full_name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Date of Birth</p>
                        <p class="text-sm font-medium text-gray-800">{{ formatDate(kyc.date_of_birth) || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Gender</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.gender?.name || '—' }}</p>
                        <p v-if="kyc.gender_other" class="text-xs text-gray-400">{{ kyc.gender_other }}</p>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                        <i class="fa fa-map-marker-alt text-gray-300"></i> Address
                    </h3>
                </div>
                <div class="p-4 grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Country</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.country?.flag_emoji }} {{ kyc.country?.name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">State / Region</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.state?.name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">City</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.city?.name || '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Address Line 1</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.address_line1 || '—' }}</p>
                    </div>
                    <div v-if="kyc.address_line2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Address Line 2</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.address_line2 }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Postal Code</p>
                        <p class="text-sm font-medium text-gray-800 tabular-nums">{{ kyc.postal_code || '—' }}</p>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                        <i class="fa fa-file-alt text-gray-300"></i> Documents
                    </h3>
                </div>
                <div class="p-4">
                    <div v-if="kyc.documents?.length" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a
                            v-for="doc in kyc.documents"
                            :key="doc.id"
                            :href="doc.url"
                            target="_blank"
                            class="flex items-center gap-3 p-3 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50/30 transition-all group"
                        >
                            <div class="w-9 h-9 rounded-lg bg-gray-100 group-hover:bg-blue-100 flex items-center justify-center flex-shrink-0 transition-colors">
                                <i class="fa fa-file-pdf text-gray-400 group-hover:text-blue-500 transition-colors"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">
                                    {{ doc.document_type?.name }}
                                    <span v-if="doc.document_side" class="font-normal text-gray-400">· {{ doc.document_side?.name }}</span>
                                </p>
                                <p class="text-xs text-gray-400">{{ doc.mime_type }}</p>
                            </div>
                            <span class="text-xs font-semibold text-blue-500 group-hover:text-blue-700 flex-shrink-0 flex items-center gap-1">
                                View <i class="fa fa-external-link-alt text-[10px]"></i>
                            </span>
                        </a>
                    </div>
                    <p v-else class="text-sm text-gray-400 py-2">No documents uploaded.</p>
                </div>
            </div>

            <!-- Review info -->
            <div v-if="kyc.reviewer" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                        <i class="fa fa-user-check text-gray-300"></i> Review
                    </h3>
                </div>
                <div class="p-4 grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Reviewed by</p>
                        <p class="text-sm font-medium text-gray-800">{{ kyc.reviewer?.name }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Reviewed at</p>
                        <p class="text-sm font-medium text-gray-800 tabular-nums">{{ formatDateTime(kyc.reviewed_at) }}</p>
                    </div>
                    <div v-if="kyc.expires_at">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1">Expires at</p>
                        <p class="text-sm font-medium text-gray-800 tabular-nums">{{ formatDate(kyc.expires_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div v-if="kyc.history?.length" class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/70">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 flex items-center gap-1.5">
                        <i class="fa fa-history text-gray-300"></i> History
                    </h3>
                </div>
                <div class="p-4 space-y-3">
                    <div v-for="entry in kyc.history" :key="entry.id" class="flex items-start gap-3">
                        <div class="mt-1.5 w-1.5 h-1.5 rounded-full bg-blue-400 flex-shrink-0"></div>
                        <div class="min-w-0">
                            <p class="text-sm text-gray-700">
                                <span class="font-semibold">{{ entry.from_status?.name ?? '—' }}</span>
                                <i class="fa fa-arrow-right text-[10px] text-gray-400 mx-1.5"></i>
                                <span class="font-semibold">{{ entry.to_status?.name }}</span>
                                <span v-if="entry.reviewer" class="text-gray-400 font-normal"> · {{ entry.reviewer?.name }}</span>
                            </p>
                            <p v-if="entry.notes" class="text-xs text-gray-500 mt-0.5">{{ entry.notes }}</p>
                            <p class="text-xs text-gray-400 mt-0.5 tabular-nums">{{ formatDateTime(entry.created_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Approve Modal ─────────────────────────────────────── -->
        <Modal :show="showApproveModal" @close="showApproveModal = false" max-width="sm">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-green-100 flex items-center justify-center flex-shrink-0">
                        <i class="fa fa-check-circle text-green-600 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Approve KYC</h3>
                        <p class="text-xs text-gray-400">This action will notify the vendor.</p>
                    </div>
                </div>

                <p class="text-sm text-gray-600 mb-5">
                    Are you sure you want to approve the KYC submission for
                    <span class="font-semibold text-gray-900">{{ kyc.user?.name }}</span>?
                    The vendor will be verified and notified.
                </p>

                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">Notes <span class="font-normal normal-case">(optional)</span></label>
                    <textarea
                        v-model="approveForm.notes"
                        rows="3"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100 resize-none transition-colors"
                        placeholder="Add any notes for the record..."
                    />
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        @click="showApproveModal = false"
                        class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitApprove"
                        :disabled="approveForm.processing"
                        class="px-4 py-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors flex items-center gap-2"
                    >
                        <i v-if="approveForm.processing" class="fa fa-spinner fa-spin text-xs"></i>
                        {{ approveForm.processing ? 'Processing…' : 'Confirm Approval' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- ── Reject Modal ──────────────────────────────────────── -->
        <Modal :show="showRejectModal" @close="showRejectModal = false" max-width="sm">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                        <i class="fa fa-times-circle text-red-500 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Reject KYC</h3>
                        <p class="text-xs text-gray-400">This action will notify the vendor.</p>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-gray-400 mb-1.5">
                        Rejection Reason <span class="text-red-400">*</span>
                    </label>
                    <textarea
                        v-model="rejectForm.notes"
                        rows="4"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 placeholder-gray-400 focus:outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100 resize-none transition-colors"
                        :class="{ 'border-red-400': rejectForm.errors.notes }"
                        placeholder="Describe clearly why this KYC is being rejected…"
                    />
                    <p v-if="rejectForm.errors.notes" class="mt-1 text-xs text-red-500">
                        {{ rejectForm.errors.notes }}
                    </p>
                </div>

                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        @click="showRejectModal = false"
                        class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitReject"
                        :disabled="rejectForm.processing || !rejectForm.notes.trim()"
                        class="px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors flex items-center gap-2"
                    >
                        <i v-if="rejectForm.processing" class="fa fa-spinner fa-spin text-xs"></i>
                        {{ rejectForm.processing ? 'Processing…' : 'Confirm Rejection' }}
                    </button>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>