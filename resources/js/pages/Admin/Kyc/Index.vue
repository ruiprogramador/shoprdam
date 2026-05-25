<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { useKycStatus } from '@/Composables/useKycStatus'
import { useFormatDate } from '@/Composables/useFormatDate'
import RightFilterSidebar from '@/Components/RightFilterSidebar.vue'
import FiltersPanel from '@/Components/FiltersPanel.vue'
import type { StatCard, FilterField, ExportAction } from '@/types/filters'

const props = defineProps<{
    kycs:           any
    stats_cards:    StatCard[]
    filter_fields:  FilterField[]
    filters:        Record<string, any>
    export_actions: ExportAction[]
}>()

const { statusColor } = useKycStatus()
const { formatDate, formatDateTime } = useFormatDate()

const filterBadge = ref(0)
const sort = ref(props.filters?.sort ?? '-created_at')

const formatNumber = (num: number) => num?.toLocaleString() || '0'

const getSortState = (field: string) => {
    if (sort.value === field)        return 'asc'
    if (sort.value === `-${field}`)  return 'desc'
    return null
}

const toggleSort = (field: string) => {
    sort.value = getSortState(field) === 'asc' ? `-${field}` : field
    router.get(route('admin.kyc.index'), { ...props.filters, sort: sort.value }, { preserveState: true, replace: true })
}
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-gray-400 mb-0.5">Admin</p>
                    <h2 class="text-base font-bold text-gray-900 leading-tight">KYC Submissions</h2>
                </div>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                    <i class="fa fa-database text-[9px]"></i>
                    {{ formatNumber(kycs.meta?.total || kycs.length || 0) }} Total
                </span>
            </div>
        </template>

        <div class="py-6 px-4 sm:px-6 max-w-7xl mx-auto">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50/80">
                            <tr>
                                <!-- Vendor -->
                                <th scope="col" class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                                    <div class="flex items-center gap-1.5">
                                        <i class="fa fa-user text-gray-300"></i>
                                        Vendor Info
                                    </div>
                                </th>

                                <!-- Full Name -->
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider cursor-pointer hover:text-gray-600 transition-colors select-none group"
                                    @click="toggleSort('full_name')"
                                >
                                    <div class="flex items-center gap-1.5">
                                        Full Name
                                        <span class="flex flex-col leading-[0]">
                                            <i :class="['fa fa-caret-up text-[9px]', getSortState('full_name') === 'asc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                            <i :class="['fa fa-caret-down text-[9px]', getSortState('full_name') === 'desc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                        </span>
                                    </div>
                                </th>

                                <!-- Location -->
                                <th scope="col" class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                                    Location
                                </th>

                                <!-- Status -->
                                <th scope="col" class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider">
                                    Status
                                </th>

                                <!-- Submitted -->
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider cursor-pointer hover:text-gray-600 transition-colors select-none group"
                                    @click="toggleSort('created_at')"
                                >
                                    <div class="flex items-center gap-1.5">
                                        Submitted
                                        <span class="flex flex-col leading-[0]">
                                            <i :class="['fa fa-caret-up text-[9px]', getSortState('created_at') === 'asc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                            <i :class="['fa fa-caret-down text-[9px]', getSortState('created_at') === 'desc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                        </span>
                                    </div>
                                </th>

                                <!-- Expiry -->
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-left text-[10px] font-bold text-gray-400 uppercase tracking-wider cursor-pointer hover:text-gray-600 transition-colors select-none group"
                                    @click="toggleSort('expires_at')"
                                >
                                    <div class="flex items-center gap-1.5">
                                        Expiry
                                        <span class="flex flex-col leading-[0]">
                                            <i :class="['fa fa-caret-up text-[9px]', getSortState('expires_at') === 'asc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                            <i :class="['fa fa-caret-down text-[9px]', getSortState('expires_at') === 'desc' ? 'text-blue-500' : 'text-gray-300']"></i>
                                        </span>
                                    </div>
                                </th>

                                <th scope="col" class="relative px-4 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-50">

                            <!-- Empty State -->
                            <tr v-if="!kycs?.length">
                                <td colspan="7" class="px-4 py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center">
                                            <i class="fa fa-inbox text-xl text-gray-300"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-700">No KYC submissions found</p>
                                            <p class="text-xs text-gray-400 mt-0.5">Try adjusting your filters</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <tr
                                v-for="kyc in kycs"
                                :key="kyc.id"
                                class="hover:bg-gray-50/60 transition-colors group"
                            >
                                <!-- Vendor Info -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2.5">
                                        <div class="flex-shrink-0 h-9 w-9 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-sm">
                                            {{ kyc.user?.name?.charAt(0)?.toUpperCase() || '?' }}
                                        </div>
                                        <div>
                                            <div class="text-sm font-semibold text-gray-900">{{ kyc.user?.name || 'N/A' }}</div>
                                            <div class="text-xs text-gray-400 flex items-center gap-1">
                                                <i class="fa fa-envelope text-gray-300"></i>
                                                {{ kyc.user?.email || '—' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Full Name -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="text-sm text-gray-700">{{ kyc.full_name || '—' }}</span>
                                </td>

                                <!-- Location -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 text-sm text-gray-600">
                                        <i class="fa fa-map-marker-alt text-gray-300"></i>
                                        {{ kyc.country?.name || '—' }}
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-semibold border"
                                        :class="statusColor(kyc.status?.slug)"
                                    >
                                        {{ kyc.status?.name || 'Unknown' }}
                                    </span>
                                </td>

                                <!-- Submitted -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 text-sm text-gray-500">
                                        <i class="fa fa-clock text-gray-300"></i>
                                        <span class="tabular-nums">{{ formatDateTime(kyc.created_at) }}</span>
                                    </div>
                                </td>

                                <!-- Expiry -->
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5 text-sm text-gray-500">
                                        <i class="fa fa-hourglass-half text-gray-300"></i>
                                        <span class="tabular-nums">{{ formatDate(kyc.expires_at) || '—' }}</span>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3 whitespace-nowrap text-right">
                                    <Link
                                        :href="route('admin.kyc.show', kyc.id)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-blue-600 hover:text-white bg-transparent hover:bg-blue-600 border border-blue-200 hover:border-blue-600 rounded-lg transition-all duration-150"
                                    >
                                        View <i class="fa fa-arrow-right text-[10px]"></i>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="kycs.meta?.last_page > 1" class="px-4 py-3 border-t border-gray-100 flex items-center justify-between gap-4 bg-gray-50/50">
                    <p class="text-xs text-gray-400">
                        Showing
                        <span class="font-semibold text-gray-600">{{ formatNumber(kycs.meta.from || 0) }}–{{ formatNumber(kycs.meta.to || 0) }}</span>
                        of <span class="font-semibold text-gray-600">{{ formatNumber(kycs.meta.total || 0) }}</span>
                    </p>
                    <nav class="flex items-center gap-1">
                        <Link
                            v-if="kycs.links?.prev"
                            :href="kycs.links.prev"
                            class="w-7 h-7 flex items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:bg-gray-100 text-xs transition-colors"
                        >
                            <i class="fa fa-chevron-left"></i>
                        </Link>
                        <span class="px-2.5 py-1 text-xs font-medium text-gray-600 bg-white border border-gray-200 rounded-md tabular-nums">
                            {{ kycs.meta.current_page }} / {{ kycs.meta.last_page }}
                        </span>
                        <Link
                            v-if="kycs.links?.next"
                            :href="kycs.links.next"
                            class="w-7 h-7 flex items-center justify-center rounded-md border border-gray-200 text-gray-500 hover:bg-gray-100 text-xs transition-colors"
                        >
                            <i class="fa fa-chevron-right"></i>
                        </Link>
                    </nav>
                </div>

            </div>
        </div>

        <!-- Filters Sidebar -->
        <RightFilterSidebar
            accent-color="#3B82F6"
            icon="🪪"
            label="KYC Filters"
            :badge-count="filterBadge"
        >
            <FiltersPanel
                :fields="filter_fields"
                :filters="filters"
                :stats-cards="stats_cards"
                :export-actions="export_actions"
                route-name="admin.kyc.index"
                @badge-count="filterBadge = $event"
            />
        </RightFilterSidebar>
    </AuthenticatedLayout>
</template>