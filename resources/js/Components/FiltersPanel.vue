<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import StatsCards from '@/Components/StatsCards.vue'
import type { StatCard, FilterField } from '@/types/filters'

const props = defineProps<{
    fields:      FilterField[]
    filters:     Record<string, any>
    routeName:   string
    statsCards?: StatCard[]
}>()

const emit = defineEmits<{
    (e: 'badge-count', n: number): void
}>()

// Build state dynamically from field definitions
const buildInitialState = (): Record<string, string> => {
    const state: Record<string, string> = {}
    for (const field of props.fields) {
        state[field.key] = props.filters?.filter?.[field.key] ?? ''
        if (field.type === 'date_range' && field.key_to) {
            state[field.key_to] = props.filters?.filter?.[field.key_to] ?? ''
        }
    }
    return state
}

const values   = ref<Record<string, string>>(buildInitialState())
const perPage  = ref<number>(Number(props.filters?.per_page) || 10)
const sort     = ref<string>(props.filters?.sort ?? '')

const activeCount = computed(() => Object.values(values.value).filter(Boolean).length)
watch(activeCount, (n) => emit('badge-count', n), { immediate: true })

const activeStatFilter = computed(() => values.value['status'] || null)

const apply = () => {
    const filter: Record<string, string> = {}
    for (const [k, v] of Object.entries(values.value)) {
        if (v) filter[k] = v
    }
    router.get(
        route(props.routeName),
        {
            filter,
            ...(sort.value    ? { sort: sort.value }        : {}),
            ...(perPage.value ? { per_page: perPage.value } : {}),
        },
        { preserveState: true, replace: true },
    )
}

const reset = () => {
    for (const key of Object.keys(values.value)) values.value[key] = ''
    perPage.value = 10
    sort.value    = ''
    router.get(route(props.routeName), {}, { preserveState: false, replace: true })
}

let searchTimer: ReturnType<typeof setTimeout>
const onSearchInput = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(apply, 400)
}

const onStatFilter = (slug: string | null) => {
    values.value['status'] = slug ?? ''
    apply()
}
</script>

<template>
    <!-- Stats Cards -->
    <template v-if="statsCards?.length">
        <div class="fp-section">
            <p class="fp-section-label">Overview</p>
            <StatsCards
                :cards="statsCards"
                :active-filter="activeStatFilter"
                @filter="onStatFilter"
            />
        </div>
        <div class="fp-divider" />
    </template>

    <!-- Fields -->
    <template v-for="field in fields" :key="field.key">

        <div v-if="field.type === 'search'" class="fp-field">
            <label class="fp-label">
                <svg v-if="field.icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="field.icon"/>
                </svg>
                {{ field.label }}
            </label>
            <input
                v-model="values[field.key]"
                type="text"
                class="fp-input"
                :placeholder="field.placeholder ?? ''"
                @input="onSearchInput"
            />
        </div>

        <div v-else-if="field.type === 'select'" class="fp-field">
            <label class="fp-label">
                <svg v-if="field.icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="field.icon"/>
                </svg>
                {{ field.label }}
            </label>
            <select v-model="values[field.key]" class="fp-select">
                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>
        </div>

        <div v-else-if="field.type === 'date'" class="fp-field">
            <label class="fp-label">
                <svg v-if="field.icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="field.icon"/>
                </svg>
                {{ field.label }}
            </label>
            <input v-model="values[field.key]" type="date" class="fp-input" />
        </div>

        <div v-else-if="field.type === 'date_range'" class="fp-field-group">
            <div class="fp-field">
                <label class="fp-label">
                    <svg v-if="field.icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="field.icon"/>
                    </svg>
                    {{ field.label }}
                </label>
                <input v-model="values[field.key]" type="date" class="fp-input" />
            </div>
            <div v-if="field.key_to" class="fp-field">
                <label class="fp-label">{{ field.label_to ?? 'To' }}</label>
                <input v-model="values[field.key_to]" type="date" class="fp-input" />
            </div>
        </div>

    </template>

    <!-- Rows per page -->
    <div class="fp-field">
        <label class="fp-label">Rows per page</label>
        <div class="fp-per-page-group">
            <button
                v-for="n in [10, 25, 50, 100]"
                :key="n"
                class="fp-per-page-btn"
                :class="{ 'fp-per-page-btn--active': perPage === n }"
                @click="perPage = n"
            >{{ n }}</button>
        </div>
    </div>

    <!-- Actions -->
    <div class="fp-actions">
        <button class="fp-btn-reset" :disabled="!activeCount && perPage === 10" @click="reset">
            Reset
        </button>
        <button class="fp-btn-apply" @click="apply">
            Apply filters
        </button>
    </div>
</template>

<style scoped>
.fp-section        { display: flex; flex-direction: column; gap: 6px; }
.fp-section-label  { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: #9ca3af; line-height: 1; }
.fp-divider        { height: 1px; background: #f3f4f6; margin: 0 -2px; }

.fp-field       { display: flex; flex-direction: column; gap: 5px; }
.fp-field-group { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

.fp-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .06em; color: #6b7280;
}
.fp-label svg { width: 12px; height: 12px; flex-shrink: 0; }

.fp-input,
.fp-select {
    width: 100%; padding: 5px 9px;
    border: 1.5px solid #e5e7eb; border-radius: 7px;
    font-size: 12px; color: #111827; background: #fafafa;
    transition: border-color .15s, box-shadow .15s;
    outline: none; appearance: none; font-family: inherit;
}
.fp-input:focus,
.fp-select:focus {
    border-color: var(--accent, #3B82F6);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent, #3B82F6) 15%, transparent);
    background: white;
}

.fp-per-page-group { display: flex; gap: 6px; flex-wrap: wrap; }
.fp-per-page-btn {
    flex: 1; padding: 5px 4px;
    border: 1.5px solid #e5e7eb; border-radius: 7px;
    font-size: 11px; font-weight: 600; color: #6b7280;
    background: white; cursor: pointer; transition: all .15s;
    min-width: 36px; font-family: inherit;
}
.fp-per-page-btn:hover { border-color: #9ca3af; color: #374151; }
.fp-per-page-btn--active {
    border-color: var(--accent, #3B82F6);
    background: color-mix(in srgb, var(--accent, #3B82F6) 10%, white);
    color: var(--accent, #3B82F6);
}

.fp-actions { display: grid; grid-template-columns: 1fr 1.6fr; gap: 8px; margin-top: 4px; }

.fp-btn-reset {
    padding: 7px; border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 12px; font-weight: 600; color: #6b7280;
    background: white; cursor: pointer; transition: all .15s; font-family: inherit;
}
.fp-btn-reset:hover:not(:disabled) { border-color: #ef4444; color: #ef4444; background: #fef2f2; }
.fp-btn-reset:disabled { opacity: .4; cursor: default; }

.fp-btn-apply {
    padding: 7px; border: none; border-radius: 8px;
    font-size: 12px; font-weight: 700; color: white;
    background: var(--accent, #3B82F6); cursor: pointer;
    transition: filter .15s, transform .1s; font-family: inherit;
}
.fp-btn-apply:hover { filter: brightness(1.1); transform: translateY(-1px); }
.fp-btn-apply:active { transform: translateY(0); }
</style>