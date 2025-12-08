<!-- resources/js/Pages/Dashboard.vue -->
<script setup>
import VerticalLayout from '@/Layouts/VerticalLayout.vue';

import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

defineOptions({
  layout: VerticalLayout,
})

// Example reactive data
const stats = ref([
  { title: 'Revenue', value: '$7,825', icon: '💰', color: 'bg-primary' },
  { title: 'Orders', value: '1,245', icon: '🛒', color: 'bg-green' },
  { title: 'Users', value: '3,402', icon: '👤', color: 'bg-yellow' },
  { title: 'Performance', value: '94%', icon: '📈', color: 'bg-red' },
])

const activities = ref([
  { name: 'Egan Poetz', text: 'registered new client as Trilia.', time: '24 hours ago', avatar: '004m.jpg' },
  { name: 'Kellie Skingley', text: 'closed a new deal for Pen Pineapple Apple Pen.', time: '2 days ago', avatar: '002f.jpg' },
  { name: 'Christabel Charlwood', text: 'created new project for Wikibox.', time: '4 days ago', avatar: '003f.jpg' },
])

const orders = ref([
  { name: 'Icons', client: 'Tookapic', number: '87956621', date: '13 May 2018', amount: '$940', status: 'Paid' },
  { name: 'Video', client: 'Poland', number: '87956622', date: '14 May 2018', amount: '$1,200', status: 'Pending' },
])

</script>

<template>

    <Head title="Dashboard" />

    <!--
        <div class="row row-cards">

            <div class="col-12">
            <div class="card">
                <div class="card-header">
                <h3 class="card-title">Dashboard</h3>
                </div>
                <div class="card-body">
                    Welcome 🥳
                </div>
            </div>
            </div>

        </div>
    -->

    <div class="row row-deck row-cards">

        <!-- Welcome / chart -->
        <div class="col-sm-12 col-lg-6">
        <div class="card">
            <div class="card-body">
                <h3 class="h2">Welcome back, {{$page.props.auth.user.name}}</h3>
                <!-- <p class="text-muted">
                    You have {{ activities.length }} new notifications.
                </p> -->

                <!-- Chart placeholder -->
                <!-- <div
                    id="chart-active-users"
                    class="position-relative chart-sm mt-4"
                    style="min-height: 200px;"
                >
                    <div class="text-muted text-center pt-5">
                    Chart will render here 📈
                    </div>
                </div> -->

            </div>
        </div>
        </div>

        <!-- Stat cards -->
        <div class="col-12">
        <div class="row row-cards">
            <div
            v-for="(stat, index) in stats"
            :key="index"
            class="col-sm-6 col-lg-3"
            >
            <div class="card card-sm">
                <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                    <span
                        class="avatar text-white"
                        :class="stat.color"
                    >
                        {{ stat.icon }}
                    </span>
                    </div>
                    <div class="col">
                    <div class="font-weight-medium">
                        {{ stat.title }}
                    </div>
                    <div class="text-muted">
                        {{ stat.value }}
                    </div>
                    </div>
                </div>
                </div>
            </div>
            </div>

        </div>
        </div>

        <!-- Activity feed -->
        <!-- <div class="col-12 col-lg-6">
        <div class="card" style="height: 28rem">
            <div class="card-header">
            <h3 class="card-title">Recent activity</h3>
            </div>

            <div class="card-body card-body-scrollable card-body-scrollable-shadow">
            <div class="divide-y">

                <div
                v-for="(activity, index) in activities"
                :key="index"
                class="py-3"
                >
                <div class="row">
                    <div class="col-auto">
                    <span
                        class="avatar avatar-1"
                        :style="`background-image: url(/images/${activity.avatar})`"
                    ></span>
                    </div>
                    <div class="col">
                    <div class="text-truncate">
                        <strong>{{ activity.name }}</strong> {{ activity.text }}
                    </div>
                    <div class="text-secondary">
                        {{ activity.time }}
                    </div>
                    </div>
                </div>
                </div>

            </div>
            </div>
        </div>
        </div> -->

        <!-- Orders table -->
        <!-- <div class="col-12 col-lg-6">
        <div class="card">
            <div class="card-header">
            <h3 class="card-title">Latest orders</h3>
            </div>

            <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                <tr>
                    <th>Product</th>
                    <th>Client</th>
                    <th>Number</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th class="text-end">Status</th>
                </tr>
                </thead>

                <tbody>
                <tr v-for="(order, index) in orders" :key="index">
                    <td>{{ order.name }}</td>
                    <td>{{ order.client }}</td>
                    <td>{{ order.number }}</td>
                    <td>{{ order.date }}</td>
                    <td>{{ order.amount }}</td>
                    <td class="text-end">
                    <span
                        class="badge"
                        :class="order.status === 'Paid'
                        ? 'bg-success'
                        : 'bg-warning text-dark'"
                    >
                        {{ order.status }}
                    </span>
                    </td>
                </tr>
                </tbody>

            </table>
            </div>
        </div>
        </div> -->

    </div>

</template>
