<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import { useToast } from 'vue-toastification'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Modal from '@/Components/Modal.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'
import { useTranslation } from '@/Composables/useTranslation'

// ─── Types ────────────────────────────────────────────────────────
interface TranslationValue {
    id:           number
    locale:       string
    value_short:  string | null
    value:        string | null
    value_long:   string | null
    value_html:   string | null
    status:       'missing' | 'auto' | 'done'
    updater?:     { name: string } | null
    translated_at: string | null
    updated_at:   string
}

interface Translation {
    id:       number
    key:      string
    label:    string
    context:  string | null
    is_system: boolean
    values:   TranslationValue[]   // eager-loaded para a locale activa
    updated_at: string
}

const props = defineProps<{
    translations: any        // paginado pelo Inertia
    locales:      string[]
    groups:       string[]
    filters:      Record<string, any>
}>()

const { t } = useTranslation()
const toast  = useToast()

// ─── Helpers ─────────────────────────────────────────────────────
// Extrai o grupo do prefixo da key ("auth.login" → "auth")
const groupFromKey = (key: string) => key.split('.')[0] ?? key

// Extrai a subkey ("auth.login" → "login")
const subkeyFromKey = (key: string) => key.split('.').slice(1).join('.') || key

const truncate = (str: string | null, len = 55) =>
    str ? (str.length > len ? str.slice(0, len) + '…' : str) : null

const localeColor = (l: string) => {
    const map: Record<string, string> = {
        en: 'bg-blue-100 text-blue-700',
        pt: 'bg-green-100 text-green-700',
    }
    return map[l] ?? 'bg-gray-100 text-gray-600'
}

const statusColor = (status?: string) => {
    if (status === 'done')    return 'bg-green-100 text-green-700'
    if (status === 'auto')    return 'bg-yellow-100 text-yellow-700'
    return 'bg-gray-100 text-gray-400'
}

// Valor eager-loaded para a locale activa (values[0] porque o controller já filtrou)
const activeValue = (row: Translation): TranslationValue | null =>
    row.values?.[0] ?? null

// ─── Filters ─────────────────────────────────────────────────────
const search  = ref(props.filters?.filter?.search ?? '')
const locale  = ref(props.filters?.filter?.locale ?? props.locales[0] ?? 'en')
const group   = ref(props.filters?.filter?.group  ?? '')
const perPage = ref(props.filters?.per_page ?? 25)
const sort    = ref(props.filters?.sort ?? 'key')

let searchTimeout: ReturnType<typeof setTimeout>

const applyFilters = () => {
    router.get(route('admin.translations.index'), {
        filter: {
            search: search.value  || undefined,
            locale: locale.value  || undefined,
            group:  group.value   || undefined,
        },
        sort:     sort.value,
        per_page: perPage.value,
    }, { preserveState: true, replace: true })
}

const onSearchInput = () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 400)
}

const resetFilters = () => {
    search.value = ''
    group.value  = ''
    applyFilters()
}

const toggleSort = (field: string) => {
    sort.value = sort.value === field ? `-${field}` : field
    applyFilters()
}

const sortIcon = (field: string) => {
    if (sort.value === field)         return '↑'
    if (sort.value === `-${field}`)   return '↓'
    return '↕'
}

// ─── Edit Modal ───────────────────────────────────────────────────
const showEditModal    = ref(false)
const editLoading      = ref(false)
const activeEditLocale = ref(props.locales[0] ?? 'en')
const allLocaleValues  = ref<Record<string, TranslationValue>>({})
const editKey          = ref<string>('')
const editForms        = ref<Record<string, ReturnType<typeof useForm>>>({})

const openEdit = async (row: Translation) => {
    editKey.value          = row.key
    activeEditLocale.value = locale.value
    editLoading.value      = true
    showEditModal.value    = true
    allLocaleValues.value  = {}
    editForms.value        = {}

    try {
        const { data } = await axios.get(route('admin.translations.show'), {
            params: { key: row.key },
        })

        // data.values é um objecto keyed por locale: { en: TranslationValue, pt: TranslationValue }
        allLocaleValues.value = data.values ?? {}

        for (const l of props.locales) {
            const v = allLocaleValues.value[l]
            editForms.value[l] = useForm({
                value_short: v?.value_short ?? '',
                value:       v?.value       ?? '',
                value_long:  v?.value_long  ?? '',
                value_html:  v?.value_html  ?? '',
                status:      v?.status      ?? 'done',
            })
        }
    } finally {
        editLoading.value = false
    }
}

