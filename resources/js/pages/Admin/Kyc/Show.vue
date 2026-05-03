<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import { useKycStatus } from '@/Composables/useKycStatus'
import { useFormatDate } from '@/Composables/useFormatDate'

const props = defineProps<{
    kyc: any
}>()

const { statusColor } = useKycStatus()
const { formatDate, formatDateTime } = useFormatDate()

const showReviewModal = ref(false)
const reviewAction = ref('')

const form = useForm({ action: '', notes: '' })

const openApprove = () => {
    reviewAction.value = 'approve'
    form.action = 'approve'
    form.notes = ''
    showReviewModal.value = true
}

const openReject = () => {
    reviewAction.value = 'reject'
    form.action = 'reject'
    form.notes = ''
    showReviewModal.value = true
}

const submitReview = () => {
    form.post(route('admin.kyc.review', props.kyc.id), {
        onSuccess: () => { showReviewModal.value = false },
    })
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('admin.kyc.index')" class="text-gray-400 hover:text-gray-600">←</Link>
                    <h2 class="text-xl font-semibold text-gray-800">KYC — {{ kyc.user?.name }}</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold" :class="statusColor(kyc.status?.slug)">
                        {{ kyc.status?.name }}
                    </span>
                </div>
                <div v-if="kyc.can_be_reviewed" class="flex gap-2">
                    <PrimaryButton @click="openApprove">Approve</PrimaryButton>
                    <DangerButton @click="openReject">Reject</DangerButton>
                </div>
            </div>
        </template>

        <div class="py-6 px-4 max-w-5xl mx-auto space-y-6">

            <!-- Personal Info -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Personal Information</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Vendor</p>
                        <p class="font-medium text-gray-800">{{ kyc.user?.name }}</p>
                        <p class="text-gray-400 text-xs">{{ kyc.user?.email }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Full Name</p>
                        <p class="font-medium text-gray-800">{{ kyc.full_name }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Date of Birth</p>
                        <p class="font-medium text-gray-800">{{ formatDate(kyc.date_of_birth) }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Gender</p>
                        <p class="font-medium text-gray-800">{{ kyc.gender?.name }}</p>
                    </div>
                    <div v-if="kyc.gender_other">
                        <p class="text-gray-500">Gender (other)</p>
                        <p class="font-medium text-gray-800">{{ kyc.gender_other }}</p>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Address</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-gray-500">Country</p>
                        <p class="font-medium text-gray-800">{{ kyc.country?.flag_emoji }} {{ kyc.country?.name }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">State/Region</p>
                        <p class="font-medium text-gray-800">{{ kyc.state?.name }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">City</p>
                        <p class="font-medium text-gray-800">{{ kyc.city?.name }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Address Line 1</p>
                        <p class="font-medium text-gray-800">{{ kyc.address_line1 }}</p>
                    </div>
                    <div v-if="kyc.address_line2">
                        <p class="text-gray-500">Address Line 2</p>
                        <p class="font-medium text-gray-800">{{ kyc.address_line2 }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Postal Code</p>
                        <p class="font-medium text-gray-800">{{ kyc.postal_code }}</p>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Documents</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-for="doc in kyc.documents" :key="doc.id" class="border border-gray-200 rounded-lg p-4 flex items-center gap-3">
                        <div class="text-2xl">📄</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800">
                                {{ doc.document_type?.name }}
                                <span v-if="doc.document_side" class="text-gray-500">— {{ doc.document_side?.name }}</span>
                            </p>
                            <p class="text-xs text-gray-400">{{ doc.mime_type }}</p>
                        </div>
                        <a v-if="doc.url" :href="doc.url" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium shrink-0">View</a>
                    </div>
                </div>
            </div>

            <!-- Rejection reason -->
            <div v-if="kyc.status?.slug === 'rejected' && kyc.rejection_reason" class="bg-red-50 border border-red-200 rounded-lg p-4">
                <p class="text-sm font-semibold text-red-700 mb-1">Reason for Rejection:</p>
                <p class="text-sm text-red-600">{{ kyc.rejection_reason }}</p>
            </div>

            <!-- Reviewer info -->
            <div v-if="kyc.reviewer" class="bg-white rounded-lg shadow p-6 text-sm">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Review</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <p class="text-gray-500">Reviewed by</p>
                        <p class="font-medium text-gray-800">{{ kyc.reviewer?.name }}</p>
                    </div>
                    <div>
                        <p class="text-gray-500">Reviewed at</p>
                        <p class="font-medium text-gray-800">{{ formatDateTime(kyc.reviewed_at) }}</p>
                    </div>
                    <div v-if="kyc.expires_at">
                        <p class="text-gray-500">Expires at</p>
                        <p class="font-medium text-gray-800">{{ formatDate(kyc.expires_at) }}</p>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div v-if="kyc.history?.length" class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">History</h2>
                <div class="space-y-4">
                    <div v-for="entry in kyc.history" :key="entry.id" class="flex items-start gap-3 text-sm">
                        <div class="w-2 h-2 rounded-full bg-indigo-400 mt-1.5 shrink-0"></div>
                        <div>
                            <p class="text-gray-700">
                                <span class="font-medium">{{ entry.from_status?.name ?? '—' }}</span>
                                →
                                <span class="font-medium">{{ entry.to_status?.name }}</span>
                                <span v-if="entry.reviewer" class="text-gray-400"> by {{ entry.reviewer?.name }}</span>
                            </p>
                            <p v-if="entry.notes" class="text-gray-500 mt-0.5">{{ entry.notes }}</p>
                            <p class="text-gray-400 text-xs mt-0.5">{{ formatDateTime(entry.created_at) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Review Modal -->
        <Modal :show="showReviewModal" @close="showReviewModal = false">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    {{ reviewAction === 'approve' ? 'Approve KYC' : 'Reject KYC' }}
                </h3>
                <div v-if="reviewAction === 'reject'" class="mb-4">
                    <InputLabel value="Reason for Rejection" />
                    <textarea
                        v-model="form.notes"
                        rows="4"
                        class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                        placeholder="Describe the reason for rejection..."
                    />
                    <InputError :message="form.errors.notes" />
                </div>
                <p v-else class="text-sm text-gray-600 mb-4">
                    Are you sure you want to approve this KYC? The vendor will be notified.
                </p>
                <div class="flex justify-end gap-3">
                    <SecondaryButton type="button" @click="showReviewModal = false">Cancel</SecondaryButton>
                    <PrimaryButton v-if="reviewAction === 'approve'" @click="submitReview" :disabled="form.processing">
                        {{ form.processing ? 'Processing...' : 'Confirm Approval' }}
                    </PrimaryButton>
                    <DangerButton v-else @click="submitReview" :disabled="form.processing">
                        {{ form.processing ? 'Processing...' : 'Confirm Rejection' }}
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>