<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import LoginWidget from '@/widgets/Login.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';

const page = usePage()

const breadcrumbs = computed(() => page.props.breadcrumbs || [])
console.log('=== LOGIN PAGE DEBUG ===')
console.log('Full page.props:', page.props)
console.log('test_value:', page.props.test_value)
console.log('breadcrumbs:', page.props.breadcrumbs)
console.log('breadcrumbs computed:', breadcrumbs.value)
console.log('=======================')
const status = computed(() => page.props.status || null)

/* defineProps({
    status: {
        type: String,
    },
}); */

</script>

<template>
    <AppLayout>
        <Head title="Login" />

        <Breadcrumb :items="breadcrumbs" />

        <main class="main">
            <LoginWidget
                middleware="guest"
                redirect-if-authenticated="/"
                title="User Login"
                path="login"
                :status="status"
            />
        </main>
    </AppLayout>
</template>
