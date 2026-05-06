<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import axios from 'axios'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Modal from '@/Components/Modal.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import TextInput from '@/Components/TextInput.vue'
import { useTranslation } from '@/Composables/useTranslation'

interface TranslationVariant {
    id: number
    locale: string
    group: string
    key: string
    text_short: string | null
    text: string | null
    text_long: string | null
    text_html: string | null
    creator?: { name: string } | null
    updater?: { name: string } | null
    updated_at: string
}

const props = defineProps<{
    translations: any
    locales: string[]
    groups: string[]
    filters: Record<string, any>
}>()

// ─── Filters ─────────────────────────────────────────────────────
const search   = ref(props.filters?.filter?.search ?? '')
const locale   = ref(props.filters?.filter?.locale ?? '')
const group    = ref(props.filters?.filter?.group ?? '')
const perPage  = ref(props.filters?.per_page ?? 25)
const sort     = ref(props.filters?.sort ?? 'group')

const { t, ts } = useTranslation()

let searchTimeout: ReturnType<typeof setTimeout>

const applyFilters = () => {
    router.get(route('admin.translations.index'), {
        filter: {
            search: search.value || undefined,
            locale: locale.value || undefined,
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
    search.value = ''; locale.value = ''; group.value = ''
    applyFilters()
}

const toggleSort = (field: string) => {
    sort.value = sort.value === field ? `-${field}` : field
    applyFilters()
}

const sortIcon = (field: string) => {
    if (sort.value === field) return '↑'
    if (sort.value === `-${field}`) return '↓'
    return '↕'
}

// ─── Edit Modal ───────────────────────────────────────────────────
const showEditModal   = ref(false)
const showCreateModal = ref(false)
const editLoading     = ref(false)
const activeLocale    = ref('en')
const allLocaleData   = ref<Record<string, TranslationVariant>>({})
const editRow         = ref<{ group: string; key: string } | null>(null)

// Forms per locale — dynamically created
const editForms = ref<Record<string, ReturnType<typeof useForm>>>({})

const openEdit = async (row: TranslationVariant) => {
    editRow.value   = { group: row.group, key: row.key }
    activeLocale.value = row.locale
    editLoading.value  = true
    showEditModal.value = true

    try {
        const { data } = await axios.get(route('admin.translations.show'), {
            params: { group: row.group, key: row.key },
        })

        allLocaleData.value = data

        // Create a form for each locale
        editForms.value = {}
        for (const l of props.locales) {
            const t = data[l]
            editForms.value[l] = useForm({
                text_short: t?.text_short ?? '',
                text:       t?.text ?? '',
                text_long:  t?.text_long ?? '',
                text_html:  t?.text_html ?? '',
            })
        }
    } finally {
        editLoading.value = false
    }
}

const saveLocale = (l: string) => {
    if (!editRow.value) return
    const existing = allLocaleData.value[l]
    const form = editForms.value[l]

    if (existing) {
        form.patch(route('admin.translations.update', existing.id), {
            onSuccess: () => {
                // Refresh locale data
                allLocaleData.value[l] = {
                    ...existing,
                    text_short: form.text_short,
                    text:       form.text,
                    text_long:  form.text_long,
                    text_html:  form.text_html,
                }
            },
        })
    } else {
        // Create for this locale
        form.transform(data => ({
            ...data,
            locale: l,
            group:  editRow.value!.group,
            key:    editRow.value!.key,
        })).post(route('admin.translations.store'), {
            onSuccess: () => {
                router.reload({ only: ['translations'] })
            },
        })
    }
}

const localeExists = (l: string) => !!allLocaleData.value[l]

const localeTabClass = (l: string) => {
    const isActive = activeLocale.value === l
    const exists   = localeExists(l)
    if (isActive) return 'border-b-2 border-indigo-500 text-indigo-700 font-semibold'
    if (!exists)  return 'text-gray-300 hover:text-gray-500'
    return 'text-gray-500 hover:text-gray-700'
}

// ─── Create Modal ────────────────────────────────────────────────
const createForm = useForm({
    locale:     'en',
    group:      '',
    key:        '',
    text_short: '',
    text:       '',
    text_long:  '',
    text_html:  '',
})

const submitCreate = () => {
    createForm.post(route('admin.translations.store'), {
        onSuccess: () => {
            showCreateModal.value = false
            createForm.reset()
        },
    })
}

// ─── Delete ──────────────────────────────────────────────────────
const deleteTranslation = (translation: TranslationVariant, e: Event) => {
    e.stopPropagation()
    if (!confirm(`Delete "${translation.group}.${translation.key}" (${translation.locale.toUpperCase()})?`)) return
    router.delete(route('admin.translations.destroy', translation.id), {
        preserveState: true,
    })
}

// ─── Helpers ─────────────────────────────────────────────────────
const truncate = (str: string | null, len = 50) =>
    str ? (str.length > len ? str.slice(0, len) + '...' : str) : null

const localeColor = (l: string): string => {
    const map: Record<string, string> = {
        en: 'bg-blue-100 text-blue-700',
        pt: 'bg-green-100 text-green-700',
    }
    return map[l] ?? 'bg-gray-100 text-gray-600'
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">{{ t('admin.translations.title') ?? 'Translations' }}</h2>
                    <p class="text-xs text-gray-400 mt-0.5">{{ translations.meta?.total ?? 0 }} entries</p>
                </div>
                <PrimaryButton @click="showCreateModal = true">+ Add</PrimaryButton>
            </div>
        </template>

        <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">

            <!-- Filters -->
            <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
                <div class="flex flex-wrap gap-3 items-center">
                    <!-- Search -->
                    <input
                        v-model="search"
                        @input="onSearchInput"
                        type="text"
                        placeholder="Search key or value..."
                        class="flex-1 min-w-48 rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />

                    <!-- Locale -->
                    <select
                        v-model="locale"
                        @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm"
                    >
                        <option value="">All locales</option>
                        <option v-for="l in locales" :key="l" :value="l">{{ l.toUpperCase() }}</option>
                    </select>

                    <!-- Group -->
                    <select
                        v-model="group"
                        @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm"
                    >
                        <option value="">All groups</option>
                        <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
                    </select>

                    <!-- Per page -->
                    <select
                        v-model="perPage"
                        @change="applyFilters"
                        class="rounded-lg border-gray-300 text-sm shadow-sm"
                    >
                        <option :value="25">25</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>

                    <button
                        v-if="search || locale || group"
                        @click="resetFilters"
                        class="text-xs text-gray-400 hover:text-gray-600 underline"
                    >
                        Clear
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium w-16">Locale</th>
                            <th
                                class="px-4 py-3 text-left font-medium w-28 cursor-pointer hover:text-gray-600"
                                @click="toggleSort('group')"
                            >
                                Group {{ sortIcon('group') }}
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium w-56 cursor-pointer hover:text-gray-600"
                                @click="toggleSort('key')"
                            >
                                Key {{ sortIcon('key') }}
                            </th>
                            <th class="px-4 py-3 text-left font-medium w-28">Short</th>
                            <th class="px-4 py-3 text-left font-medium">Text</th>
                            <th
                                class="px-4 py-3 text-left font-medium w-28 cursor-pointer hover:text-gray-600"
                                @click="toggleSort('updated_at')"
                            >
                                Updated {{ sortIcon('updated_at') }}
                            </th>
                            <th class="px-4 py-3 w-16"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr
                            v-for="translation in translations.data"
                            :key="translation.id"
                            class="hover:bg-indigo-50/40 transition-colors cursor-pointer group"
                            @click="openEdit(translation)"
                        >
                            <td class="px-4 py-2.5">
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                                    :class="localeColor(translation.locale)"
                                >
                                    {{ translation.locale.toUpperCase() }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ translation.group }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-700 font-medium">{{ translation.key }}</td>
                            <td class="px-4 py-2.5 text-xs text-gray-500">{{ translation.text_short ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-gray-700">
                                <span
                                    v-if="translation.text"
                                    class="text-sm"
                                >
                                    {{ truncate(translation.text) }}
                                </span>
                                <span v-else class="text-xs text-gray-300 italic">empty</span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-gray-400">
                                {{ translation.updater?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5" @click.stop>
                                <div class="flex items-center justify-end gap-1">
                                    <!-- Edit icon -->
                                    <button
                                        @click="openEdit(translation)"
                                        class="p-1.5 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-md transition-colors"
                                        title="Edit"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                            <path d="M5.433 13.917l1.262-3.155A4 4 0 017.58 9.42l6.92-6.918a2.121 2.121 0 013 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 01-.65-.65z"/>
                                            <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0010 3H4.75A2.75 2.75 0 002 5.75v9.5A2.75 2.75 0 004.75 18h9.5A2.75 2.75 0 0017 15.25V10a.75.75 0 00-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5z"/>
                                        </svg>
                                    </button>
                                    <!-- Delete icon -->
                                    <button
                                        @click="deleteTranslation(translation, $event)"
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
                            <td colspan="7" class="px-4 py-10 text-center text-gray-400 text-sm">
                                No translations found.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div
                    v-if="translations.meta?.last_page > 1"
                    class="px-4 py-3 border-t border-gray-100 flex items-center justify-between text-sm text-gray-500"
                >
                    <p>Page {{ translations.meta.current_page }} of {{ translations.meta.last_page }} ({{ translations.meta.total }} entries)</p>
                    <div class="flex gap-2">
                        <Link v-if="translations.links?.prev" :href="translations.links.prev" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">← Prev</Link>
                        <Link v-if="translations.links?.next" :href="translations.links.next" class="px-3 py-1 border border-gray-300 rounded hover:bg-gray-50">Next →</Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal — with locale tabs -->
        <Modal :show="showEditModal" @close="showEditModal = false" max-width="2xl">
            <div class="p-6">

                <!-- Header -->
                <div class="mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Translation</h3>
                    <p class="text-xs font-mono text-gray-400 mt-0.5" v-if="editRow">
                        {{ editRow.group }}.{{ editRow.key }}
                    </p>
                </div>

                <!-- Loading -->
                <div v-if="editLoading" class="py-10 text-center text-gray-400 text-sm">
                    <svg class="animate-spin w-6 h-6 mx-auto mb-2 text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    Loading...
                </div>

                <template v-else>
                    <!-- Locale tabs -->
                    <div class="flex gap-1 border-b border-gray-200 mb-5">
                        <button
                            v-for="l in locales"
                            :key="l"
                            type="button"
                            @click="activeLocale = l"
                            class="px-4 py-2 text-sm transition-colors relative"
                            :class="localeTabClass(l)"
                        >
                            {{ l.toUpperCase() }}
                            <!-- Dot indicator if translation exists -->
                            <span
                                class="ml-1.5 inline-block w-1.5 h-1.5 rounded-full"
                                :class="localeExists(l) ? 'bg-green-400' : 'bg-gray-200'"
                            />
                        </button>
                    </div>

                    <!-- Form per locale -->
                    <template v-for="l in locales" :key="l">
                        <div v-if="activeLocale === l && editForms[l]" class="space-y-4">

                            <!-- Not yet translated notice -->
                            <div
                                v-if="!localeExists(l)"
                                class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700 flex items-center gap-2"
                            >
                                <span>⚠️</span>
                                This translation doesn't exist for <strong>{{ l.toUpperCase() }}</strong> yet. Fill in the fields and save to create it.
                            </div>

                            <div>
                                <InputLabel value="Short text" />
                                <p class="text-xs text-gray-400 mb-1">Badges, pills, table cells</p>
                                <TextInput v-model="editForms[l].text_short" class="mt-1 w-full" placeholder="e.g. Pending" />
                                <InputError :message="editForms[l].errors.text_short" />
                            </div>

                            <div>
                                <InputLabel value="Text" />
                                <p class="text-xs text-gray-400 mb-1">Messages, toasts, labels</p>
                                <textarea
                                    v-model="editForms[l].text"
                                    rows="2"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="e.g. Your KYC is pending review"
                                />
                                <InputError :message="editForms[l].errors.text" />
                            </div>

                            <div>
                                <InputLabel value="Long text" />
                                <p class="text-xs text-gray-400 mb-1">Descriptions, warnings</p>
                                <textarea
                                    v-model="editForms[l].text_long"
                                    rows="3"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <InputError :message="editForms[l].errors.text_long" />
                            </div>

                            <div>
                                <InputLabel value="HTML text" />
                                <p class="text-xs text-gray-400 mb-1">Rich HTML emails</p>
                                <textarea
                                    v-model="editForms[l].text_html"
                                    rows="3"
                                    class="mt-1 w-full rounded-lg border-gray-300 text-sm font-mono shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="<p>Your KYC has been <strong>approved</strong>.</p>"
                                />
                                <InputError :message="editForms[l].errors.text_html" />
                            </div>

                            <!-- Audit -->
                            <div class="text-xs text-gray-400 flex gap-4 pt-1" v-if="allLocaleData[l]">
                                <span v-if="allLocaleData[l].creator">Created by {{ allLocaleData[l].creator?.name }}</span>
                                <span v-if="allLocaleData[l].updater">Updated by {{ allLocaleData[l].updater?.name }}</span>
                            </div>

                            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                                <SecondaryButton type="button" @click="showEditModal = false">Close</SecondaryButton>
                                <PrimaryButton @click="saveLocale(l)" :disabled="editForms[l].processing">
                                    {{ editForms[l].processing ? 'Saving...' : localeExists(l) ? 'Save changes' : 'Create translation' }}
                                </PrimaryButton>
                            </div>
                        </div>
                    </template>
                </template>
            </div>
        </Modal>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="2xl">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-semibold text-gray-800">Add Translation</h3>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <InputLabel value="Locale" />
                        <select v-model="createForm.locale" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm">
                            <option v-for="l in locales" :key="l" :value="l">{{ l.toUpperCase() }}</option>
                        </select>
                        <InputError :message="createForm.errors.locale" />
                    </div>
                    <div>
                        <InputLabel value="Group" />
                        <TextInput v-model="createForm.group" class="mt-1 w-full" placeholder="e.g. kyc" list="groups-list" />
                        <datalist id="groups-list">
                            <option v-for="g in groups" :key="g" :value="g" />
                        </datalist>
                        <InputError :message="createForm.errors.group" />
                    </div>
                    <div>
                        <InputLabel value="Key" />
                        <TextInput v-model="createForm.key" class="mt-1 w-full" placeholder="e.g. submitted" />
                        <InputError :message="createForm.errors.key" />
                    </div>
                </div>

                <div>
                    <InputLabel value="Short text" />
                    <TextInput v-model="createForm.text_short" class="mt-1 w-full" placeholder="e.g. Pending" />
                    <InputError :message="createForm.errors.text_short" />
                </div>
                <div>
                    <InputLabel value="Text" />
                    <textarea v-model="createForm.text" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError :message="createForm.errors.text" />
                </div>
                <div>
                    <InputLabel value="Long text (optional)" />
                    <textarea v-model="createForm.text_long" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError :message="createForm.errors.text_long" />
                </div>
                <div>
                    <InputLabel value="HTML text (optional)" />
                    <textarea v-model="createForm.text_html" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm font-mono shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <InputError :message="createForm.errors.text_html" />
                </div>

                <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                    <SecondaryButton type="button" @click="showCreateModal = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="submitCreate" :disabled="createForm.processing">
                        {{ createForm.processing ? 'Saving...' : 'Save' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>