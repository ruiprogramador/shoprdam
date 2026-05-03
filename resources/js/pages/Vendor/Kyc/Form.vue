<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import VerticalLayout from '@/Layouts/VerticalLayout.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FileUpload from '@/Components/FileUpload.vue'
import SearchSelect from '@/Components/SearchSelect.vue'
import { useLocationData } from '@/Composables/useLocationData'

const props = defineProps<{
    kyc?: any
    countries: { id: number; name: string; flag_emoji: string }[]
    genders: { id: number; name: string; slug: string }[]
    document_types: { id: number; name: string }[]
    document_sides: { id: number; name: string; slug: string }[]
    states?: { id: number; name: string }[]
    cities?: { id: number; name: string }[]
}>()

const isEditing = computed(() => !!props.kyc)

const {
    states,
    cities,
    loadingStates,
    loadingCities,
    locationError,
    fetchStates,
    fetchCities,
} = useLocationData()

if (props.states?.length) states.value = props.states
if (props.cities?.length) cities.value = props.cities

const form = useForm({
    full_name:     props.kyc?.full_name ?? '',
    date_of_birth: props.kyc?.date_of_birth ?? '',
    gender_id:     props.kyc?.gender_id ?? '',
    gender_other:  props.kyc?.gender_other ?? '',
    country_id:    props.kyc?.country?.id ?? '',
    state_id:      props.kyc?.state?.id ?? '',
    city_id:       props.kyc?.city?.id ?? '',
    address_line1: props.kyc?.address_line1 ?? '',
    address_line2: props.kyc?.address_line2 ?? '',
    postal_code:   props.kyc?.postal_code ?? '',
    documents: [] as { document_type_id: string; document_side_id: string; file: File | null }[],
})

// ─── Clear errors on change ──────────────────────────────────────
// Inertia does not auto-clear errors when the user edits a field.
// We watch each field and clear its error as soon as the value changes.
const simpleFields = [
    'full_name', 'date_of_birth', 'gender_id', 'gender_other',
    'country_id', 'state_id', 'city_id',
    'address_line1', 'address_line2', 'postal_code',
] as const

simpleFields.forEach((field) => {
    watch(() => form[field], () => {
        if (form.errors[field]) form.clearErrors(field)
    })
})

// For documents array — watch each document's fields individually
watch(
    () => form.documents.map(d => ({ ...d })),
    (docs, oldDocs) => {
        docs.forEach((doc, index) => {
            const old = oldDocs?.[index]
            if (doc.document_type_id !== old?.document_type_id) {
                form.clearErrors(`documents.${index}.document_type_id` as any)
            }
            if (doc.document_side_id !== old?.document_side_id) {
                form.clearErrors(`documents.${index}.document_side_id` as any)
            }
            if (doc.file !== old?.file) {
                form.clearErrors(`documents.${index}.file` as any)
            }
        })
    },
    { deep: true }
)

// ─── Location ────────────────────────────────────────────────────
const customGender = computed(() => props.genders.find(g => g.slug === 'custom'))
const isCustomGender = computed(() => form.gender_id == customGender.value?.id)

watch(() => form.country_id, async (countryId) => {
    form.state_id = ''
    form.city_id = ''
    if (countryId) await fetchStates(countryId)
})

watch(() => form.state_id, async (stateId) => {
    form.city_id = ''
    if (stateId) await fetchCities(stateId)
})

// ─── Documents ───────────────────────────────────────────────────
const sideIcon = (slug: string) => {
    const icons: Record<string, string> = {
        front:          '🪪',
        back:           '🔄',
        selfie:         '🤳',
        liveness_video: '🎥',
    }
    return icons[slug] ?? '📄'
}

const addDocument = () => {
    form.documents.push({ document_type_id: '', document_side_id: '', file: null })
}

const removeDocument = (index: number) => {
    form.documents.splice(index, 1)
}

const onDocumentTypeChange = (index: number) => {
    form.documents[index].document_side_id = ''
    form.clearErrors(`documents.${index}.document_type_id` as any)
}

