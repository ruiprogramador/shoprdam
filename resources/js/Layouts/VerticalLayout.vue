<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import Sidebar from '@/Components/Sidebar.vue'
import HeaderNavbar from '@/Components/HeaderNavbar.vue'
import VerticalMenu from '@/Components/VerticalMenu.vue'

const sidebarOpen = ref(true);
const BREAKPOINT = 768;
const theme = ref('light');
const isMobile = ref(false);
const page = usePage();
const loggedInUser = ref(page.props.auth.user);

const updateByWidth = () => {
  sidebarOpen.value = window.innerWidth >= BREAKPOINT
  isMobile.value = window.innerWidth < BREAKPOINT
}

const toggleSidebar = (value) => {
  sidebarOpen.value = typeof value === 'boolean'
    ? value
    : !sidebarOpen.value
}

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    applyTheme()
}
const applyTheme = () => { document.documentElement.setAttribute('data-bs-theme', theme.value)
localStorage.setItem('theme', theme.value) }

onMounted(() => {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) { theme.value = savedTheme } applyTheme()

    updateByWidth()
    window.addEventListener('resize', updateByWidth)
})

onUnmounted(() => {
  window.removeEventListener('resize', updateByWidth)
})
</script>

<template>
    <div class="page flex">

        <!-- Sidebar -->
        <Sidebar
            :open="sidebarOpen"
            :theme="theme"
            :is-mobile="isMobile"
            @close="toggleSidebar(false)"
        >
            <template #vertical_menu>
                <VerticalMenu
                    @toggle-theme="toggleTheme"
                    :theme="theme"
                    :is-mobile="isMobile"
                    :logged-in-user="loggedInUser"
                />
            </template>
        </Sidebar>

        <!-- Main -->
        <div
            class="main-content flex-1 min-h-screen transition-all duration-300"
            :class="{ 'with-sidebar': sidebarOpen }"
        >
            <HeaderNavbar
                :sidebar-open="sidebarOpen"
                @toggle-sidebar="toggleSidebar"
                @toggle-theme="toggleTheme"
                :theme="theme"
                :is-mobile="isMobile"
                :logged-in-user="loggedInUser"
            />

            <main class="p-4">
                <slot />
            </main>
        </div>

    </div>
</template>
