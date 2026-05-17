<script setup>
// Este layout é um alias de VerticalLayout.
// Migra gradualmente as tuas páginas para:
//   import VerticalLayout from '@/Layouts/VerticalLayout.vue'
// e apaga este ficheiro quando estiver feito.
import VerticalLayout from '@/Layouts/VerticalLayout.vue'
</script>

<template>
    <VerticalLayout>
        <template v-if="$slots.header" #header>
            <slot name="header" />
        </template>
        <slot />
    </VerticalLayout>
</template>