const saveAllLocales = async () => {
    const localesToSave = props.locales.filter(l =>
        editForms.value[l] &&
        (allLocaleValues.value[l] || editForms.value[l].value || editForms.value[l].value_short)
    )

    for (let i = 0; i < localesToSave.length; i++) {
        const l      = localesToSave[i]
        const isLast = i === localesToSave.length - 1
        const existing = allLocaleValues.value[l]

        await new Promise<void>((resolve) => {
            const options = {
                preserveState:  true,
                preserveScroll: true,
                onSuccess: () => {
                    if (isLast) {
                        showEditModal.value = false
                        toast.success('Translations saved successfully.')
                    }
                    resolve()
                },
                onError: () => {
                    activeEditLocale.value = l
                    resolve()
                },
            }

            if (existing) {
                // PATCH /admin/translation-values/{id}
                editForms.value[l].patch(
                    route('admin.translations.update', existing.id),
                    options
                )
            } else {
                // POST /admin/translations  (cria Translation + TranslationValue)
                editForms.value[l]
                    .transform((data: any) => ({
                        ...data,
                        key:    editKey.value,
                        locale: l,
                        is_last: isLast,
                    }))
                    .post(route('admin.translations.store'), options)
            }
        })
    }
}

const localeHasValue = (l: string) => !!allLocaleValues.value[l]

const editLocaleTabClass = (l: string) => {
    const isActive = activeEditLocale.value === l
    const exists   = localeHasValue(l)
    if (isActive) return 'border-b-2 border-indigo-500 text-indigo-700 font-semibold bg-indigo-50/50'
    if (!exists)  return 'text-gray-300 hover:text-gray-500'
    return 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
}

// ─── Create Modal ────────────────────────────────────────────────
const showCreateModal    = ref(false)
const activeCreateLocale = ref(props.locales[0] ?? 'en')
const createShared       = ref({ key: '', label: '', context: '' })
const createForms        = ref<Record<string, ReturnType<typeof useForm>>>({})

const initCreateForms = () => {
    createShared.value       = { key: '', label: '', context: '' }
    activeCreateLocale.value = props.locales[0] ?? 'en'
    createForms.value        = {}
    props.locales.forEach(l => {
        createForms.value[l] = useForm({
            value_short: '',
            value:       '',
            value_long:  '',
            value_html:  '',
            status:      'done',
        })
    })
}

initCreateForms()

const openCreate = () => {
    initCreateForms()
    showCreateModal.value = true
}

const createLocaleTabClass = (l: string) => {
    const isActive     = activeCreateLocale.value === l
    const form         = createForms.value[l]
    const hasSomething = form && (form.value || form.value_short)
    if (isActive)     return 'border-b-2 border-indigo-500 text-indigo-700 font-semibold bg-indigo-50/50'
    if (hasSomething) return 'text-green-600 hover:text-green-700 hover:bg-green-50'
    return 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'
}

const submitCreate = async () => {
    const localesToSubmit = props.locales.filter(l => {
        const form = createForms.value[l]
        return form.value || form.value_short
    })

    if (!localesToSubmit.length) return

    let hasError = false

    for (let i = 0; i < localesToSubmit.length; i++) {
        const l      = localesToSubmit[i]
        const isLast = i === localesToSubmit.length - 1

        await new Promise<void>((resolve) => {
            createForms.value[l]
                .transform((data: any) => ({
                    ...data,
                    key:     createShared.value.key,
                    locale:  l,
                    is_last: isLast,
                }))
                .post(route('admin.translations.store'), {
                    onSuccess: () => {
                        if (isLast) showCreateModal.value = false
                        resolve()
                    },
                    onError: () => {
                        hasError = true
                        activeCreateLocale.value = l
                        resolve()
                    },
                })
        })

        if (hasError) return
    }
}

