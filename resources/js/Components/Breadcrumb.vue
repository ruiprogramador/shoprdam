<!-- @/Components/Breadcrumb.vue -->
<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    items: {
        type: Array,
        required: false,
        default: () => []
    }
})

// Force reactivity
const breadcrumbItems = computed(() => props.items || [])

console.log('Breadcrumb component loaded')
console.log('Items:', breadcrumbItems.value)
</script>

<template>
    <div class="breadcrumb-area" v-if="items && items.length > 0">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li
                        v-for="(item, index) in items"
                        :key="index"
                        class="breadcrumb-item"
                        :class="{ active: !item.url }"
                    >
                        <Link v-if="item.url" :href="item.url">
                            {{ item.label }}
                        </Link>
                        <span v-else>{{ item.label }}</span>
                    </li>
                </ol>
            </nav>
        </div>
    </div>
</template>
