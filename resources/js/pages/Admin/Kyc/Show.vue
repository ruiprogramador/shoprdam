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
</script>

<template>
    <AuthenticatedLayout>

        <!-- ─── HEADER ──────────────────────────────────────────────────── -->
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                <div class="flex items-center gap-3">
                    <Link
                        :href="route('admin.kyc.index')"
                        class="kyc-back-btn"
                        aria-label="Back to KYC list"
                    >
                        <i class="fa fa-arrow-left text-sm"></i>
                    </Link>

                    <div>
                        <p class="kyc-eyebrow">KYC Review</p>
                        <h1 class="kyc-page-title">{{ kyc.user?.name }}</h1>
                        <p class="kyc-page-sub">{{ kyc.user?.email }}</p>
                    </div>
                </div>

                <span class="kyc-status-pill" :class="statusColor(kyc.status?.slug)">
                    <span class="kyc-status-dot" :class="statusColor(kyc.status?.slug) + '-dot'"></span>
                    {{ kyc.status?.name }}
                </span>

            </div>
        </template>

        <!-- ─── BODY ─────────────────────────────────────────────────────── -->
        <div class="kyc-container">

            <!-- Rejection banner -->
            <div
                v-if="kyc.status?.slug === 'rejected' && kyc.rejection_reason"
                class="kyc-rejection-banner"
                role="alert"
            >
                <i class="fa fa-triangle-exclamation kyc-rejection-icon" aria-hidden="true"></i>
                <div>
                    <p class="kyc-rejection-label">Rejection reason</p>
                    <p class="kyc-rejection-text">{{ kyc.rejection_reason }}</p>
                </div>
            </div>

            <!-- Two-column layout -->
            <div class="kyc-grid">

                <!-- ── MAIN ─────────────────────────────── -->
                <div class="kyc-main">

                    <!-- Documents -->
                    <section class="kyc-card" aria-label="Documents">
                        <div class="kyc-card-header">
                            <i class="fa fa-folder-open kyc-card-icon" aria-hidden="true"></i>
                            <h2 class="kyc-card-title">Documents</h2>
                            <span class="kyc-badge-count">{{ kyc.documents?.length ?? 0 }}</span>
                        </div>

                        <div class="kyc-docs-list">
                            <a
                                v-for="doc in kyc.documents"
                                :key="doc.id"
                                :href="doc.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="kyc-doc-row"
                            >
                                <span class="kyc-doc-icon-wrap">
                                    <i class="fa fa-file-pdf kyc-doc-pdf-icon" aria-hidden="true"></i>
                                </span>
                                <div class="kyc-doc-info">
                                    <p class="kyc-doc-name">{{ doc.document_type?.name }}</p>
                                    <p class="kyc-doc-meta">{{ doc.mime_type }}</p>
                                </div>
                                <i class="fa fa-arrow-up-right-from-square kyc-doc-ext-icon" aria-hidden="true"></i>
                            </a>

                            <p v-if="!kyc.documents?.length" class="kyc-empty-state">
                                No documents uploaded.
                            </p>
                        </div>
                    </section>

                    <!-- Personal Information -->
                    <section class="kyc-card" aria-label="Personal information">
                        <div class="kyc-card-header">
                            <i class="fa fa-user kyc-card-icon" aria-hidden="true"></i>
                            <h2 class="kyc-card-title">Personal information</h2>
                        </div>

                        <dl class="kyc-field-list">
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Vendor</dt>
                                <dd class="kyc-field-value">
                                    <span class="kyc-field-strong">{{ kyc.user?.name }}</span>
                                    <span class="kyc-field-muted">{{ kyc.user?.email }}</span>
                                </dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Full name</dt>
                                <dd class="kyc-field-value">{{ kyc.full_name || '—' }}</dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Date of birth</dt>
                                <dd class="kyc-field-value">{{ formatDate(kyc.date_of_birth) }}</dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Gender</dt>
                                <dd class="kyc-field-value">{{ kyc.gender?.name || '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Address -->
                    <section class="kyc-card" aria-label="Address">
                        <div class="kyc-card-header">
                            <i class="fa fa-location-dot kyc-card-icon" aria-hidden="true"></i>
                            <h2 class="kyc-card-title">Address</h2>
                        </div>

                        <dl class="kyc-field-list">
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Country</dt>
                                <dd class="kyc-field-value">
                                    <span>{{ kyc.country?.flag_emoji }}</span>
                                    {{ kyc.country?.name || '—' }}
                                </dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">State</dt>
                                <dd class="kyc-field-value">{{ kyc.state?.name || '—' }}</dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">City</dt>
                                <dd class="kyc-field-value">{{ kyc.city?.name || '—' }}</dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Address</dt>
                                <dd class="kyc-field-value kyc-field-right">{{ kyc.address_line1 || '—' }}</dd>
                            </div>
                            <div class="kyc-field-row">
                                <dt class="kyc-field-label">Postal code</dt>
                                <dd class="kyc-field-value">{{ kyc.postal_code || '—' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- History -->
                    <section
                        v-if="kyc.history?.length"
                        class="kyc-card"
                        aria-label="Review history"
                    >
                        <div class="kyc-card-header">
                            <i class="fa fa-clock-rotate-left kyc-card-icon" aria-hidden="true"></i>
                            <h2 class="kyc-card-title">History</h2>
                        </div>

                        <ol class="kyc-timeline">
                            <li
                                v-for="(entry, i) in kyc.history"
                                :key="entry.id"
                                class="kyc-timeline-item"
                                :class="{ 'kyc-timeline-last': i === kyc.history.length - 1 }"
                            >
                                <span class="kyc-timeline-dot" aria-hidden="true"></span>
                                <div class="kyc-timeline-content">
                                    <p class="kyc-timeline-transition">
                                        <span class="kyc-timeline-from">{{ entry.from_status?.name }}</span>
                                        <i class="fa fa-arrow-right kyc-timeline-arrow" aria-hidden="true"></i>
                                        <span class="kyc-timeline-to">{{ entry.to_status?.name }}</span>
                                    </p>
                                    <p v-if="entry.notes" class="kyc-timeline-notes">{{ entry.notes }}</p>
                                    <time class="kyc-timeline-time">{{ formatDateTime(entry.created_at) }}</time>
                                </div>
                            </li>
                        </ol>
                    </section>

                </div>

                <!-- ── SIDEBAR ──────────────────────────── -->
                <aside class="kyc-sidebar">
                    <div class="kyc-sidebar-sticky">

                        <!-- Review summary -->
                        <div class="kyc-card">
                            <div class="kyc-card-header">
                                <i class="fa fa-circle-info kyc-card-icon" aria-hidden="true"></i>
                                <h2 class="kyc-card-title">Review summary</h2>
                            </div>

                            <dl class="kyc-sidebar-fields">
                                <div class="kyc-sidebar-field">
                                    <dt class="kyc-sidebar-label">Status</dt>
                                    <dd>
                                        <span class="kyc-status-pill kyc-status-pill--sm" :class="statusColor(kyc.status?.slug)">
                                            <span class="kyc-status-dot" :class="statusColor(kyc.status?.slug) + '-dot'"></span>
                                            {{ kyc.status?.name }}
                                        </span>
                                    </dd>
                                </div>

                                <div class="kyc-sidebar-field">
                                    <dt class="kyc-sidebar-label">Vendor</dt>
                                    <dd>
                                        <p class="kyc-sidebar-value">{{ kyc.user?.name }}</p>
                                        <p class="kyc-sidebar-muted">{{ kyc.user?.email }}</p>
                                    </dd>
                                </div>

                                <div v-if="kyc.reviewer" class="kyc-sidebar-field">
                                    <dt class="kyc-sidebar-label">Reviewed by</dt>
                                    <dd>
                                        <p class="kyc-sidebar-value">{{ kyc.reviewer?.name }}</p>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Actions -->
                        <div v-if="kyc.can_be_reviewed" class="kyc-card">
                            <div class="kyc-card-header">
                                <i class="fa fa-shield-halved kyc-card-icon" aria-hidden="true"></i>
                                <h2 class="kyc-card-title">Actions</h2>
                            </div>

                            <div class="kyc-actions">
                                <button
                                    class="kyc-btn kyc-btn-approve"
                                    @click="showApproveModal = true"
                                >
                                    <i class="fa fa-check" aria-hidden="true"></i>
                                    Approve KYC
                                </button>

                                <button
                                    class="kyc-btn kyc-btn-reject"
                                    @click="showRejectModal = true"
                                >
                                    <i class="fa fa-xmark" aria-hidden="true"></i>
                                    Reject KYC
                                </button>
                            </div>
                        </div>

                    </div>
                </aside>

            </div>
        </div>

        <!-- ─── APPROVE MODAL ─────────────────────────────────────────────── -->
        <Modal :show="showApproveModal" @close="showApproveModal = false">
            <div class="kyc-modal">
                <div class="kyc-modal-icon kyc-modal-icon--approve">
                    <i class="fa fa-check" aria-hidden="true"></i>
                </div>
                <h3 class="kyc-modal-title">Approve KYC submission</h3>
                <p class="kyc-modal-desc">
                    This will mark <strong>{{ kyc.user?.name }}</strong>'s KYC as approved. This action can be reviewed later.
                </p>

                <div class="kyc-modal-field">
                    <label for="approve-notes" class="kyc-modal-label">Notes <span class="kyc-optional">(optional)</span></label>
                    <textarea
                        id="approve-notes"
                        v-model="approveForm.notes"
                        rows="3"
                        class="kyc-textarea"
                        placeholder="Internal notes for this approval…"
                    ></textarea>
                </div>

                <div class="kyc-modal-footer">
                    <button class="kyc-btn kyc-btn-ghost" @click="showApproveModal = false">
                        Cancel
                    </button>
                    <button
                        class="kyc-btn kyc-btn-approve"
                        :disabled="approveForm.processing"
                        @click="submitApprove"
                    >
                        <i v-if="approveForm.processing" class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                        <i v-else class="fa fa-check" aria-hidden="true"></i>
                        Approve
                    </button>
                </div>
            </div>
        </Modal>

        <!-- ─── REJECT MODAL ──────────────────────────────────────────────── -->
        <Modal :show="showRejectModal" @close="showRejectModal = false">
            <div class="kyc-modal">
                <div class="kyc-modal-icon kyc-modal-icon--reject">
                    <i class="fa fa-xmark" aria-hidden="true"></i>
                </div>
                <h3 class="kyc-modal-title">Reject KYC submission</h3>
                <p class="kyc-modal-desc">
                    This will mark <strong>{{ kyc.user?.name }}</strong>'s KYC as rejected. They will be notified.
                </p>

                <div class="kyc-modal-field">
                    <label for="reject-notes" class="kyc-modal-label">Rejection reason <span class="kyc-required">*</span></label>
                    <textarea
                        id="reject-notes"
                        v-model="rejectForm.notes"
                        rows="3"
                        class="kyc-textarea"
                        placeholder="Explain why this submission is being rejected…"
                        required
                    ></textarea>
                    <p v-if="rejectForm.errors.notes" class="kyc-field-error">{{ rejectForm.errors.notes }}</p>
                </div>

                <div class="kyc-modal-footer">
                    <button class="kyc-btn kyc-btn-ghost" @click="showRejectModal = false">
                        Cancel
                    </button>
                    <button
                        class="kyc-btn kyc-btn-reject"
                        :disabled="rejectForm.processing"
                        @click="submitReject"
                    >
                        <i v-if="rejectForm.processing" class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                        <i v-else class="fa fa-xmark" aria-hidden="true"></i>
                        Reject
                    </button>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>
