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