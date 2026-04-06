<script setup>

import { ref, computed } from 'vue';
import NavLink from '@/Components/NavLink.vue';

/* defineProps({
    theme: String,
    isMobile: Boolean
}) */
const props = defineProps({
    theme: String,
    isMobile: Boolean,
    loggedInUser: Object
})

const userType = computed(() => {
    if (props?.loggedInUser?.user_type_id === 2) return 'vendor';
    return 'admin';
})

const dashboardRoute = computed(() => {
    switch (userType.value) {
        case 'admin':
            return route('admin.dashboard');
        default:
            return route('dashboard');
    }
})

const profileRoute = computed(() => {
    switch (userType.value) {
        case 'admin':
            return route('admin.profile.edit');
        default:
            return route('profile.edit');
    }
})

const emit = defineEmits(['toggle-theme']);

const openManagement = ref(false);
</script>

<template>
    <!-- Dashboard -->
    <li class="nav-item">
        <!-- <NavLink :href="route('admin.dashboard')" :active="route().current('admin.dashboard')">
            <i class="fas fa-chart-line me-2"></i>
            Dashboard
        </NavLink> -->
        <NavLink :href="dashboardRoute" :active="route().current(dashboardRoute)">
            <i class="fas fa-chart-line me-2"></i>
            Dashboard
        </NavLink>
    </li>

    <!-- DROPDOWN -->
    <li
        class="nav-item dropdown"
    >
        <a
            href="#"
            class="nav-link dropdown-toggle"
            @click.prevent="openManagement = !openManagement"
        >
            <span class="nav-link-title">
                <i class="fas fa-users-cog ms-1"></i>
                Management
            </span>
        </a>

        <!-- DROPDOWN CONTENT -->
        <div class="dropdown-menu" :class="{ show: openManagement }">
            <NavLink href="#" class="dropdown-item">
                <i class="fas fa-user-circle ms-1"></i>
                Users
            </NavLink>

            <NavLink href="#" class="dropdown-item">
                <i class="fas fa-cog ms-1"></i>
                Settings

            </NavLink>

            <NavLink href="#" class="dropdown-item">
                <i class="fas fa-lock ms-1"></i>
                Roles
            </NavLink>
        </div>
    </li>

    <!-- Profile -->
    <li class="nav-item">
        <!-- <NavLink :href="route('admin.profile.edit')" :active="route().current('admin.profile.edit')">
            <i class="fas fa-user-circle me-2"></i>
            Profile
        </NavLink> -->
        <NavLink :href="profileRoute" :active="route().current(profileRoute)">
            <i class="fas fa-user-circle me-2"></i>
            Profile
        </NavLink>
    </li>

    <!-- Theme Toggle (Mobile Only) -->
    <li v-if="isMobile" class="nav-item border-top">
        <a
            href="#"
            class="nav-link"
            @click.prevent="emit('toggle-theme')"
        >
            {{ theme === 'dark' ? '☀️' : '🌙' }}
        </a>
    </li>
</template>
