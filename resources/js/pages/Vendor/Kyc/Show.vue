<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import VerticalLayout from '@/Layouts/VerticalLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useKycStatus } from '@/Composables/useKycStatus'
import { useFormatDate } from '@/Composables/useFormatDate'

const props = defineProps<{ kyc: any | null }>()

const { statusColor } = useKycStatus()
const { formatDate, formatDateTime } = useFormatDate()

const statusConfig = computed(() => {
    const s = props.kyc?.status?.slug
    const map: Record<string, { icon: string; label: string; message: string; bg: string; border: string; text: string }> = {
        pending: {
            icon: '🕐',
            label: 'Under Review',
            message: 'Your KYC has been submitted and is awaiting review. We\'ll notify you once it\'s processed.',
            bg: 'bg-amber-50',
            border: 'border-amber-200',
            text: 'text-amber-800',
        },
        approved: {
            icon: '✅',
            label: 'Verified',
            message: 'Your identity has been successfully verified.',
            bg: 'bg-green-50',
            border: 'border-green-200',
            text: 'text-green-800',
        },
        rejected: {
            icon: '❌',
            label: 'Rejected',
            message: 'Your KYC was rejected. Please review the reason below and resubmit with the correct documents.',
            bg: 'bg-red-50',
            border: 'border-red-200',
            text: 'text-red-800',
        },
        expired: {
            icon: '⏰',
            label: 'Expired',
            message: 'Your KYC has expired. Please submit a new one to continue operating as a vendor.',
            bg: 'bg-gray-50',
            border: 'border-gray-200',
            text: 'text-gray-700',
        },
    }
    return map[s] ?? { icon: '📋', label: 'Unknown', message: '', bg: 'bg-gray-50', border: 'border-gray-200', text: 'text-gray-700' }
})
</script>

