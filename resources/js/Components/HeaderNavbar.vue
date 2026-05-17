<script setup>
import { computed } from 'vue'
import Dropdown     from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const props = defineProps({
    sidebarOpen:  { type: Boolean, default: false },
    theme:        { type: String,  default: 'light' },
    isMobile:     { type: Boolean, default: false },
    loggedInUser: { type: Object,  default: null },
})

const emit = defineEmits(['toggle-sidebar', 'toggle-theme'])

const userType = computed(() =>
    props.loggedInUser?.user_type_id === 2 ? 'vendor' : 'admin'
)

const profileRoute = computed(() =>
    userType.value === 'admin' ? route('admin.profile.edit') : route('profile.edit')
)

const logoutRoute = computed(() =>
    userType.value === 'admin' ? route('admin.logout') : route('logout')
)
</script>

<template>
    <header class="sticky top-0 z-30 w-full bg-white border-b border-gray-200 shadow-sm">
        <div class="flex items-center h-14 sm:h-16 px-3 sm:px-4 gap-2 sm:gap-4">

            <!-- Sidebar toggle -->
            <SecondaryButton
                :aria-label="sidebarOpen ? 'Fechar menu' : 'Abrir menu'"
                @click="emit('toggle-sidebar')"
            >
                <svg v-if="sidebarOpen" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </SecondaryButton>

            <!-- Spacer -->
            <div class="flex-1" />

            <!-- Direita -->
            <div class="flex items-center gap-1 sm:gap-2">

                <!-- Theme toggle -->
                <button
                    type="button"
                    class="p-2 rounded-md text-gray-500 hover:text-gray-900 hover:bg-gray-100
                           focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    :aria-label="theme === 'dark' ? 'Mudar para modo claro' : 'Mudar para modo escuro'"
                    @click.prevent="emit('toggle-theme')"
                >
                    <span class="text-base leading-none select-none">
                        {{ theme === 'dark' ? '☀️' : '🌙' }}
                    </span>
                </button>

                <!-- User dropdown -->
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-md border border-transparent
                                   bg-white px-2 sm:px-3 py-2 text-sm font-medium leading-4
                                   text-gray-500 hover:text-gray-700
                                   focus:outline-none focus:ring-2 focus:ring-indigo-500
                                   transition duration-150 ease-in-out max-w-[140px] sm:max-w-none"
                        >
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full
                                         bg-indigo-100 text-indigo-700 text-xs font-bold flex-shrink-0">
                                {{ $page.props.auth.user.name?.charAt(0).toUpperCase() }}
                            </span>
                            <span class="hidden sm:block truncate">
                                {{ $page.props.auth.user.name }}
                            </span>
                            <svg class="-me-0.5 ms-1 h-4 w-4 flex-shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </template>
                    <template #content>
                        <DropdownLink :href="profileRoute">Profile</DropdownLink>
                        <DropdownLink :href="logoutRoute" method="post" as="button">Log Out</DropdownLink>
                    </template>
                </Dropdown>
            </div>

        </div>
    </header>
</template>