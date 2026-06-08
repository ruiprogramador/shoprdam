<script setup lang="ts">
import type { StatCard } from '@/types/filters'

const props = defineProps<{
    cards:        StatCard[]
    activeFilter?: string | null
}>()

const emit = defineEmits<{
    (e: 'filter', slug: string | null): void
}>()

const colorMap: Record<string, { text: string; bg: string; ring: string }> = {
    blue:   { text: 'text-blue-700',   bg: 'bg-blue-50 hover:bg-blue-100 border-blue-200',      ring: 'ring-2 ring-blue-400 ring-offset-1 border-blue-400'    },
    yellow: { text: 'text-yellow-700', bg: 'bg-yellow-50 hover:bg-yellow-100 border-yellow-200', ring: 'ring-2 ring-yellow-400 ring-offset-1 border-yellow-400' },
    green:  { text: 'text-green-700',  bg: 'bg-green-50 hover:bg-green-100 border-green-200',    ring: 'ring-2 ring-green-400 ring-offset-1 border-green-400'   },
    red:    { text: 'text-red-700',    bg: 'bg-red-50 hover:bg-red-100 border-red-200',          ring: 'ring-2 ring-red-400 ring-offset-1 border-red-400'       },
    orange: { text: 'text-orange-700', bg: 'bg-orange-50 hover:bg-orange-100 border-orange-200', ring: 'ring-2 ring-orange-400 ring-offset-1 border-orange-400' },
    purple: { text: 'text-purple-700', bg: 'bg-purple-50 hover:bg-purple-100 border-purple-200', ring: 'ring-2 ring-purple-400 ring-offset-1 border-purple-400' },
    gray:   { text: 'text-gray-700',   bg: 'bg-gray-50 hover:bg-gray-100 border-gray-200',       ring: 'ring-2 ring-gray-400 ring-offset-1 border-gray-400'     },
}

const colors = (card: StatCard) => colorMap[card.color] ?? colorMap.gray
const isActive = (card: StatCard) => props.activeFilter === card.filter_slug
const handleClick = (card: StatCard) => emit('filter', isActive(card) ? null : card.filter_slug)
</script>

<template>
    <div class="sc-strip">
        <button
            v-for="card in cards"
            :key="card.label"
            class="sc-pill"
            :class="[colors(card).bg, isActive(card) ? colors(card).ring : 'border']"
            :title="`${card.label}: ${card.value} — ${card.description}`"
            @click="handleClick(card)"
        >
            <span class="sc-icon">{{ card.icon }}</span>
            <span class="sc-value" :class="colors(card).text">{{ card.value.toLocaleString() }}</span>
            <span class="sc-label" :class="colors(card).text">{{ card.label }}</span>
        </button>
    </div>
</template>