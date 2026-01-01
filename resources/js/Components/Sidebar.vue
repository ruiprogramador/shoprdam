<!-- resources/js/Components/Sidebar.vue -->
<script setup>

/* import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue'; */
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
    open: Boolean,
    theme: String,
    isMobile: Boolean
});

const emit = defineEmits(['close'])

</script>

<template>
    <!-- <aside
        class="navbar navbar-vertical navbar-expand-lg"
        :class="{ 'navbar-collapsed': !open }"
        data-bs-theme="dark"
    > -->
    <aside
        class="navbar navbar-vertical navbar-expand navbar-collapse sidebar"
        :class="{ 'navbar-collapsed': !props.open }"
        v-if="props.open"
    >
        <div class="container-fluid">

            <!-- Logo -->
            <div class="navbar-brand">
                <ApplicationLogo
                    class="block h-9 w-auto fill-current text-zinc-50"
                />
                <!-- Close button (mobile only) -->
                <button
                    v-if="isMobile"
                    class="btn btn-icon mobile-close-btn"
                    aria-label="Close sidebar"
                    @click="emit('close')"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Vertical Menu -->
            <div id="sidebar-vertical-menu">
                <div class="navbar-nav flex-column">
                    <slot name="vertical_menu" />
                </div>
            </div>
        </div>
    </aside>
</template>
