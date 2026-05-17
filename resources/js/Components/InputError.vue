<script setup>
defineProps({
    message: {
        type: String,
        default: '',
    },
})
</script>

<template>
    <p v-if="message" class="text-sm text-red-600 mt-1" role="alert">
        {{ message }}
    </p>
</template>