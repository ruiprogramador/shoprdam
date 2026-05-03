<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useKycStatus } from '@/Composables/useKycStatus'
import { useFormatDate } from '@/Composables/useFormatDate'
import SearchableSelect from '@/Components/SearchableSelect.vue'

const props = defineProps<{
    kycs: any
    stats: {
        total: number
        pending: number
        approved: number
        rejected: number
        expiring_soon: number
    }
    statuses: { id: number; name: string; slug: string }[]
    countries: { id: number; name: string }[]
    filters: Record<string, any>
}>()

const { statusColor } = useKycStatus()
const { formatDate, formatDateTime } = useFormatDate()

const search = ref(props.filters?.filter?.search ?? '')
const status = ref(props.filters?.filter?.status ?? '')
const countryId = ref(props.filters?.filter?.country_id ?? '')
const dateFrom = ref(props.filters?.filter?.date_from ?? '')
const dateTo = ref(props.filters?.filter?.date_to ?? '')
const perPage = ref(props.filters?.per_page ?? 10)
const sort = ref(props.filters?.sort ?? '-created_at')

const showFilters = ref(true)
const isExporting = ref(false)
let searchTimeout: ReturnType<typeof setTimeout>

// Format numbers with locale
const formatNumber = (num: number) => num?.toLocaleString() || '0'

// Check if any filters are active
const hasActiveFilters = computed(() => {
    return search.value || status.value || countryId.value || dateFrom.value || dateTo.value
})

// Stats configuration with enhanced visuals
const statsConfig = computed(() => [
    {
        label: 'Total Submissions',
        value: props.stats.total,
        icon: '📊',
        gradient: 'from-blue-500 to-blue-600',
        bgGradient: 'from-blue-50 to-blue-100',
        textColor: 'text-blue-700',
        iconBg: 'bg-blue-100',
        filter: null,
        description: 'All KYC submissions'
    },
    {
        label: 'Pending Review',
        value: props.stats.pending,
        icon: '⏳',
        gradient: 'from-yellow-500 to-yellow-600',
        bgGradient: 'from-yellow-50 to-yellow-100',
        textColor: 'text-yellow-700',
        iconBg: 'bg-yellow-100',
        filter: 'pending',
        description: 'Awaiting review'
    },
    {
        label: 'Approved',
        value: props.stats.approved,
        icon: '✅',
        gradient: 'from-green-500 to-green-600',
        bgGradient: 'from-green-50 to-green-100',
        textColor: 'text-green-700',
        iconBg: 'bg-green-100',
        filter: 'approved',
        description: 'Verified vendors'
    },
    {
        label: 'Rejected',
        value: props.stats.rejected,
        icon: '❌',
        gradient: 'from-red-500 to-red-600',
        bgGradient: 'from-red-50 to-red-100',
        textColor: 'text-red-700',
        iconBg: 'bg-red-100',
        filter: 'rejected',
        description: 'Failed verification'
    },
    {
        label: 'Expiring Soon',
        value: props.stats.expiring_soon,
        icon: '⚠️',
        gradient: 'from-orange-500 to-orange-600',
        bgGradient: 'from-orange-50 to-orange-100',
        textColor: 'text-orange-700',
        iconBg: 'bg-orange-100',
        filter: 'expiring_soon',
        description: 'Needs renewal'
    }
])

// Prepare options for searchable selects
const statusOptions = computed(() => [
    { value: '', label: 'All Statuses' },
    ...props.statuses.map(s => ({ value: s.slug, label: s.name }))
])

const countryOptions = computed(() => [
    { value: '', label: 'All Countries' },
    ...props.countries.map(c => ({ value: c.id, label: c.name }))
])

const applyFilters = () => {
    router.get(route('admin.kyc.index'), {
        filter: {
            search: search.value || undefined,
            status: status.value || undefined,
            country_id: countryId.value || undefined,
            date_from: dateFrom.value || undefined,
            date_to: dateTo.value || undefined,
        },
        sort: sort.value,
        per_page: perPage.value,
    }, { preserveState: true, replace: true })
}