if (!form.documents.length) addDocument()

// ─── Steps ───────────────────────────────────────────────────────
const step = ref(1)
const totalSteps = 3
const stepLabels = ['Personal', 'Address', 'Documents']

const stepFields: Record<number, string[]> = {
    1: ['full_name', 'date_of_birth', 'gender_id', 'gender_other'],
    2: ['country_id', 'state_id', 'city_id', 'address_line1', 'address_line2', 'postal_code'],
    3: ['documents'],
}

const stepHasErrors = (s: number) => {
    const fields = stepFields[s]
    return Object.keys(form.errors).some(key =>
        fields.some(f => key === f || key.startsWith(f + '.') || key.startsWith(f + '['))
    )
}

const scrollTop = () => nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }))
const nextStep = () => { if (step.value < totalSteps) { step.value++; scrollTop() } }
const prevStep = () => { if (step.value > 1) { step.value--; scrollTop() } }
const goToStep = (s: number) => { step.value = s; scrollTop() }

const goToFirstError = () => {
    for (let s = 1; s <= totalSteps; s++) {
        if (stepHasErrors(s)) { goToStep(s); return }
    }
}

// ─── Submit ──────────────────────────────────────────────────────
const submit = () => {
    const routeName = isEditing.value ? 'vendor.kyc.update' : 'vendor.kyc.store'
    form.post(route(routeName), {
        forceFormData: true,
        onError: () => goToFirstError(),
    })
}
</script>

