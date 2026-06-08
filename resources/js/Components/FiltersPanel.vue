<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import StatsCards from '@/Components/StatsCards.vue'
import ExportActions from '@/Components/ExportActions.vue'
import type { StatCard, FilterField, ExportAction } from '@/types/filters'

const props = defineProps<{
    fields:          FilterField[]
    filters:         Record<string, any>
    routeName:       string
    statsCards?:     StatCard[]
    exportActions?:  ExportAction[]
}>()

const emit = defineEmits<{
    (e: 'badge-count', n: number): void
}>()

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

const values  = ref<Record<string, string>>(buildInitialState())
const perPage = ref<number>(Number(props.filters?.per_page) || 10)
const sort    = ref<string>(props.filters?.sort ?? '')

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
        { preserveState: false, replace: true },
    )
}

const reset = () => {
    for (const key of Object.keys(values.value)) values.value[key] = ''
    perPage.value = 10
    sort.value    = ''
    router.get(route(props.routeName), {}, { preserveState: false, replace: true })
}

let searchTimer: ReturnType<typeof setTimeout>
/* const onSearchInput = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(apply, 400)
} */

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
            <!-- <input
                v-model="values[field.key]"
                type="text"
                class="fp-input"
                :placeholder="field.placeholder ?? ''"
                @input="onSearchInput"
            /> -->
            <input
                v-model="values[field.key]"
                type="text"
                class="fp-input"
                :placeholder="field.placeholder ?? ''"
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

    <!-- Apply / Reset -->
    <div class="fp-actions">
        <button class="fp-btn-reset" :disabled="!activeCount && perPage === 10" @click="reset">
            Reset
        </button>
        <button class="fp-btn-apply" @click="apply">
            Apply filters
        </button>
    </div>

    <!-- Export Actions (opcional — só aparece se o backend mandar export_actions) -->
    <template v-if="exportActions?.length">
        <div class="fp-divider" />
        <ExportActions :actions="exportActions" :filters="filters" />
    </template>
</template>