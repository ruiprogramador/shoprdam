<script setup>
import NavLink from '@/Components/NavLink.vue';
import { ref, onMounted  } from 'vue';
import Sidebar from './Sidebar.vue';

const openManagement = ref(false)

const toggleManagement = () => {
  openManagement.value = !openManagement.value
}

</script>

<template>
    <Sidebar :open="true">
        <template #menu>
            <!-- <NavLink href="#" :active="true">Dashboard</NavLink>
            <NavLink href="#">Settings</NavLink>
            <NavLink href="#">Profile</NavLink>
            <NavLink href="#">Help</NavLink> -->
                <!-- Dashboard -->
                <li class="nav-item">
                    <NavLink href="#" :active="true">
                        <span class="nav-link-title">
                            <i class="fas fa-chart-line ms-1"></i>
                            Dashboard
                        </span>
                    </NavLink>
                </li>

                <!-- DROPDOWN -->
                <li
                    class="nav-item dropdown"
                    :class="{ show: openManagement }"
                >
                    <a
                        href="#"
                        class="nav-link dropdown-toggle"
                        @click.prevent="toggleManagement"
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
                        <span class="nav-link-title">
                            <i class="fas fa-user-circle"></i>
                            Profile
                        </span>
                    </NavLink>
                </li>

                <!-- Help -->
                <li class="nav-item">
                    <NavLink href="#">
                        <span class="nav-link-title">
                            <i class="fas fa-question-circle"></i>
                            Help
                        </span>
                    </NavLink>
                </li>
                <li class="nav-item">
                    <NavLink href="#">
                        <span class="nav-link-title">
                            <i class="fas fa-sign-out-alt"></i>
                            Logout
                        </span>
                    </NavLink>
                </li>
        </template>
    </Sidebar>
</template>
