<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
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
    id:            number
    locale:        string
    value_short:   string | null
    value:         string | null
    value_long:    string | null
    value_html:    string | null
    status:        'missing' | 'auto' | 'done'
    updater?:      { name: string } | null
    translated_at: string | null
    updated_at:    string
}

interface Translation {
    id:        number
    key:       string
    label:     string
    context:   string | null
    is_system: boolean
    values:    TranslationValue[]
    updated_at: string
}

const props = defineProps<{
    translations: any
    locales:      string[]
    groups:       string[]
    filters:      Record<string, any>
}>()

const { t } = useTranslation()
const toast  = useToast()

// ─── Helpers ─────────────────────────────────────────────────────
const groupFromKey  = (key: string) => key.split('.')[0] ?? key
const subkeyFromKey = (key: string) => key.split('.').slice(1).join('.') || key
const truncate      = (str: string | null, len = 45) =>
    str ? (str.length > len ? str.slice(0, len) + '…' : str) : null

const valueFor = (row: Translation, locale: string): TranslationValue | null =>
    row.values?.find(v => v.locale === locale) ?? null

const localeColor = (l: string): string => {
    const map: Record<string, string> = {
        en: 'bg-blue-100 text-blue-700',
        pt: 'bg-green-100 text-green-700',
        es: 'bg-amber-100 text-amber-700',
        fr: 'bg-pink-100 text-pink-700',
    }
    return map[l] ?? 'bg-gray-100 text-gray-600'
}

const statusColor = (status?: string | null) => {
    if (status === 'done')    return 'bg-green-100 text-green-700'
    if (status === 'auto')    return 'bg-yellow-100 text-yellow-700'
    return 'bg-gray-100 text-gray-400'
}

// ─── Filters ─────────────────────────────────────────────────────
const search  = ref(props.filters?.filter?.search ?? '')
const group   = ref(props.filters?.filter?.group  ?? '')
const perPage = ref(props.filters?.per_page ?? 25)
const sort    = ref(props.filters?.sort ?? 'key')

let searchTimeout: ReturnType<typeof setTimeout>

const applyFilters = () => {
    router.get(route('admin.translations.index'), {
        filter: {
            search: search.value || undefined,
            group:  group.value  || undefined,
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
    if (sort.value === field)        return '↑'
    if (sort.value === `-${field}`)  return '↓'
    return '↕'
}

// ─── Edit Modal ───────────────────────────────────────────────────
const showEditModal   = ref(false)
const editRow         = ref<Translation | null>(null)
const expandedLocales = ref<Set<string>>(new Set())
const editForms       = ref<Record<string, ReturnType<typeof useForm>>>({})

const openEdit = (row: Translation) => {
    editRow.value       = row
    expandedLocales.value = new Set(props.locales) // todas abertas por defeito
    editForms.value     = {}

    for (const l of props.locales) {
        const v = row.values?.find(val => val.locale === l)
        editForms.value[l] = useForm({
            value_short: v?.value_short ?? '',
            value:       v?.value       ?? '',
            value_long:  v?.value_long  ?? '',
            value_html:  v?.value_html  ?? '',
            status:      v?.status      ?? 'done',
        })
    }

    showEditModal.value = true
}

const toggleLocale = (locale: string) => {
    const s = new Set(expandedLocales.value)
    s.has(locale) ? s.delete(locale) : s.add(locale)
    expandedLocales.value = s
}

const isExpanded = (locale: string) => expandedLocales.value.has(locale)

const saveAllLocales = async () => {
    if (!editRow.value) return

    const localesToSave = props.locales.filter(l => {
        const form = editForms.value[l]
        const existing = valueFor(editRow.value!, l)
        return existing || form.value || form.value_short
    })

    for (let i = 0; i < localesToSave.length; i++) {
        const l        = localesToSave[i]
        const isLast   = i === localesToSave.length - 1
        const existing = valueFor(editRow.value!, l)

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
                    // abre a locale com erro
                    expandedLocales.value = new Set([...expandedLocales.value, l])
                    resolve()
                },
            }

            if (existing) {
                editForms.value[l].patch(
                    route('admin.translations.update', existing.id),
                    options
                )
            } else {
                editForms.value[l]
                    .transform((data: any) => ({
                        ...data,
                        key:     editRow.value!.key,
                        locale:  l,
                        is_last: isLast,
                    }))
                    .post(route('admin.translations.store'), options)
            }
        })
    }
}

