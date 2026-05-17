<script setup>
defineProps({
    value: {
        type: String,
        default: '',
    },
    for: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <label
        :for="for"
        class="block text-sm font-medium text-gray-700 mb-1"
    >
        <slot>{{ value }}</slot>
        <span v-if="required" class="text-red-500 ml-0.5" aria-hidden="true">*</span>
    </label>
</template>