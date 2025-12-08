<template>
    <LoginWidget
        middleware="guest"
        redirect-if-authenticated="/"
        title="User Login"
        path="login"
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
