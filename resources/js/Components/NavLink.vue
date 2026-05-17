<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
        default: false,
    },
})

const classes = computed(() =>
    props.active ? 'active-nav-link' : 'normal-nav-link'
)
</script>

<template>
    <Link :href="href" :class="classes" v-bind="$attrs">
        <slot />
    </Link>
</template>