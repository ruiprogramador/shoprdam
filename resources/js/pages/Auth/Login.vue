<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import LoginWidget from '@/widgets/Login.vue';

defineProps({
    status: {
        type: String,
    },
});

</script>

<template>
    <AppLayout>
        <!-- <Head title="Login - ShopRDAM" /> -->

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
