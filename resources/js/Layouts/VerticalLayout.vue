<script setup>
import { ref, provide, onMounted, onUnmounted } from 'vue'
import { usePage }    from '@inertiajs/vue3'
import Sidebar        from '@/Components/Sidebar.vue'
import HeaderNavbar   from '@/Components/HeaderNavbar.vue'
import VerticalMenu   from '@/Components/VerticalMenu.vue'
import { LAYOUT_STATE_KEY } from '@/Composables/useLayoutState'

const BREAKPOINT = 1024

const page         = usePage()
const loggedInUser = ref(page.props.auth.user)
const isMobile     = ref(window.innerWidth < BREAKPOINT)
const sidebarOpen  = ref(!isMobile.value)
const theme        = ref('light')

const applyTheme = () => {
    document.documentElement.setAttribute('data-bs-theme', theme.value)
    try { localStorage.setItem('theme', theme.value) } catch {}
}

const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    applyTheme()
}

const toggleSidebar = (value) => {
    sidebarOpen.value = typeof value === 'boolean' ? value : !sidebarOpen.value
}

const updateByWidth = () => {
    const mobile = window.innerWidth < BREAKPOINT
    if (mobile !== isMobile.value) {
        isMobile.value    = mobile
        sidebarOpen.value = !mobile
    }
}

onMounted(() => {
    try {
        const saved = localStorage.getItem('theme')
        if (saved) theme.value = saved
    } catch {}
    applyTheme()
    updateByWidth()
    window.addEventListener('resize', updateByWidth)
})

onUnmounted(() => window.removeEventListener('resize', updateByWidth))

const closeSidebar = () => { sidebarOpen.value = false }

provide(LAYOUT_STATE_KEY, {
    sidebarOpen,
    isMobile,
    closeSidebar,
    toggleSidebar,
})
</script>

<template>
    <div class="min-h-screen flex flex-col bg-gray-50 dark:bg-gray-900">

        <!-- HEADER — full width, sticky no topo -->
        <HeaderNavbar
            :sidebar-open="sidebarOpen"
            :theme="theme"
            :is-mobile="isMobile"
            :logged-in-user="loggedInUser"
            @toggle-sidebar="toggleSidebar"
            @toggle-theme="toggleTheme"
        />

        <div class="flex flex-1">

            <Sidebar
                :open="sidebarOpen"
                :theme="theme"
                :is-mobile="isMobile"
                @close="toggleSidebar(false)"
            >
                <template #vertical_menu>
                    <VerticalMenu
                        :theme="theme"
                        :is-mobile="isMobile"
                        :logged-in-user="loggedInUser"
                        @toggle-theme="toggleTheme"
                    />
                </template>
            </Sidebar>

            <!-- MAIN -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 min-h-[calc(100vh-3.5rem)]">
                <header v-if="$slots.header" class="mb-6">
                    <slot name="header" />
                </header>
                <slot />
            </main>

        </div>
    </div>
</template>