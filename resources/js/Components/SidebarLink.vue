<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    icon: {
        type: [Object, String],
        default: null,
    },
})

const page = usePage()

const isActive = computed(() =>
    page.url === props.href ||
    page.url.startsWith(props.href + '/')
)
</script>

<template>
    <Link
        :href="href"
        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium
               transition-all duration-150
               focus:outline-none focus:ring-2 focus:ring-indigo-500"
        :class="isActive
            ? 'bg-indigo-600 text-white shadow-sm'
            : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800'"
        role="menuitem"
        :aria-current="isActive ? 'page' : undefined"
    >
        <!-- Componente Vue (Heroicons, etc.) -->
        <component
            v-if="icon && typeof icon === 'object'"
            :is="icon"
            class="w-5 h-5 flex-shrink-0"
            aria-hidden="true"
        />
        <!-- Font Awesome / string class -->
        <i
            v-else-if="icon && typeof icon === 'string'"
            :class="[icon, 'w-5 text-center flex-shrink-0']"
            aria-hidden="true"
        />
        <span class="truncate"><slot /></span>
    </Link>
</template>