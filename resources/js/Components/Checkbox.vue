<script setup>
import { computed } from 'vue'

defineOptions({ inheritAttrs: false })

const emit = defineEmits(['update:checked'])

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
})

const proxyChecked = computed({
    get() {
        return props.checked
    },
    set(val) {
        emit('update:checked', val)
    },
})
</script>

<template>
    <input
        v-bind="$attrs"
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="rounded border-gray-300 text-indigo-600 shadow-sm
               focus:ring-indigo-500 focus:ring-offset-0
               disabled:opacity-50 disabled:cursor-not-allowed
               cursor-pointer w-4 h-4"
    />
</template>