<template>
<VerticalLayout>
<div class="max-w-2xl mx-auto py-6 px-4 space-y-4">

    <!-- NO KYC -->
    <div v-if="!kyc" class="bg-white border border-gray-200 rounded-xl p-8 text-center space-y-3">
        <div class="text-4xl">📋</div>
        <h2 class="text-base font-semibold text-gray-800">Identity Verification Required</h2>
        <p class="text-sm text-gray-500 max-w-xs mx-auto">
            To operate as a vendor, you need to verify your identity by submitting a KYC.
        </p>
        <Link :href="route('vendor.kyc.create')">
            <PrimaryButton class="mt-2">Get Started →</PrimaryButton>
        </Link>
    </div>

    <template v-else>

        <!-- STATUS BANNER -->
        <div
            class="rounded-xl border p-4"
            :class="[statusConfig.bg, statusConfig.border]"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="text-xl mt-0.5">{{ statusConfig.icon }}</span>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold" :class="statusConfig.text">
                                {{ statusConfig.label }}
                            </span>
                            <span
                                v-if="kyc.is_expiring_soon"
                                class="text-xs font-medium bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full"
                            >
                                Expiring soon
                            </span>
                        </div>
                        <p class="text-xs mt-0.5" :class="statusConfig.text" style="opacity:0.85">
                            {{ statusConfig.message }}
                        </p>
                    </div>
                </div>

                <!-- Action button -->
                <div class="shrink-0">
                    <Link
                        v-if="kyc.can_be_resubmitted"
                        :href="route('vendor.kyc.create')"
                        class="text-xs font-medium text-indigo-600 hover:text-indigo-800 border border-indigo-200 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition-colors whitespace-nowrap"
                    >
                        Resubmit →
                    </Link>
                    <Link
                        v-else-if="kyc.can_be_edited"
                        :href="route('vendor.kyc.edit')"
                        class="text-xs font-medium text-gray-600 hover:text-gray-800 border border-gray-200 px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors whitespace-nowrap"
                    >
                        Edit
                    </Link>
                </div>
            </div>

            <!-- Rejection reason -->
            <div
                v-if="kyc.status?.slug === 'rejected' && kyc.rejection_reason"
                class="mt-3 pt-3 border-t border-red-200"
            >
                <p class="text-xs font-semibold text-red-700 uppercase tracking-wide mb-1">Rejection Reason</p>
                <p class="text-sm text-red-700">{{ kyc.rejection_reason }}</p>
            </div>
        </div>

        <!-- INFO ROW -->
        <div class="flex flex-wrap gap-2 text-xs text-gray-400 px-1">
            <span>Submitted {{ formatDate(kyc.created_at) }}</span>
            <span v-if="kyc.reviewed_at">· Reviewed {{ formatDate(kyc.reviewed_at) }}</span>
            <span v-if="kyc.expires_at">· Expires {{ formatDate(kyc.expires_at) }}</span>
        </div>

        <!-- DOCUMENTS TABLE -->
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-700">Documents</h3>
                <Link
                    v-if="kyc.can_be_edited"
                    :href="route('vendor.kyc.edit')"
                    class="text-xs text-indigo-600 hover:underline"
                >
                    Update documents
                </Link>
            </div>

            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-400 uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Document</th>
                        <th class="text-left px-4 py-2 font-medium hidden sm:table-cell">Side</th>
                        <th class="text-left px-4 py-2 font-medium hidden sm:table-cell">Size</th>
                        <th class="text-right px-4 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr
                        v-for="doc in kyc.documents"
                        :key="doc.id"
                        class="hover:bg-gray-50 transition-colors"
                    >
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="text-base">
                                    {{ doc.mime_type === 'application/pdf' ? '📄' : '🖼️' }}
                                </span>
                                <span class="font-medium text-gray-800 text-sm">
                                    {{ doc.document_type?.name ?? 'Document' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">
                            {{ doc.document_side?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs hidden sm:table-cell">
                            {{ doc.file_size ? Math.round(doc.file_size / 1024) + ' KB' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a
                                v-if="doc.url"
                                :href="doc.url"
                                target="_blank"
                                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium px-2.5 py-1 rounded-md border border-indigo-100 hover:bg-indigo-50 transition-colors"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!kyc.documents?.length">
                        <td colspan="4" class="text-center py-6 text-xs text-gray-400">
                            No documents uploaded yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- PERSONAL + ADDRESS compact card -->
        <div class="bg-white border border-gray-200 rounded-xl divide-y divide-gray-50">

            <!-- Personal -->
            <div class="px-4 py-3">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Personal</p>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <div>
                        <span class="text-gray-400 text-xs">Name · </span>
                        <span class="text-gray-800 font-medium">{{ kyc.full_name }}</span>
                    </div>
                    <div>
                        <span class="text-gray-400 text-xs">DOB · </span>
                        <span class="text-gray-800">{{ formatDate(kyc.date_of_birth) }}</span>
                    </div>
                    <div v-if="kyc.gender">
                        <span class="text-gray-400 text-xs">Gender · </span>
                        <span class="text-gray-800">
                            {{ kyc.gender?.slug === 'custom' && kyc.gender_other ? kyc.gender_other : kyc.gender?.name }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Address -->
            <div class="px-4 py-3">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Address</p>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <div>
                        <span class="text-gray-400 text-xs">Street · </span>
                        <span class="text-gray-800">{{ kyc.address_line1 }}</span>
                        <span v-if="kyc.address_line2" class="text-gray-500">, {{ kyc.address_line2 }}</span>
                    </div>
                    <div>
                        <span class="text-gray-400 text-xs">Postal · </span>
                        <span class="text-gray-800">{{ kyc.postal_code }}</span>
                    </div>
                    <div v-if="kyc.country">
                        <span class="text-gray-400 text-xs">Location · </span>
                        <span class="text-gray-800">
                            {{ [kyc.city?.name, kyc.state?.name, kyc.country?.name].filter(Boolean).join(', ') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- HISTORY -->
        <div v-if="kyc.history?.length" class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">History</h3>
            </div>
            <div class="divide-y divide-gray-50">
                <div
                    v-for="(entry, index) in kyc.history"
                    :key="entry.id"
                    class="px-4 py-3 flex items-start gap-3"
                >
                    <div class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0"
                        :class="index === 0 ? 'bg-indigo-500' : 'bg-gray-300'"
                    />
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-gray-400">{{ entry.from_status?.name ?? 'Initial' }}</span>
                            <span class="text-gray-300 text-xs">→</span>
                            <span
                                class="text-xs font-semibold px-2 py-0.5 rounded-full"
                                :class="statusColor(entry.to_status?.slug)"
                            >
                                {{ entry.to_status?.name }}
                            </span>
                        </div>
                        <p v-if="entry.notes" class="text-xs text-gray-500 mt-0.5">{{ entry.notes }}</p>
                        <p class="text-xs text-gray-300 mt-0.5">{{ formatDateTime(entry.created_at) }}</p>
                    </div>
                </div>
            </div>
        </div>

    </template>
</div>
</VerticalLayout>
</template>