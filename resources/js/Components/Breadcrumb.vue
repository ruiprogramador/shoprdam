<!-- @/Components/Breadcrumb.vue -->
<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    items: {
        type: Array,
        default: () => []
    }
})
</script>

<template>
    <nav v-if="items.length > 0" aria-label="breadcrumb" class="w-full">
        <ol class="flex flex-wrap items-center gap-1 text-sm text-gray-500">
            <li
                v-for="(item, index) in items"
                :key="index"
                class="flex items-center gap-1"
                :aria-current="!item.url ? 'page' : undefined"
            >
                <span v-if="index > 0" class="text-gray-400 select-none">/</span>
                <Link
                    v-if="item.url"
                    :href="item.url"
                    class="hover:text-gray-900 hover:underline transition-colors duration-150 truncate max-w-[140px] sm:max-w-none"
                >
                    {{ item.label }}
                </Link>
                <span v-else class="text-gray-900 font-medium truncate max-w-[140px] sm:max-w-none">
                    {{ item.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
