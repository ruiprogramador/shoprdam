<script setup>
import NavLink from '@/Components/NavLink.vue';
import { ref } from 'vue';

defineProps({
    theme: String,
    isMobile: Boolean
})

const emit = defineEmits(['toggle-theme']);

const openManagement = ref(false);
</script>

<template>
    <!-- Dashboard -->
    <li class="nav-item">
        <NavLink href="#" :active="true">
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
        <NavLink href="#">
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
