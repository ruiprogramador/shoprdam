<script setup lang="ts">
import { ref } from 'vue'
import type { ExportAction } from '@/types/filters'

const props = defineProps<{
    actions:  ExportAction[]
    filters?: Record<string, any>
}>()

const loading = ref<string | null>(null)

/**
 * Achata os filtros activos em query params no formato Spatie:
 * { filter: { status: 'pending', search: 'rui' } }
 * → filter[status]=pending&filter[search]=rui
 */
const buildUrl = (action: ExportAction): string => {
    const base = route(action.route!)
    const params = new URLSearchParams()

    if (action.format !== 'print') {
        params.append('format', action.format)
    }

    const addParams = (obj: Record<string, any>, prefix = '') => {
        for (const [k, v] of Object.entries(obj)) {
            if (v === null || v === undefined || v === '') continue
            const key = prefix ? `${prefix}[${k}]` : k
            if (typeof v === 'object' && !Array.isArray(v)) {
                addParams(v, key)
            } else {
                params.append(key, String(v))
            }
        }
    }

    if (props.filters) addParams(props.filters)

    const qs = params.toString()
    return qs ? `${base}?${qs}` : base
}

const handleExport = async (action: ExportAction) => {
    if (loading.value) return

    const url = buildUrl(action)  // ← sempre com filtros, incluindo print

    if (action.format === 'print') {
        window.open(url, '_blank')
        return
    }

    loading.value = action.format
    try {
        window.location.href = url
        await new Promise(r => setTimeout(r, 1500))
    } finally {
        loading.value = null
    }
}

const isLoading = (action: ExportAction) => loading.value === action.format

const colorMap: Record<string, string> = {
    xlsx:  'ea-btn--green',
    csv:   'ea-btn--blue',
    print: 'ea-btn--gray',
}
</script>

<template>
    <div class="ea-wrap">
        <p class="ea-label">Export</p>
        <div class="ea-group">
            <button
                v-for="action in actions"
                :key="action.format"
                class="ea-btn"
                :class="[colorMap[action.format], { 'ea-btn--loading': isLoading(action) }]"
                :disabled="!!loading"
                :title="`Export as ${action.label}`"
                @click="handleExport(action)"
            >
                <span v-if="isLoading(action)" class="ea-spinner" />
                <span v-else class="ea-icon">{{ action.icon }}</span>
                <span class="ea-btn-label">{{ action.label }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.ea-wrap  { display: flex; flex-direction: column; gap: 6px; }

.ea-label {
    font-size: 10px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .08em; color: #9ca3af; line-height: 1;
}

.ea-group { display: flex; gap: 6px; flex-wrap: wrap; }

.ea-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 11px; border-radius: 7px; border: 1.5px solid transparent;
    font-size: 11px; font-weight: 600; cursor: pointer;
    transition: all .15s; font-family: inherit; white-space: nowrap;
}
.ea-btn:disabled { opacity: .5; cursor: not-allowed; }

.ea-btn--green { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
.ea-btn--green:hover:not(:disabled) { background: #dcfce7; border-color: #86efac; }

.ea-btn--blue  { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
.ea-btn--blue:hover:not(:disabled)  { background: #dbeafe; border-color: #93c5fd; }

.ea-btn--gray  { background: #f9fafb; border-color: #e5e7eb; color: #374151; }
.ea-btn--gray:hover:not(:disabled)  { background: #f3f4f6; border-color: #d1d5db; }

.ea-icon      { font-size: 13px; line-height: 1; }
.ea-btn-label { font-size: 11px; }

.ea-spinner {
    width: 12px; height: 12px; flex-shrink: 0;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: ea-spin .6s linear infinite;
}
@keyframes ea-spin { to { transform: rotate(360deg); } }
</style>