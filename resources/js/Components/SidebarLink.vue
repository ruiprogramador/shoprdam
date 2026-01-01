<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps({
  href: String,
  icon: Object,
})

const page = usePage()

const isActive = computed(() =>
  page.url.startsWith(props.href)
)
</script>

<template>
  <Link
    :href="href"
    class="flex items-center gap-3 px-3 py-2 rounded-lg
           transition
           focus:outline-none focus:ring-2 focus:ring-indigo-500"
    :class="isActive
      ? 'bg-indigo-600 text-white'
      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
    role="menuitem"
  >
    <component :is="icon" class="w-5 h-5" />
    <span><slot /></span>
  </Link>
</template>
