<script setup>
import { ref, computed } from 'vue'
import NavLink from '@/Components/NavLink.vue'
import SidebarDropdown from '@/Components/SidebarDropdown.vue'

const props = defineProps({
    theme: {
        type: String,
        default: 'light',
    },
    isMobile: {
        type: Boolean,
        default: false,
    },
    loggedInUser: {
        type: Object,
        default: null,
    },
})

const emit = defineEmits(['toggle-theme'])

const userType = computed(() => {
    if (props.loggedInUser?.user_type_id === 2) return 'vendor'
    return 'admin'
})

const dashboardRoute = computed(() => {
    if (userType.value === 'admin') return route('admin.dashboard')
    return route('dashboard')
})

const profileRoute = computed(() => {
    if (userType.value === 'admin') return route('admin.profile.edit')
    return route('profile.edit')
})

const openManagement = ref(false)
</script>

<template>
    <!-- Dashboard -->
    <NavLink
        :href="dashboardRoute"
        :active="route().current('admin.dashboard') || route().current('dashboard')"
    >
        <i class="fas fa-chart-line me-2 w-4 text-center" aria-hidden="true"></i>
        Dashboard
    </NavLink>

    <!-- Management Dropdown -->
    <SidebarDropdown label="Management" icon="fas fa-users-cog">
        <NavLink href="#" class="text-sm py-1.5">
            <i class="fas fa-user-circle me-2 w-4 text-center" aria-hidden="true"></i>
            Users
        </NavLink>
        <NavLink href="#" class="text-sm py-1.5">
            <i class="fas fa-cog me-2 w-4 text-center" aria-hidden="true"></i>
            Settings
        </NavLink>
        <NavLink href="#" class="text-sm py-1.5">
            <i class="fas fa-lock me-2 w-4 text-center" aria-hidden="true"></i>
            Roles
        </NavLink>
    </SidebarDropdown>

    <!-- Profile -->
    <NavLink
        :href="profileRoute"
        :active="route().current('admin.profile.edit') || route().current('profile.edit')"
    >
        <i class="fas fa-user-circle me-2 w-4 text-center" aria-hidden="true"></i>
        Profile
    </NavLink>

    <!-- Theme Toggle (Mobile Only) -->
    <div v-if="isMobile" class="mt-2 pt-2 border-t border-gray-200">
        <button
            type="button"
            class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium
                   text-gray-700 hover:bg-gray-100
                   focus:outline-none focus:ring-2 focus:ring-indigo-500
                   transition-all duration-150"
            @click.prevent="emit('toggle-theme')"
        >
            <span class="w-4 text-center">{{ theme === 'dark' ? '☀️' : '🌙' }}</span>
            {{ theme === 'dark' ? 'Modo Claro' : 'Modo Escuro' }}
        </button>
    </div>
</template>