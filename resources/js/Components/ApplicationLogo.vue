<script setup>

defineProps({
    width: {
        type: String,
        default: '48',
    },
    height: {
        type: String,
        default: '48',
    },
    class: {
        type: String,
        default: 'h-12 w-auto',
    },
    alt: {
        type: String,
        default: 'Application Logo',
    },
})

const logo = '/storage/img/logo.png'
</script>

<template>
    <img :src="logo" :alt="alt" :class="class" />
</template>
