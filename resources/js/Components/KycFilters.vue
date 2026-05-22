<script setup lang="ts">
/**
 * KycFilters — filtros exclusivos da view Admin/Kyc/Index
 *
 * Responsabilidade:
 *   - Manter estado local dos filtros (inicializado com os valores actuais da URL via props.filters)
 *   - Ao "Apply" faz router.get com os filtros como query params (Spatie QueryBuilder format)
 *   - Ao "Reset" limpa tudo e navega sem filtros
 *   - Emite `badge-count` para o componente pai mostrar no trigger
 *
 * Props vindas do controller (Inertia::render):
 *   - statuses  : KycStatus[] { id, name, slug }
 *   - countries : Country[]   { id, name }
 *   - filters   : os query params actuais { filter: { search, status, ... }, sort, per_page }
 */

import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

// ─── Types ───────────────────────────────────────────────────────────────────

interface SelectOption { value: string | number; label: string }

const props = defineProps<{
    statuses:  { id: number; name: string; slug: string }[]
    countries: { id: number; name: string }[]
    filters:   Record<string, any>
    routeName: string  // ex: 'admin.kyc.index' — permite reutilizar em sub-secções
}>()

const emit = defineEmits<{
    (e: 'badge-count', n: number): void
}>()

// ─── State local (inicializado com filtros actuais da URL) ────────────────────

const search    = ref<string>(props.filters?.filter?.search     ?? '')
const status    = ref<string>(props.filters?.filter?.status     ?? '')
const countryId = ref<string | number>(props.filters?.filter?.country_id ?? '')
const dateFrom  = ref<string>(props.filters?.filter?.date_from  ?? '')
const dateTo    = ref<string>(props.filters?.filter?.date_to    ?? '')
const perPage   = ref<number>(Number(props.filters?.per_page)   || 10)
const sort      = ref<string>(props.filters?.sort               ?? '-created_at')

// ─── Opções formatadas ────────────────────────────────────────────────────────

const statusOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All Statuses' },
    ...props.statuses.map(s => ({ value: s.slug, label: s.name })),
])

const countryOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'All Countries' },
    ...props.countries.map(c => ({ value: c.id, label: c.name })),
])

// ─── Badge (filtros activos) ──────────────────────────────────────────────────

const activeCount = computed(() =>
    [search.value, status.value, countryId.value, dateFrom.value, dateTo.value]
        .filter(Boolean).length
)

watch(activeCount, (n) => emit('badge-count', n), { immediate: true })

// ─── Acções ───────────────────────────────────────────────────────────────────

/**
 * Navega para a mesma rota com os filtros como query params.
 * O Spatie QueryBuilder espera: ?filter[search]=...&filter[status]=...&per_page=...
 */
const apply = () => {
    router.get(
        route(props.routeName),
        {
            filter: {
                ...(search.value    ? { search:     search.value }    : {}),
                ...(status.value    ? { status:     status.value }    : {}),
                ...(countryId.value ? { country_id: countryId.value } : {}),
                ...(dateFrom.value  ? { date_from:  dateFrom.value }  : {}),
                ...(dateTo.value    ? { date_to:    dateTo.value }    : {}),
            },
            sort:     sort.value,
            per_page: perPage.value,
        },
        { preserveState: true, replace: true },
    )
}

const reset = () => {
    search.value    = ''
    status.value    = ''
    countryId.value = ''
    dateFrom.value  = ''
    dateTo.value    = ''
    perPage.value   = 10
    sort.value      = '-created_at'

    router.get(
        route(props.routeName),
        {},
        { preserveState: false, replace: true },
    )
}

// Debounce para o campo search
let searchTimer: ReturnType<typeof setTimeout>
const onSearchInput = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(apply, 400)
}
</script>

