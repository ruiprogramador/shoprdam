/**
 * Tipos partilhados entre StatsCards.vue, FiltersPanel.vue e os controllers via Inertia.
 * Importar com: import type { StatCard, FilterField, FilterOption } from '@/types/filters'
 */

export interface StatCard {
    label:       string
    description: string
    value:       number
    icon:        string
    filter_slug: string | null
    color:       'blue' | 'yellow' | 'green' | 'red' | 'orange' | 'purple' | 'gray'
}

export interface FilterOption {
    value: string | number
    label: string
}

export interface FilterField {
    type:         'search' | 'select' | 'date' | 'date_range'
    key:          string
    key_to?:      string        // só em date_range
    label:        string
    label_to?:    string        // label do campo "to" em date_range
    placeholder?: string        // só em search
    options?:     FilterOption[] // só em select
    icon?:        string        // SVG path d="..." string
}

export interface ExportAction {
    label:   string              // ex: "Excel", "CSV", "Print"
    icon:    string              // emoji ou SVG path d="..."
    format:  'xlsx' | 'csv' | 'print'
    route?:  string              // route name Laravel — omitir em format:'print'
}