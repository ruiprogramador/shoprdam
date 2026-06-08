<script setup>
import { onMounted, ref } from 'vue'

defineOptions({ inheritAttrs: false })

const model = defineModel({
    type: String,
    required: true,
})

defineProps({
    class: {
        type: String,
        default: '',
    },
})

const input = ref(null)

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus()
    }
})

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
    <input
        v-bind="$attrs"
        ref="input"
        v-model="model"
        :class="[
            'rounded-md border-gray-300 shadow-sm',
            'focus:border-indigo-500 focus:ring-indigo-500',
            'disabled:opacity-50 disabled:cursor-not-allowed',
            'transition duration-150 ease-in-out',
            $props.class,
        ]"
    />
</template>