<template>
    <LoginWidget
        middleware="admin"
        redirect-if-authenticated="/"
        title="Admin Login"
        path="admin.login"
        :status="status"
    />
</template>
<script setup>
import LoginWidget from '@/widgets/Login.vue';


defineProps({
    status: {
        type: String,
    },
});
</script>
