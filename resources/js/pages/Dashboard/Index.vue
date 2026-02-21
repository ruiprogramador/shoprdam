
<script setup lang="ts">
    import { onMounted, computed } from 'vue'
    import { router , Head, usePage} from '@inertiajs/vue3'
    import { useToast } from 'vue-toastification'
    import { useDashboardStore } from '@/Stores/dashboardStore'
    import DashboardSidebar from '@/Components/Dashboard/DashboardSidebar.vue'
    import DashboardOverview from '@/Components/Dashboard/DashboardOverview.vue'
    import OrdersList from '@/Components/Dashboard/OrdersList.vue'
    import TrackOrders from '@/Components/Dashboard/TrackOrders.vue'
    import AddressList from '@/Components/Dashboard/AddressList.vue'
    import AccountDetails from '@/Components/Dashboard/AccountDetails.vue'
    import WishlistTab from '@/Components/Dashboard/WishlistTab.vue'
    import AppLayout from '@/Layouts/AppLayout.vue';
    import Breadcrumb from '@/Components/Breadcrumb.vue';
    import Edit from '../Profile/Edit.vue'

    // Get page props for flash messages
    const page = usePage()
    const toast = useToast()
    const dashboardStore = useDashboardStore()

    const breadcrumbs = computed(() => page.props.breadcrumbs || [])

    // Check for flash messages
    onMounted(() => {
        const flash = page.props.flash as { success?: string; error?: string }

        if (flash?.success) {
            toast.success(flash.success)
        }

        if (flash?.error) {
            toast.error(flash.error)
        }
    })

    const handleTabChange = (tab: string) => {
        dashboardStore.setActiveTab(tab)
    }

    const handleViewOrder = (orderId: string) => {
        router.visit(route('dashboard.order.details', orderId))
    }
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />

        <Breadcrumb :items="breadcrumbs" />

        <div class="main pages">
            <div class="page-content pt-70 pb-60">
                <div class="container">
                    <div class="row">
                        <div class="col-12">
                            <div class="row">
                                <div class="col-md-3">
                                    <DashboardSidebar
                                        :active-tab="dashboardStore.activeTab"
                                        @tab-change="handleTabChange"
                                    />
                                </div>

                                <div class="col-md-9">
                                    <div class="tab-content account dashboard-content pl-50">
                                        <!-- Dashboard Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'dashboard'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'dashboard' }"
                                        >
                                            <DashboardOverview />
                                        </div>

                                        <!-- Orders Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'orders'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'orders' }"
                                        >
                                            <OrdersList @view-order="handleViewOrder" />
                                        </div>

                                        <!-- Track Orders Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'track-orders'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'track-orders' }"
                                        >
                                            <TrackOrders />
                                        </div>

                                        <!-- Address Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'address'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'address' }"
                                        >
                                            <AddressList />
                                        </div>

                                        <!-- Account Details Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'account-detail'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'account-detail' }"
                                        >
                                            <!-- <AccountDetails /> -->
                                            <Edit :must-verify-email="false" :status="null" />
                                        </div>

                                        <!-- Wishlist Tab -->
                                        <div
                                            v-show="dashboardStore.activeTab === 'wishlist-tab'"
                                            class="tab-pane fade"
                                            :class="{ 'active show': dashboardStore.activeTab === 'wishlist-tab' }"
                                        >
                                            <WishlistTab />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