// ─── Delete ──────────────────────────────────────────────────────
// Apaga o TranslationValue da locale activa (não o Translation pai)
const deleteTranslation = (row: Translation, e: Event) => {
    e.stopPropagation()
    const value = activeValue(row)
    if (!value) return

    const label = `${row.key} (${value.locale.toUpperCase()})`
    if (!confirm(`${t('common.delete')} "${label}"?`)) return

    router.delete(
        route('admin.translations.destroy', value.id),
        { preserveState: true }
    )
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ t('common.translations') }}</h2>
                    <p class="text-xs text-gray-400 mt-0.5">({{ translations?.total }} {{ t('common.total') }})</p>
                </div>
                <PrimaryButton @click="openCreate">+ {{ t('common.add_translation') }}</PrimaryButton>
            </div>
        </template>

        <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">

            <!-- Filters -->
            <div class="bg-white rounded-xl border border-gray-200 p-4">
                <div class="flex flex-wrap gap-3 items-center">
                    <div class="flex gap-1 bg-gray-100 rounded-lg p-1 shrink-0">
                        <button
                            v-for="l in locales" :key="l"
                            type="button"
                            @click="locale = l; applyFilters()"
                            class="px-4 py-1.5 rounded-md text-sm font-medium transition-all"
                            :class="locale === l ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'"
                        >
                            {{ l.toUpperCase() }}
                        </button>
                    </div>

                    <select v-model="group" @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2 px-3">
                        <option value="">{{ t('common.all_groups') }}</option>
                        <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
                    </select>

                    <input
                        v-model="search" @input="onSearchInput" type="text"
                        :placeholder="t('common.search_key_value')"
                        class="flex-1 min-w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-2 px-3"
                    />

                    <select v-model="perPage" @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3">
                        <option :value="25">25 / {{ t('common.page').toLowerCase() }}</option>
                        <option :value="50">50 / {{ t('common.page').toLowerCase() }}</option>
                        <option :value="100">100 / {{ t('common.page').toLowerCase() }}</option>
                    </select>

                    <button v-if="search || group" @click="resetFilters"
                        class="text-xs text-gray-400 hover:text-gray-600 underline">
                        {{ t('common.clear') }}
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium w-20">Status</th>
                            <th class="px-4 py-3 text-left font-medium w-24 cursor-pointer hover:text-gray-600 select-none"
                                @click="toggleSort('key')">
                                Group {{ sortIcon('key') }}
                            </th>
                            <th class="px-4 py-3 text-left font-medium w-56 cursor-pointer hover:text-gray-600 select-none"
                                @click="toggleSort('key')">
                                Key {{ sortIcon('key') }}
                            </th>
                            <th class="px-4 py-3 text-left font-medium w-28">Short</th>
                            <th class="px-4 py-3 text-left font-medium">Text</th>
                            <th class="px-4 py-3 text-left font-medium w-28 cursor-pointer hover:text-gray-600 select-none"
                                @click="toggleSort('updated_at')">
                                Updated {{ sortIcon('updated_at') }}
                            </th>
                            <th class="px-4 py-3 w-20"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="row in translations.data" :key="row.id"
                            class="hover:bg-indigo-50/30 transition-colors cursor-pointer"
                            @click="openEdit(row)"
                        >
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                    :class="statusColor(activeValue(row)?.status)">
                                    {{ activeValue(row)?.status ?? 'missing' }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">
                                {{ groupFromKey(row.key) }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-700 font-medium">
                                {{ subkeyFromKey(row.key) }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-500">
                                {{ activeValue(row)?.value_short ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-gray-700">
                                <span v-if="activeValue(row)?.value" class="text-sm">
                                    {{ truncate(activeValue(row)!.value) }}
                                </span>
                                <span v-else class="text-xs text-gray-300 italic">missing</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-400">
                                {{ activeValue(row)?.updater?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5" @click.stop>
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openEdit(row)"
                                        class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors" title="Edit">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                            <path d="M5.433 13.917l1.262-3.155A4 4 0 017.58 9.42l6.92-6.918a2.121 2.121 0 013 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 01-.65-.65z"/>
                                            <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0010 3H4.75A2.75 2.75 0 002 5.75v9.5A2.75 2.75 0 004.75 18h9.5A2.75 2.75 0 0017 15.25V10a.75.75 0 00-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5z"/>
                                        </svg>
                                    </button>
                                    <button @click="deleteTranslation(row, $event)"
                                        class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors" title="Delete">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!translations.data?.length">
                            <td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">
                                No translations found.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="translations.meta?.last_page > 1"
                    class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500">
                    <p>Page {{ translations.meta.current_page }} of {{ translations.meta.last_page }} ({{ translations.meta.total }} entries)</p>
                    <div class="flex gap-2">
                        <Link v-if="translations.links?.prev" :href="translations.links.prev"
                            class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">← Prev</Link>
                        <Link v-if="translations.links?.next" :href="translations.links.next"
                            class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Edit Modal ── -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="2xl">
            <div class="p-6">
                <div class="mb-5">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Translation</h3>
                    <p class="text-xs font-mono text-gray-400 mt-0.5">{{ editKey }}</p>
                </div>

                <div v-if="editLoading" class="py-10 text-center text-gray-400 text-sm">
                    <svg class="animate-spin w-6 h-6 mx-auto mb-2 text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    Loading translations...
                </div>

                <template v-else>
                    <!-- Locale tabs -->
                    <div class="flex border-b border-gray-200 mb-6">
                        <button
                            v-for="l in locales" :key="l"
                            type="button"
                            @click="activeEditLocale = l"
                            class="flex items-center gap-2 px-5 py-2.5 text-sm transition-colors"
                            :class="editLocaleTabClass(l)"
                        >
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold"
                                :class="localeColor(l)">{{ l.toUpperCase() }}</span>
                            <span class="w-2 h-2 rounded-full"
                                :class="localeHasValue(l) ? 'bg-green-400' : 'bg-gray-200'"
                                :title="localeHasValue(l) ? 'Translation exists' : 'Not yet translated'" />
                        </button>
                    </div>

                    <!-- Form por locale -->
                    <template v-for="l in locales" :key="l">
                        <div v-if="activeEditLocale === l && editForms[l]" class="space-y-5">
                            <div v-if="!localeHasValue(l)"
                                class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700 flex items-center gap-2">
                                <span>⚠️</span>
                                This translation doesn't exist for <strong>{{ l.toUpperCase() }}</strong> yet.
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Short text <span class="text-xs text-gray-400 font-normal ml-1">— badges, pills</span>
                                </label>
                                <TextInput v-model="editForms[l].value_short" class="w-full" placeholder="e.g. Pending" />
                                <InputError :message="editForms[l].errors.value_short" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Text <span class="text-red-500">*</span>
                                    <span class="text-xs text-gray-400 font-normal ml-1">— messages, labels</span>
                                </label>
                                <textarea v-model="editForms[l].value" rows="2"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="e.g. Your KYC is pending review" />
                                <InputError :message="editForms[l].errors.value" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Long text <span class="text-xs text-gray-400 font-normal ml-1">— descriptions</span>
                                </label>
                                <textarea v-model="editForms[l].value_long" rows="3"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <InputError :message="editForms[l].errors.value_long" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Rich text <span class="text-xs text-gray-400 font-normal ml-1">— HTML emails</span>
                                </label>
                                <RichTextEditor v-model="editForms[l].value_html" />
                                <InputError :message="editForms[l].errors.value_html" />
                            </div>

                            <!-- Status -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select v-model="editForms[l].status"
                                    class="rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="done">Done</option>
                                    <option value="auto">Auto</option>
                                    <option value="missing">Missing</option>
                                </select>
                            </div>

                            <div v-if="allLocaleValues[l]"
                                class="text-xs text-gray-400 flex gap-4 pt-1 border-t border-gray-100">
                                <span v-if="allLocaleValues[l].updater">
                                    Updated by {{ allLocaleValues[l].updater?.name }}
                                </span>
                                <span v-if="allLocaleValues[l].translated_at">
                                    at {{ allLocaleValues[l].translated_at }}
                                </span>
                            </div>
                        </div>
                    </template>

                    <div class="flex justify-end gap-3 mt-6">
                        <SecondaryButton type="button" @click="showEditModal = false">Close</SecondaryButton>
                        <PrimaryButton
                            @click="saveAllLocales"
                            :disabled="Object.values(editForms).some(f => f.processing)"
                        >
                            {{ Object.values(editForms).some(f => f.processing) ? 'Saving…' : 'Save all' }}
                        </PrimaryButton>
                    </div>
                </template>
            </div>
        </Modal>

        <!-- ── Create Modal ── -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="2xl">
            <div class="p-6 space-y-5">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Add Translation</h3>
                    <p class="text-xs text-gray-400 mt-0.5">
                        A key segue o formato <code class="font-mono bg-gray-100 px-1 rounded">group.subkey</code>
                        (ex: <code class="font-mono bg-gray-100 px-1 rounded">auth.login</code>).
                    </p>
                </div>

                <!-- Key + label + context -->
                <div class="grid grid-cols-2 gap-4 p-4 rounded-lg border border-gray-200">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Key <span class="text-red-500">*</span>
                            <span class="text-xs text-gray-400 font-normal ml-1">— ex: auth.login</span>
                        </label>
                        <input
                            v-model="createShared.key"
                            list="groups-list"
                            class="w-full rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            placeholder="auth.login"
                        />
                        <!-- Sugere os grupos existentes como prefixo -->
                        <datalist id="groups-list">
                            <option v-for="g in groups" :key="g" :value="g + '.'" />
                        </datalist>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Label</label>
                        <input
                            v-model="createShared.label"
                            class="w-full rounded-lg border border-gray-300 text-sm py-2 px-3 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                            placeholder="e.g. Login button"
                        />
                    </div>
                </div>

                <!-- Locale tabs -->
                <div class="flex border-b border-gray-200">
                    <button
                        v-for="l in locales" :key="l"
                        type="button"
                        @click="activeCreateLocale = l"
                        class="flex items-center gap-2 px-5 py-2.5 text-sm transition-colors"
                        :class="createLocaleTabClass(l)"
                    >
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold"
                            :class="localeColor(l)">{{ l.toUpperCase() }}</span>
                        <span class="w-2 h-2 rounded-full"
                            :class="createForms[l] && (createForms[l].value || createForms[l].value_short) ? 'bg-green-400' : 'bg-gray-200'" />
                    </button>
                </div>

                <!-- Form por locale -->
                <template v-for="l in locales" :key="l">
                    <div v-if="activeCreateLocale === l && createForms[l]" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Short text <span class="text-xs text-gray-400 font-normal ml-1">— badges, pills</span>
                            </label>
                            <TextInput v-model="createForms[l].value_short" class="w-full" placeholder="e.g. Pending" />
                            <InputError :message="createForms[l].errors.value_short" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Text <span class="text-xs text-gray-400 font-normal ml-1">— messages, labels</span>
                            </label>
                            <textarea v-model="createForms[l].value" rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="e.g. Your KYC is pending review" />
                            <InputError :message="createForms[l].errors.value" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Long text <span class="text-xs text-gray-400 font-normal ml-1">— descriptions</span>
                            </label>
                            <textarea v-model="createForms[l].value_long" rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            <InputError :message="createForms[l].errors.value_long" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Rich text <span class="text-xs text-gray-400 font-normal ml-1">— HTML emails</span>
                            </label>
                            <RichTextEditor v-model="createForms[l].value_html" />
                            <InputError :message="createForms[l].errors.value_html" />
                        </div>
                    </div>
                </template>

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <SecondaryButton type="button" @click="showCreateModal = false">Cancel</SecondaryButton>
                    <PrimaryButton
                        @click="submitCreate"
                        :disabled="Object.values(createForms).some(f => f.processing)"
                    >
                        {{ Object.values(createForms).some(f => f.processing) ? 'Creating…' : 'Create Translation' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>