<template>
    <VerticalLayout>
        <div class="max-w-3xl mx-auto py-6 px-4">

            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-bold text-gray-900">
                    {{ isEditing ? 'Edit KYC' : 'Submit KYC' }}
                </h1>
            </div>

            <!-- Location Error -->
            <div v-if="locationError" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
                <p class="text-sm text-red-600">{{ locationError }}</p>
            </div>

            <!-- Step indicators -->
            <div class="flex items-center mb-6">
                <template v-for="s in totalSteps" :key="s">
                    <div class="flex flex-col items-center cursor-pointer" @click="goToStep(s)">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors"
                            :class="[
                                step === s
                                    ? 'bg-indigo-600 text-white'
                                    : stepHasErrors(s)
                                        ? 'bg-red-100 text-red-600 ring-2 ring-red-400'
                                        : 'bg-gray-200 text-gray-600 hover:bg-gray-300',
                            ]"
                        >
                            <svg v-if="stepHasErrors(s) && step !== s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
                            </svg>
                            <span v-else>{{ s }}</span>
                        </div>
                        <p class="text-xs mt-1 text-gray-500">{{ stepLabels[s - 1] }}</p>
                    </div>
                    <div v-if="s < totalSteps" class="flex-1 h-px bg-gray-200 mx-2 mb-4" />
                </template>
            </div>

            <form @submit.prevent="submit" class="space-y-6">

                <!-- STEP 1: Personal Info -->
                <div v-if="step === 1" class="bg-white rounded-lg shadow p-6 space-y-4">
                    <div>
                        <h2 class="text-xl font-semibold">Personal Information</h2>
                        <p class="text-sm text-gray-500">This must match your official identification document.</p>
                    </div>

                    <div>
                        <InputLabel value="Full Name" />
                        <TextInput
                            v-model="form.full_name"
                            :disabled="isEditing"
                            class="mt-1 w-full"
                            :class="{ 'opacity-60 cursor-not-allowed': isEditing }"
                        />
                        <p v-if="isEditing" class="text-xs text-gray-400 mt-1">Cannot be changed after submission.</p>
                        <InputError :message="form.errors.full_name" />
                    </div>

                    <div>
                        <InputLabel value="Date of Birth" />
                        <input
                            type="date"
                            v-model="form.date_of_birth"
                            :disabled="isEditing"
                            class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            :class="{ 'opacity-60 cursor-not-allowed': isEditing }"
                        />
                        <p class="text-xs text-gray-400 mt-1">You must be at least 18 years old.</p>
                        <InputError :message="form.errors.date_of_birth" />
                    </div>

                    <div>
                        <InputLabel value="Gender" />
                        <SearchSelect
                            v-model="form.gender_id"
                            :options="genders"
                            label="name"
                            value="id"
                            placeholder="Search gender..."
                            clearable
                        >
                            <template #option="{ option }">{{ option.name }}</template>
                        </SearchSelect>
                        <InputError :message="form.errors.gender_id" />
                    </div>

                    <div v-if="isCustomGender">
                        <InputLabel value="Specify Gender" />
                        <TextInput v-model="form.gender_other" class="mt-1 w-full" />
                        <InputError :message="form.errors.gender_other" />
                    </div>
                </div>

                <!-- STEP 2: Address -->
                <div v-if="step === 2" class="bg-white rounded-lg shadow p-6 space-y-4">
                    <div>
                        <h2 class="text-xl font-semibold">Address</h2>
                        <p class="text-sm text-gray-500">Enter your current residential address.</p>
                    </div>

                    <div>
                        <InputLabel value="Country" />
                        <SearchSelect
                            v-model="form.country_id"
                            :options="countries"
                            label="name"
                            value="id"
                            placeholder="Search country..."
                            clearable
                        >
                            <template #option="{ option }">
                                <span>{{ option.flag_emoji }}</span> {{ option.name }}
                            </template>
                        </SearchSelect>
                        <InputError :message="form.errors.country_id" />
                    </div>

                    <div>
                        <InputLabel value="State / Province" />
                        <SearchSelect
                            v-model="form.state_id"
                            :options="states"
                            label="name"
                            value="id"
                            placeholder="Search state..."
                            :disabled="!states.length && !loadingStates"
                            :loading="loadingStates"
                            clearable
                        />
                        <p v-if="!form.country_id" class="text-xs text-gray-400 mt-1">Select a country first.</p>
                        <InputError :message="form.errors.state_id" />
                    </div>

                    <div>
                        <InputLabel value="City" />
                        <SearchSelect
                            v-model="form.city_id"
                            :options="cities"
                            label="name"
                            value="id"
                            placeholder="Search city..."
                            :disabled="!cities.length && !loadingCities"
                            :loading="loadingCities"
                            clearable
                        />
                        <p v-if="!form.state_id" class="text-xs text-gray-400 mt-1">Select a state first.</p>
                        <InputError :message="form.errors.city_id" />
                    </div>

                    <div>
                        <InputLabel value="Postal Code" />
                        <TextInput v-model="form.postal_code" class="mt-1 w-full" />
                        <InputError :message="form.errors.postal_code" />
                    </div>

                    <div>
                        <InputLabel value="Address Line 1" />
                        <TextInput v-model="form.address_line1" class="mt-1 w-full" />
                        <InputError :message="form.errors.address_line1" />
                    </div>

                    <div>
                        <InputLabel value="Address Line 2 (optional)" />
                        <TextInput v-model="form.address_line2" class="mt-1 w-full" />
                        <InputError :message="form.errors.address_line2" />
                    </div>
                </div>

                <!-- STEP 3: Documents -->
                <div v-if="step === 3" class="bg-white rounded-lg shadow p-6 space-y-4">
                    <div>
                        <h2 class="text-xl font-semibold">Documents</h2>
                        <p class="text-sm text-gray-500">
                            Upload a valid identification document. Each document requires its own upload.
                        </p>
                    </div>

                    <div
                        v-if="!document_types.length"
                        class="p-4 bg-yellow-50 border border-yellow-200 rounded-md"
                    >
                        <p class="text-sm text-yellow-700">
                            ⚠️ No document types are available yet. Please contact support.
                        </p>
                    </div>

                    <InputError :message="form.errors.documents" />

                    <div
                        v-for="(doc, index) in form.documents"
                        :key="index"
                        class="border border-gray-200 rounded-xl p-5 space-y-4 bg-gray-50"
                    >
                        <div class="flex justify-between items-center">
                            <p class="text-sm font-semibold text-gray-700">Document {{ index + 1 }}</p>
                            <button
                                v-if="form.documents.length > 1"
                                type="button"
                                @click="removeDocument(index)"
                                class="text-xs text-red-500 hover:text-red-700 transition-colors"
                            >
                                Remove
                            </button>
                        </div>

                        <!-- Document Type -->
                        <div>
                            <InputLabel value="1. What type of document are you uploading?" />
                            <SearchSelect
                                v-model="doc.document_type_id"
                                :options="document_types"
                                label="name"
                                value="id"
                                placeholder="e.g. Passport, Driving Licence..."
                                :no-results-text="document_types.length ? 'No results found' : 'No document types available'"
                                class="mt-1"
                                @update:modelValue="onDocumentTypeChange(index)"
                            />
                            <InputError :message="form.errors[`documents.${index}.document_type_id`]" />
                        </div>

                        <!-- Document Side pills -->
                        <Transition name="fade">
                            <div v-if="doc.document_type_id && document_sides.length">
                                <InputLabel value="2. Which part of the document are you uploading?" />
                                <p class="text-xs text-gray-400 mb-2 mt-0.5">
                                    Skip this if your document is a single page (e.g. most passports).
                                </p>
                                <div class="flex flex-wrap gap-2 mt-1">
                                    <button
                                        type="button"
                                        @click="doc.document_side_id = ''"
                                        class="px-3 py-1.5 text-xs rounded-full border transition-colors"
                                        :class="!doc.document_side_id
                                            ? 'bg-gray-700 text-white border-gray-700'
                                            : 'border-gray-300 text-gray-500 hover:border-gray-400'"
                                    >
                                        Not applicable
                                    </button>
                                    <button
                                        v-for="side in document_sides"
                                        :key="side.id"
                                        type="button"
                                        @click="doc.document_side_id = String(side.id)"
                                        class="px-3 py-1.5 text-xs rounded-full border transition-colors flex items-center gap-1.5"
                                        :class="doc.document_side_id === String(side.id)
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'border-gray-300 text-gray-600 hover:border-indigo-400 hover:text-indigo-600'"
                                    >
                                        <span>{{ sideIcon(side.slug) }}</span>
                                        {{ side.name }}
                                    </button>
                                </div>
                                <InputError :message="form.errors[`documents.${index}.document_side_id`]" />
                            </div>
                        </Transition>

                        <!-- File Upload -->
                        <div>
                            <InputLabel
                                :value="doc.document_type_id ? '3. Upload the file' : '2. Upload the file'"
                            />
                            <p class="text-xs text-gray-400 mb-2 mt-0.5">
                                Accepted formats: JPG, PNG, PDF · Max size: 5MB
                            </p>
                            <FileUpload
                                :name="`documents[${index}][file]`"
                                accepted-file-types="image/jpeg, image/png, application/pdf"
                                max-file-size="5MB"
                                label-idle='Drag & drop or <span class="filepond--label-action">browse</span>'
                                @update:src="(file: File) => { doc.file = file; form.clearErrors(`documents.${index}.file` as any) }"
                            />
                            <InputError :message="form.errors[`documents.${index}.file`]" />
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="addDocument"
                        class="w-full py-3 border-2 border-dashed border-gray-300 rounded-xl text-sm text-gray-500 hover:border-indigo-400 hover:text-indigo-600 transition-colors"
                    >
                        + Add another document
                    </button>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between pt-2">
                    <SecondaryButton v-if="step > 1" type="button" @click="prevStep">← Back</SecondaryButton>
                    <div v-else />

                    <div class="flex gap-3">
                        <Link :href="route('vendor.kyc.show')">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton v-if="step < totalSteps" type="button" @click="nextStep">
                            Next →
                        </PrimaryButton>
                        <PrimaryButton v-else type="submit" :disabled="form.processing">
                            {{ form.processing ? 'Submitting...' : isEditing ? 'Update KYC' : 'Submit KYC' }}
                        </PrimaryButton>
                    </div>
                </div>

            </form>
        </div>
    </VerticalLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>