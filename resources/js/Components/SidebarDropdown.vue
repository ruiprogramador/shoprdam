<!-- SidebarDropdown.vue -->
<script setup>
import { ref } from 'vue'

defineProps({
    label: { type: String, required: true },
    icon:  { type: String, default: '' },
})

const open = ref(false)
</script>

<template>
    <div>
        <button
            type="button"
            class="w-full flex items-center justify-between py-2 rounded-lg text-sm font-medium
                   text-gray-700 hover:bg-gray-100 hover:text-gray-900
                   focus:outline-none focus:ring-2 focus:ring-indigo-500
                   transition-all duration-150"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="flex items-center gap-3">
                <i v-if="icon" :class="[icon, 'w-4 text-center']" aria-hidden="true"></i>
                {{ label }}
            </span>
            <svg
                class="w-4 h-4 transition-transform duration-200"
                :class="open ? 'rotate-180' : ''"
                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <Transition
            enter-active-class="transition-all duration-200 ease-out overflow-hidden"
            enter-from-class="opacity-0 max-h-0"
            enter-to-class="opacity-100 max-h-48"
            leave-active-class="transition-all duration-150 ease-in overflow-hidden"
            leave-from-class="opacity-100 max-h-48"
            leave-to-class="opacity-0 max-h-0"
        >
            <div v-show="open" class="mt-1 ml-4 pl-3 border-l-2 border-gray-200 flex flex-col gap-0.5">
                <slot />
            </div>
        </Transition>
    </div>
</template>