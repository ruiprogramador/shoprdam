<!-- resources/js/Components/Sidebar.vue -->
<script setup>

/* import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue'; */
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
  open: Boolean,
});



</script>

<template>
    <!-- <aside
        class="navbar navbar-vertical navbar-expand-lg"
        :class="{ 'navbar-collapsed': !open }"
        data-bs-theme="dark"
    > -->
    <aside
        class="navbar navbar-vertical navbar-expand-lg navbar-collapse"
        :class="{ 'navbar-collapsed': !props.open }"
        v-if="props.open"
    >
        <div class="container-fluid">

            <!-- Logo -->
            <div class="navbar-brand">
                <ApplicationLogo
                    class="block h-9 w-auto fill-current text-zinc-50"
                />
            </div>

            <!-- Menu -->
            <div id="sidebar-menu">
                <div class="navbar-nav flex-column">
                    <slot name="menu" />
                </div>
            </div>
        </div>
    </aside>
</template>