// ─── Create Modal ────────────────────────────────────────────────
const showCreateModal    = ref(false)
const activeCreateLocale = ref(props.locales[0] ?? 'en')
const createShared       = ref({ key: '', label: '' })
const createForms        = ref<Record<string, ReturnType<typeof useForm>>>({})

const initCreateForms = () => {
    createShared.value       = { key: '', label: '' }
    activeCreateLocale.value = props.locales[0] ?? 'en'
    createForms.value        = {}
    props.locales.forEach(l => {
        createForms.value[l] = useForm({
            value_short: '',
            value:       '',
            value_long:  '',
            value_html:  '',
            status:      'done' as const,
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
    const localesToSubmit = props.locales.filter(l =>
        createForms.value[l]?.value || createForms.value[l]?.value_short
    )
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
                    label:   createShared.value.label || createShared.value.key,
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
const deleteTranslation = (row: Translation, e: Event) => {
    e.stopPropagation()
    if (!confirm(`Delete all translations for "${row.key}"?`)) return
    // Apaga o Translation pai — o controller apaga os values em cascade
    router.delete(route('admin.translations.destroy', row.id), {
        preserveState: true,
    })
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
                    <select
                        v-model="group"
                        @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">{{ t('common.all_groups') }}</option>
                        <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
                    </select>

                    <input
                        v-model="search"
                        @input="onSearchInput"
                        type="text"
                        :placeholder="t('common.search_key_value')"
                        class="flex-1 min-w-48 rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                    />

                    <select
                        v-model="perPage"
                        @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3"
                    >
                        <option :value="25">25 / {{ t('common.page').toLowerCase() }}</option>
                        <option :value="50">50 / {{ t('common.page').toLowerCase() }}</option>
                        <option :value="100">100 / {{ t('common.page').toLowerCase() }}</option>
                    </select>

                    <button
                        v-if="search || group"
                        @click="resetFilters"
                        class="text-xs text-gray-400 hover:text-gray-600 underline"
                    >
                        {{ t('common.clear') }}
                    </button>
                </div>
            </div>

            <!-- Table — scroll horizontal em mobile -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-400 uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium w-24 whitespace-nowrap">Group</th>
                                <th
                                    class="px-4 py-3 text-left font-medium w-48 whitespace-nowrap cursor-pointer hover:text-gray-600 select-none"
                                    @click="toggleSort('key')"
                                >
                                    Key {{ sortIcon('key') }}
                                </th>
                                <!-- coluna por locale -->
                                <th
                                    v-for="l in locales"
                                    :key="l"
                                    class="px-4 py-3 text-left font-medium whitespace-nowrap"
                                    style="min-width: 160px;"
                                >
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase"
                                        :class="localeColor(l)"
                                    >
                                        {{ l }}
                                    </span>
                                </th>
                                <th class="px-4 py-3 w-16"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr
                                v-for="row in translations.data"
                                :key="row.id"
                                class="hover:bg-indigo-50/30 transition-colors cursor-pointer"
                                @click="openEdit(row)"
                            >
                                <td class="px-4 py-3 font-mono text-xs text-gray-400 whitespace-nowrap">
                                    {{ groupFromKey(row.key) }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-700 font-medium whitespace-nowrap">
                                    {{ subkeyFromKey(row.key) }}
                                </td>
                                <!-- célula por locale -->
                                <td
                                    v-for="l in locales"
                                    :key="l"
                                    class="px-4 py-3"
                                    style="min-width: 160px; position: relative;"
                                >
                                    <template v-if="valueFor(row, l)">
                                        <p class="text-xs text-gray-700 leading-snug line-clamp-2">
                                            {{ truncate(valueFor(row, l)!.value) }}
                                        </p>
                                        <span
                                            class="inline-flex items-center mt-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                            style="position: absolute; right: 0; top: 0; margin-right: 5px;"
                                            :class="statusColor(valueFor(row, l)!.status)"
                                        >
                                            {{ valueFor(row, l)!.status }}
                                        </span>
                                    </template>
                                    <span v-else class="text-xs text-gray-300 italic">missing</span>
                                </td>
                                <td class="px-4 py-3" @click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            @click="openEdit(row)"
                                            class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors"
                                            title="Edit"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                                <path d="M5.433 13.917l1.262-3.155A4 4 0 017.58 9.42l6.92-6.918a2.121 2.121 0 013 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 01-.65-.65z"/>
                                                <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0010 3H4.75A2.75 2.75 0 002 5.75v9.5A2.75 2.75 0 004.75 18h9.5A2.75 2.75 0 0017 15.25V10a.75.75 0 00-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5z"/>
                                            </svg>
                                        </button>
                                        <button
                                            @click="deleteTranslation(row, $event)"
                                            class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors"
                                            title="Delete"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                                <path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 006 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 10.23 1.482l.149-.022.841 10.518A2.75 2.75 0 007.596 19h4.807a2.75 2.75 0 002.742-2.53l.841-10.52.149.023a.75.75 0 00.23-1.482A41.03 41.03 0 0014 4.193V3.75A2.75 2.75 0 0011.25 1h-2.5zM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4zM8.58 7.72a.75.75 0 00-1.5.06l.3 7.5a.75.75 0 101.5-.06l-.3-7.5zm4.34.06a.75.75 0 10-1.5-.06l-.3 7.5a.75.75 0 101.5.06l.3-7.5z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!translations.data?.length">
                                <td
                                    :colspan="2 + locales.length + 1"
                                    class="px-4 py-10 text-center text-gray-400 text-sm"
                                >
                                    No translations found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div
                    v-if="translations.meta?.last_page > 1"
                    class="px-4 py-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500"
                >
                    <p>Page {{ translations.meta.current_page }} of {{ translations.meta.last_page }} ({{ translations.meta.total }} entries)</p>
                    <div class="flex gap-2">
                        <Link
                            v-if="translations.links?.prev"
                            :href="translations.links.prev"
                            class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50"
                        >← Prev</Link>
                        <Link
                            v-if="translations.links?.next"
                            :href="translations.links.next"
                            class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50"
                        >Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Edit Modal ── -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="4xl">
            <div class="p-6">
                <!-- Header -->
                <div class="mb-5">
                    <h3 class="text-lg font-semibold text-gray-800">Edit translation</h3>
                    <p class="text-xs font-mono text-gray-400 mt-0.5" v-if="editRow">{{ editRow.key }}</p>
                </div>

                <!-- Uma secção por locale, colapsável -->
                <div class="divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden">
                    <div v-for="l in locales" :key="l">

                        <!-- Cabeçalho da locale — clicável -->
                        <button
                            type="button"
                            class="w-full flex items-center gap-3 px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
                            @click="toggleLocale(l)"
                        >
                            <span
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase shrink-0"
                                :class="localeColor(l)"
                            >{{ l }}</span>

                            <!-- preview do valor actual -->
                            <span class="flex-1 text-xs text-gray-500 truncate">
                                <template v-if="editRow && valueFor(editRow, l)">
                                    {{ truncate(valueFor(editRow, l)!.value, 60) }}
                                </template>
                                <span v-else class="italic text-gray-300">not translated</span>
                            </span>

                            <!-- status badge -->
                            <span
                                v-if="editRow"
                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium shrink-0"
                                :class="statusColor(valueFor(editRow, l)?.status)"
                            >
                                {{ valueFor(editRow, l)?.status ?? 'missing' }}
                            </span>

                            <!-- chevron -->
                            <svg
                                xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                                class="w-4 h-4 text-gray-400 shrink-0 transition-transform duration-150"
                                :class="isExpanded(l) ? 'rotate-180' : ''"
                            >
                                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>

                        <!-- Corpo expansível -->
                        <div v-if="isExpanded(l) && editForms[l]" class="px-4 py-4 space-y-4 bg-white">

                            <!-- aviso se ainda não existe -->
                            <div
                                v-if="editRow && !valueFor(editRow, l)"
                                class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700"
                            >
                                <span>⚠️</span>
                                <span>Not yet translated for <strong>{{ l.toUpperCase() }}</strong>. Fill in and save to create.</span>
                            </div>

                            <div class="">
                                
                                <!-- status -->
                                <!-- <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                                    <select
                                        v-model="editForms[l].status"
                                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3 focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="done">Done</option>
                                        <option value="auto">Auto</option>
                                        <option value="missing">Missing</option>
                                    </select>
                                </div> -->

                                <!-- value_short -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Short <span class="text-gray-400 font-normal ml-1">— badges</span>
                                    </label>
                                    <TextInput v-model="editForms[l].value_short" class="w-full text-sm" placeholder="e.g. Pending" />
                                    <InputError :message="editForms[l].errors.value_short" />
                                </div>

                                <!-- value (ocupa toda a largura) -->
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Text <span class="text-red-500">*</span>
                                        <span class="text-gray-400 font-normal ml-1">— messages, labels</span>
                                    </label>
                                    
                                    <textarea
                                        v-model="editForms[l].value"
                                        rows="2"
                                        class="w-full h-1 resize-none rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <InputError :message="editForms[l].errors.value" />
                                </div>

                                <!-- value_long -->
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Long <span class="text-gray-400 font-normal ml-1">— descriptions</span>
                                    </label>
                                    <textarea
                                        v-model="editForms[l].value_long"
                                        rows="2"
                                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <InputError :message="editForms[l].errors.value_long" />
                                </div>

                                <!-- value_html -->
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        Rich text <span class="text-gray-400 font-normal ml-1">— HTML emails</span>
                                    </label>
                                    <RichTextEditor v-model="editForms[l].value_html" />
                                    <InputError :message="editForms[l].errors.value_html" />
                                </div>
                            </div>

                            <!-- meta -->
                            <div
                                v-if="editRow && valueFor(editRow, l)?.updater"
                                class="text-xs text-gray-400 border-t border-gray-100 pt-2"
                            >
                                Updated by {{ valueFor(editRow, l)!.updater?.name }}
                                <template v-if="valueFor(editRow, l)?.translated_at">
                                    · {{ valueFor(editRow, l)!.translated_at }}
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex justify-end gap-3 mt-6">
                    <SecondaryButton type="button" @click="showEditModal = false">Close</SecondaryButton>
                    <PrimaryButton
                        @click="saveAllLocales"
                        :disabled="Object.values(editForms).some(f => f.processing)"
                    >
                        {{ Object.values(editForms).some(f => f.processing) ? 'Saving…' : 'Save all' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- ── Create Modal ── -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="2xl">
            <div class="p-6 space-y-5">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Add translation</h3>
                    <p class="text-xs text-gray-400 mt-0.5">
                        A key segue o formato <code class="font-mono bg-gray-100 px-1 rounded">group.subkey</code>
                        (ex: <code class="font-mono bg-gray-100 px-1 rounded">auth.login</code>).
                    </p>
                </div>

                <!-- Key + label -->
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
                        <datalist id="groups-list">
                            <option v-for="g in groups" :key="g" :value="g + '.'" />
                        </datalist>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Label <span class="text-xs text-gray-400 font-normal ml-1">— nome legível, opcional</span>
                        </label>
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
                        <span
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase"
                            :class="localeColor(l)"
                        >{{ l }}</span>
                        <span
                            class="w-2 h-2 rounded-full"
                            :class="createForms[l] && (createForms[l].value || createForms[l].value_short) ? 'bg-green-400' : 'bg-gray-200'"
                        />
                    </button>
                </div>

                <!-- Form por locale -->
                <template v-for="l in locales" :key="l">
                    <div v-if="activeCreateLocale === l && createForms[l]" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Text <span class="text-xs text-gray-400 font-normal ml-1">— messages, labels</span>
                            </label>
                            <textarea
                                v-model="createForms[l].value"
                                rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <InputError :message="createForms[l].errors.value" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Short</label>
                                <TextInput v-model="createForms[l].value_short" class="w-full" placeholder="e.g. Pending" />
                                <InputError :message="createForms[l].errors.value_short" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select
                                    v-model="createForms[l].status"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm py-2 px-3"
                                >
                                    <option value="done">Done</option>
                                    <option value="auto">Auto</option>
                                    <option value="missing">Missing</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Long</label>
                            <textarea
                                v-model="createForms[l].value_long"
                                rows="2"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Rich text</label>
                            <RichTextEditor v-model="createForms[l].value_html" />
                        </div>
                    </div>
                </template>

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <SecondaryButton type="button" @click="showCreateModal = false">Cancel</SecondaryButton>
                    <PrimaryButton
                        @click="submitCreate"
                        :disabled="Object.values(createForms).some(f => f.processing)"
                    >
                        {{ Object.values(createForms).some(f => f.processing) ? 'Creating…' : 'Create translation' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>