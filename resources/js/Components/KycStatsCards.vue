<script setup lang="ts">
/**
 * KycStatsCards — pills compactos com estatísticas KYC
 *
 * Cada pill mostra: ícone + número + label (em tooltip nativo via title)
 * Clicável → filtra por status (emite 'filter-status')
 * O pai (KycFilters) liga o emit ao router.get
 */

interface Stat {
    label: string
    description: string
    value: number
    icon: string
    filterSlug: string | null
    colorClass: string       // classe Tailwind para o texto/borda
    bgClass: string          // classe Tailwind para o fundo
    activeRingClass: string  // ring quando activo
}

const props = defineProps<{
    stats: {
        total: number
        pending: number
        approved: number
        rejected: number
        expiring_soon: number
    }
    activeFilter?: string | null
}>()

const emit = defineEmits<{
    (e: 'filter-status', slug: string | null): void
}>()

const statList: Stat[] = [
    {
        label: 'Total',
        description: 'All KYC submissions',
        value: props.stats.total,
        icon: '📊',
        filterSlug: null,
        colorClass: 'text-blue-700',
        bgClass: 'bg-blue-50 hover:bg-blue-100 border-blue-200',
        activeRingClass: 'ring-2 ring-blue-400 ring-offset-1 border-blue-400',
    },
    {
        label: 'Pending',
        description: 'Awaiting review',
        value: props.stats.pending,
        icon: '⏳',
        filterSlug: 'pending',
        colorClass: 'text-yellow-700',
        bgClass: 'bg-yellow-50 hover:bg-yellow-100 border-yellow-200',
        activeRingClass: 'ring-2 ring-yellow-400 ring-offset-1 border-yellow-400',
    },
    {
        label: 'Approved',
        description: 'Verified vendors',
        value: props.stats.approved,
        icon: '✅',
        filterSlug: 'approved',
        colorClass: 'text-green-700',
        bgClass: 'bg-green-50 hover:bg-green-100 border-green-200',
        activeRingClass: 'ring-2 ring-green-400 ring-offset-1 border-green-400',
    },
    {
        label: 'Rejected',
        description: 'Failed verification',
        value: props.stats.rejected,
        icon: '❌',
        filterSlug: 'rejected',
        colorClass: 'text-red-700',
        bgClass: 'bg-red-50 hover:bg-red-100 border-red-200',
        activeRingClass: 'ring-2 ring-red-400 ring-offset-1 border-red-400',
    },
    {
        label: 'Expiring',
        description: 'Needs renewal soon',
        value: props.stats.expiring_soon,
        icon: '⚠️',
        filterSlug: 'expiring_soon',
        colorClass: 'text-orange-700',
        bgClass: 'bg-orange-50 hover:bg-orange-100 border-orange-200',
        activeRingClass: 'ring-2 ring-orange-400 ring-offset-1 border-orange-400',
    },
]

const isActive = (slug: string | null) => props.activeFilter === slug
</script>

<template>
    <!-- Strip de pills compactos -->
    <div class="kyc-stats-strip">
        <button
            v-for="stat in statList"
            :key="stat.label"
            class="kyc-stat-pill"
            :class="[
                stat.bgClass,
                isActive(stat.filterSlug) ? stat.activeRingClass : 'border',
            ]"
            :title="`${stat.label}: ${stat.value} — ${stat.description}. Click to filter.`"
            @click="emit('filter-status', isActive(stat.filterSlug) ? null : stat.filterSlug)"
        >
            <span class="kyc-stat-icon">{{ stat.icon }}</span>
            <span class="kyc-stat-value" :class="stat.colorClass">{{ stat.value }}</span>
            <span class="kyc-stat-label" :class="stat.colorClass">{{ stat.label }}</span>
        </button>
    </div>
</template>

<style scoped>
.kyc-stats-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding-bottom: 2px;
}

.kyc-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px 4px 7px;
    border-radius: 999px;
    cursor: pointer;
    transition: all .15s ease;
    white-space: nowrap;
    font-family: inherit;
    line-height: 1;
}

.kyc-stat-pill:focus-visible {
    outline: 2px solid currentColor;
    outline-offset: 2px;
}

.kyc-stat-icon {
    font-size: 13px;
    line-height: 1;
}

.kyc-stat-value {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: -0.02em;
}

.kyc-stat-label {
    font-size: 11px;
    font-weight: 600;
    opacity: 0.85;
}
</style>