<template>
    <!-- Search -->
    <div class="rsb-field">
        <label class="rsb-field-label">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z"/>
            </svg>
            Search
        </label>
        <input
            v-model="search"
            type="text"
            class="rsb-input"
            placeholder="Name, email…"
            @input="onSearchInput"
        />
    </div>

    <!-- Status -->
    <div class="rsb-field">
        <label class="rsb-field-label">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
            </svg>
            Status
        </label>
        <select v-model="status" class="rsb-select">
            <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>
    </div>

    <!-- Country -->
    <div class="rsb-field">
        <label class="rsb-field-label">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>
            </svg>
            Country
        </label>
        <select v-model="countryId" class="rsb-select">
            <option v-for="opt in countryOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
            </option>
        </select>
    </div>

    <!-- Date range -->
    <div class="rsb-field-group">
        <div class="rsb-field">
            <label class="rsb-field-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5"/>
                </svg>
                From
            </label>
            <input v-model="dateFrom" type="date" class="rsb-input" />
        </div>
        <div class="rsb-field">
            <label class="rsb-field-label">To</label>
            <input v-model="dateTo" type="date" class="rsb-input" />
        </div>
    </div>

    <!-- Rows per page -->
    <div class="rsb-field">
        <label class="rsb-field-label">Rows per page</label>
        <div class="rsb-per-page-group">
            <button
                v-for="n in [10, 25, 50, 100]"
                :key="n"
                class="rsb-per-page-btn"
                :class="{ 'rsb-per-page-btn--active': perPage === n }"
                @click="perPage = n"
            >
                {{ n }}
            </button>
        </div>
    </div>

    <!-- Actions -->
    <div class="rsb-actions">
        <button
            class="rsb-btn-reset"
            :disabled="!activeCount && perPage === 10"
            @click="reset"
        >
            Reset
        </button>
        <button class="rsb-btn-apply" @click="apply">
            Apply filters
        </button>
    </div>
</template>

<style scoped>
/* ── Field ── */
.rsb-field { display: flex; flex-direction: column; gap: 6px; }

.rsb-field-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6b7280;
}
.rsb-field-label svg { width: 13px; height: 13px; flex-shrink: 0; }

.rsb-field-group { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

/* ── Inputs ── */
.rsb-input,
.rsb-select {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    color: #111827;
    background: #fafafa;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
    appearance: none;
}
.rsb-input:focus,
.rsb-select:focus {
    border-color: var(--accent, #3B82F6);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #3B82F6) 15%, transparent);
    background: white;
}

/* ── Per-page buttons ── */
.rsb-per-page-group {
    display: flex; gap: 6px; flex-wrap: wrap;
}
.rsb-per-page-btn {
    flex: 1;
    padding: 6px 4px;
    border: 1.5px solid #e5e7eb;
    border-radius: 7px;
    font-size: 12px; font-weight: 600;
    color: #6b7280;
    background: white;
    cursor: pointer;
    transition: all .15s;
    min-width: 40px;
}
.rsb-per-page-btn:hover { border-color: #9ca3af; color: #374151; }
.rsb-per-page-btn--active {
    border-color: var(--accent, #3B82F6);
    background: color-mix(in srgb, var(--accent, #3B82F6) 10%, white);
    color: var(--accent, #3B82F6);
}

/* ── Actions ── */
.rsb-actions {
    display: grid;
    grid-template-columns: 1fr 1.6fr;
    gap: 8px;
    margin-top: 4px;
}
.rsb-btn-reset {
    padding: 9px;
    border: 1.5px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px; font-weight: 600;
    color: #6b7280;
    background: white;
    cursor: pointer;
    transition: all .15s;
}
.rsb-btn-reset:hover:not(:disabled) { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
.rsb-btn-reset:disabled { opacity: .4; cursor: default; }

.rsb-btn-apply {
    padding: 9px;
    border: none;
    border-radius: 8px;
    font-size: 13px; font-weight: 700;
    color: white;
    background: var(--accent, #3B82F6);
    cursor: pointer;
    transition: filter .15s, transform .1s;
}
.rsb-btn-apply:hover { filter: brightness(1.1); transform: translateY(-1px); }
.rsb-btn-apply:active { transform: translateY(0); }
</style>