const onSearchInput = () => {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 400)
}

const resetFilters = () => {
    search.value = ''
    status.value = ''
    countryId.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    perPage.value = 10
    sort.value = '-created_at'
    applyFilters()
}

const filterByStatus = (statusSlug: string | null) => {
    if (statusSlug) {
        status.value = statusSlug
        applyFilters()
    } else {
        resetFilters()
    }
}

const toggleSort = (field: string) => {
    sort.value = sort.value === `-${field}` ? field : `-${field}`
    applyFilters()
}

const getSortIcon = (field: string) => {
    if (sort.value === field) return '↑'
    if (sort.value === `-${field}`) return '↓'
    return '⇅'
}

const getSortState = (field: string) => {
    if (sort.value === field) return 'asc'
    if (sort.value === `-${field}`) return 'desc'
    return null
}

const getStatusIcon = (slug: string) => {
    const icons: Record<string, string> = {
        'pending': '⏳',
        'approved': '✅',
        'rejected': '❌',
        'under_review': '🔍',
        'expired': '⚠️'
    }
    return icons[slug] || '📄'
}

const handleExport = () => {
    isExporting.value = true
    setTimeout(() => {
        isExporting.value = false
    }, 2000)
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 tracking-tight">
                        KYC Management
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Manage and review vendor verification documents
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        @click="showFilters = !showFilters"
                        class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 hover:border-gray-400 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    >
                        <i :class="showFilters ? 'fa fa-eye-slash' : 'fa fa-filter'"></i>
                        <span>{{ showFilters ? 'Hide' : 'Show' }} Filters</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-6">

            <!-- Stats Cards - Interactive -->
            <div class="space-x-4 flex overflow-x-auto py-2">
                <button
                    v-for="stat in statsConfig"
                    :key="stat.label"
                    @click="filterByStatus(stat.filter)"
                    :class="[
                        'group relative overflow-hidden rounded-xl border-2 transition-all duration-300',
                        'hover:shadow-xl hover:scale-105 hover:-translate-y-1',
                        status === stat.filter 
                            ? 'ring-4 ring-blue-500/30 ring-offset-2 border-blue-500' 
                            : 'border-gray-200 hover:border-gray-300'
                    ]"
                >
                    <!-- Background Gradient -->
                    <div :class="['absolute inset-0 bg-gradient-to-br opacity-90', stat.bgGradient]"></div>
                    
                    <!-- Content -->
                    <div class="relative p-6">
                        <div class="flex items-start justify-between mb-3">
                            <div :class="['w-12 h-12 rounded-xl flex items-center justify-center text-2xl shadow-sm', stat.iconBg]">
                                {{ stat.icon }}
                            </div>
                            <i 
                                v-if="stat.filter" 
                                class="fa fa-filter text-xs opacity-0 group-hover:opacity-100 transition-opacity"
                                :class="stat.textColor"
                            ></i>
                        </div>
                        
                        <div class="space-y-1">
                            <p :class="['text-3xl font-bold tracking-tight', stat.textColor]">
                                {{ formatNumber(stat.value) }}
                            </p>
                            <p class="text-sm font-semibold text-gray-700">
                                {{ stat.label }}
                            </p>
                            <p class="text-xs text-gray-600">
                                {{ stat.description }}
                            </p>
                        </div>
                    </div>

                    <!-- Shine effect on hover -->
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent translate-x-[-200%] group-hover:translate-x-[200%] transition-transform duration-1000"></div>
                </button>
            </div>

            <!-- Filters Panel -->
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 -translate-y-4 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 -translate-y-4 scale-95"
            >
                <div v-show="showFilters" class="bg-white rounded-xl shadow-lg border-2 border-gray-200">
                    <div class="p-6 space-y-5">
                        <!-- Main Filters Row -->
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                            <!-- Search -->
                            <div class="lg:col-span-5">
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-search mr-1.5 text-blue-600"></i>
                                    Search
                                </label>
                                <div class="relative">
                                    <input
                                        v-model="search"
                                        @input="onSearchInput"
                                        type="text"
                                        placeholder="Search by name, email, company..."
                                        class="w-full pl-11 pr-10 py-3 rounded-lg border-2 border-gray-300 shadow-sm text-sm placeholder-gray-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200"
                                    />
                                    <i class="fa fa-search absolute left-4 top-4 text-gray-400"></i>
                                    <button
                                        v-if="search"
                                        @click="search = ''; applyFilters()"
                                        class="absolute right-3 top-3.5 text-gray-400 hover:text-gray-600 transition-colors"
                                    >
                                        <i class="fa fa-times-circle"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Status Select -->
                            <div class="lg:col-span-3">
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-flag mr-1.5 text-yellow-600"></i>
                                    Status
                                </label>
                                <SearchableSelect
                                    v-model="status"
                                    :options="statusOptions"
                                    placeholder="Select status..."
                                    @update:modelValue="applyFilters"
                                />
                            </div>

                            <!-- Country Select -->
                            <div class="lg:col-span-3">
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-globe mr-1.5 text-green-600"></i>
                                    Country
                                </label>
                                <SearchableSelect
                                    v-model="countryId"
                                    :options="countryOptions"
                                    placeholder="Select country..."
                                    @update:modelValue="applyFilters"
                                />
                            </div>

                            <!-- Per Page -->
                            <div class="lg:col-span-1">
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-list mr-1.5 text-purple-600"></i>
                                    Show
                                </label>
                                <select
                                    v-model="perPage"
                                    @change="applyFilters"
                                    class="w-full rounded-lg border-2 border-gray-300 shadow-sm text-sm py-3 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200"
                                >
                                    <option :value="10">10</option>
                                    <option :value="25">25</option>
                                    <option :value="50">50</option>
                                    <option :value="100">100</option>
                                </select>
                            </div>
                        </div>

                        <!-- Date Range -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-calendar-alt mr-1.5 text-indigo-600"></i>
                                    From Date
                                </label>
                                <input
                                    v-model="dateFrom"
                                    @change="applyFilters"
                                    type="date"
                                    class="w-full rounded-lg border-2 border-gray-300 shadow-sm text-sm py-3 px-4 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-2 uppercase tracking-wider">
                                    <i class="fa fa-calendar-alt mr-1.5 text-indigo-600"></i>
                                    To Date
                                </label>
                                <input
                                    v-model="dateTo"
                                    @change="applyFilters"
                                    type="date"
                                    class="w-full rounded-lg border-2 border-gray-300 shadow-sm text-sm py-3 px-4 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 transition-all duration-200"
                                />
                            </div>
                        </div>

                        <!-- Actions Row -->
                        <div class="flex items-center justify-between pt-4 border-t-2 border-gray-100">
                            <div class="flex items-center gap-3">
                                <Transition
                                    enter-active-class="transition-all duration-200"
                                    enter-from-class="opacity-0 scale-90"
                                    leave-active-class="transition-all duration-200"
                                    leave-to-class="opacity-0 scale-90"
                                >
                                    <span v-if="hasActiveFilters" class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-sm font-medium border-2 border-blue-200">
                                        <i class="fa fa-filter text-blue-500"></i>
                                        <span>Filters Active</span>
                                    </span>
                                </Transition>
                                
                                <button
                                    v-if="hasActiveFilters"
                                    @click="resetFilters"
                                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors duration-200"
                                >
                                    <i class="fa fa-times"></i>
                                    <span>Clear All</span>
                                </button>
                            </div>

                            <!-- Export Excel Button -->
                            <a
                                :href="route('admin.kyc.export', { ...filters?.filter, sort: filters?.sort })"
                                @click="handleExport"
                                class="inline-flex items-center gap-2.5 px-5 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg shadow-md hover:shadow-xl hover:from-green-700 hover:to-green-800 transition-all duration-300 transform hover:scale-105 font-medium text-sm"
                            >
                                <i :class="isExporting ? 'fa fa-spinner fa-spin' : 'fa fa-file-excel'" class="text-lg"></i>
                                <span>{{ isExporting ? 'Exporting...' : 'Export to Excel' }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- Table Container -->
            <div class="bg-white rounded-xl shadow-lg border-2 border-gray-200 overflow-hidden">
                <!-- Table Header -->
                <div class="px-6 py-4 border-b-2 border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <h3 class="text-lg font-bold text-gray-900">
                                KYC Submissions
                            </h3>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-800 border-2 border-blue-200">
                                <i class="fa fa-database"></i>
                                <span>{{ formatNumber(kycs.meta?.total || kycs.length || 0) }} Total</span>
                            </span>
                        </div>
                        <div v-if="kycs.meta" class="text-sm text-gray-600 font-medium">
                            <span class="text-gray-500">Showing</span>
                            <span class="font-bold text-gray-900">{{ formatNumber(kycs.meta.from || 0) }}</span>
                            <span class="text-gray-500">-</span>
                            <span class="font-bold text-gray-900">{{ formatNumber(kycs.meta.to || 0) }}</span>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y-2 divide-gray-200">
                        <thead class="bg-gradient-to-r from-gray-100 to-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-user text-blue-600"></i>
                                        <span>Vendor Info</span>
                                    </div>
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-200/70 transition-colors select-none group"
                                    @click="toggleSort('full_name')"
                                >
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-id-card text-purple-600"></i>
                                        <span>Full Name</span>
                                        <div class="flex flex-col text-gray-400 group-hover:text-gray-600">
                                            <i :class="['fa fa-caret-up text-xs -mb-1', getSortState('full_name') === 'asc' && 'text-blue-600']"></i>
                                            <i :class="['fa fa-caret-down text-xs', getSortState('full_name') === 'desc' && 'text-blue-600']"></i>
                                        </div>
                                    </div>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-globe text-green-600"></i>
                                        <span>Location</span>
                                    </div>
                                </th>
                                <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-flag text-yellow-600"></i>
                                        <span>Status</span>
                                    </div>
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-200/70 transition-colors select-none group"
                                    @click="toggleSort('created_at')"
                                >
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-calendar-plus text-indigo-600"></i>
                                        <span>Submitted</span>
                                        <div class="flex flex-col text-gray-400 group-hover:text-gray-600">
                                            <i :class="['fa fa-caret-up text-xs -mb-1', getSortState('created_at') === 'asc' && 'text-blue-600']"></i>
                                            <i :class="['fa fa-caret-down text-xs', getSortState('created_at') === 'desc' && 'text-blue-600']"></i>
                                        </div>
                                    </div>
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-200/70 transition-colors select-none group"
                                    @click="toggleSort('expires_at')"
                                >
                                    <div class="flex items-center gap-2">
                                        <i class="fa fa-calendar-times text-red-600"></i>
                                        <span>Expiry</span>
                                        <div class="flex flex-col text-gray-400 group-hover:text-gray-600">
                                            <i :class="['fa fa-caret-up text-xs -mb-1', getSortState('expires_at') === 'asc' && 'text-blue-600']"></i>
                                            <i :class="['fa fa-caret-down text-xs', getSortState('expires_at') === 'desc' && 'text-blue-600']"></i>
                                        </div>
                                    </div>
                                </th>
                                <th scope="col" class="relative px-6 py-4">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <tr
                                v-for="kyc in kycs"
                                :key="kyc.id"
                                class="hover:bg-blue-50/50 transition-all duration-200 group"
                            >
                                <!-- Vendor Info -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-shrink-0 h-11 w-11 rounded-xl bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-white font-bold text-base shadow-md group-hover:shadow-lg transition-shadow">
                                            {{ kyc.user?.name?.charAt(0)?.toUpperCase() || '?' }}
                                        </div>
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">
                                                {{ kyc.user?.name || 'N/A' }}
                                            </div>
                                            <div class="text-xs text-gray-500 flex items-center gap-1">
                                                <i class="fa fa-envelope text-gray-400"></i>
                                                {{ kyc.user?.email || 'No email' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Full Name -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ kyc.full_name || '—' }}
                                    </div>
                                </td>

                                <!-- Country -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2 text-sm text-gray-700">
                                        <i class="fa fa-map-marker-alt text-gray-400"></i>
                                        <span class="font-medium">{{ kyc.country?.name || '—' }}</span>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm border-2"
                                        :class="statusColor(kyc.status?.slug)"
                                    >
                                        <span class="text-base">{{ getStatusIcon(kyc.status?.slug) }}</span>
                                        <span>{{ kyc.status?.name || 'Unknown' }}</span>
                                    </span>
                                </td>

                                <!-- Submitted -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2 text-sm text-gray-700">
                                        <i class="fa fa-clock text-gray-400"></i>
                                        <span class="font-medium">{{ formatDateTime(kyc.created_at) }}</span>
                                    </div>
                                </td>

                                <!-- Expiry -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2 text-sm text-gray-700">
                                        <i class="fa fa-hourglass-half text-gray-400"></i>
                                        <span class="font-medium">{{ formatDate(kyc.expires_at) }}</span>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <Link
                                        :href="route('admin.kyc.show', kyc.id)"
                                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-blue-600 hover:text-white bg-blue-50 hover:bg-blue-600 border-2 border-blue-200 hover:border-blue-600 rounded-lg transition-all duration-200 shadow-sm hover:shadow-md"
                                    >
                                        <span>View Details</span>
                                        <i class="fa fa-arrow-right text-xs"></i>
                                    </Link>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr v-if="!kycs?.length">
                                <td colspan="7" class="px-6 py-20 text-center">
                                    <div class="flex flex-col items-center gap-4">
                                        <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 flex items-center justify-center shadow-inner">
                                            <i class="fa fa-inbox text-4xl text-gray-400"></i>
                                        </div>
                                        <div>
                                            <p class="text-base font-semibold text-gray-900">No KYC submissions found</p>
                                            <p class="text-sm text-gray-500 mt-1">Try adjusting your filters or search criteria</p>
                                        </div>
                                        <button
                                            v-if="hasActiveFilters"
                                            @click="resetFilters"
                                            class="mt-2 inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-blue-600 hover:text-white bg-blue-50 hover:bg-blue-600 border-2 border-blue-200 hover:border-blue-600 rounded-lg transition-all duration-200"
                                        >
                                            <i class="fa fa-redo"></i>
                                            <span>Clear All Filters</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="kycs.meta?.last_page > 1" class="px-6 py-4 border-t-2 border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <Link
                                v-if="kycs.links?.prev"
                                :href="kycs.links.prev"
                                class="relative inline-flex items-center px-4 py-2 border-2 border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors"
                            >
                                Previous
                            </Link>
                            <Link
                                v-if="kycs.links?.next"
                                :href="kycs.links.next"
                                class="ml-3 relative inline-flex items-center px-4 py-2 border-2 border-gray-300 text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors"
                            >
                                Next
                            </Link>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700 font-medium">
                                    Showing
                                    <span class="font-bold text-gray-900">{{ formatNumber(kycs.meta.from || 0) }}</span>
                                    to
                                    <span class="font-bold text-gray-900">{{ formatNumber(kycs.meta.to || 0) }}</span>
                                    of
                                    <span class="font-bold text-gray-900">{{ formatNumber(kycs.meta.total || 0) }}</span>
                                    results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-lg shadow-sm -space-x-px" aria-label="Pagination">
                                    <Link
                                        v-if="kycs.links?.prev"
                                        :href="kycs.links.prev"
                                        class="relative inline-flex items-center px-3 py-2 rounded-l-lg border-2 border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors"
                                    >
                                        <i class="fa fa-chevron-left"></i>
                                    </Link>
                                    <span class="relative inline-flex items-center px-4 py-2 border-2 border-gray-300 bg-white text-sm font-bold text-gray-700">
                                        Page {{ kycs.meta.current_page }} of {{ kycs.meta.last_page }}
                                    </span>
                                    <Link
                                        v-if="kycs.links?.next"
                                        :href="kycs.links.next"
                                        class="relative inline-flex items-center px-3 py-2 rounded-r-lg border-2 border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors"
                                    >
                                        <i class="fa fa-chevron-right"></i>
                                    </Link>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>