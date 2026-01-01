<script setup>

import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

defineProps({
    sidebarOpen: Boolean,
    theme: String,
    isMobile: Boolean
})

const emit = defineEmits(['toggle-sidebar'])
const toggleTheme = () => {
    emit('toggle-theme');
}
</script>

<template>
    <header class="navbar">
        <button
            class="btn btn-icon toggle-sidebar"
            @click="emit('toggle-sidebar')"
        >
            <i :class="sidebarOpen ? 'fas fa-times' : 'fas fa-bars'"></i>
        </button>

        <!-- Right section -->
        <div class="navbar-nav flex-row order-md-last ms-auto" v-if="!isMobile">

            <!-- Dark mode placeholder -->
            <!-- <a href="#" class="nav-link px-0">
            🌙
            </a> -->
            <a
                href="#"
                class="nav-link px-0"
                @click.prevent="toggleTheme"
            >
                {{ theme === 'dark' ? '☀️' : '🌙' }}
            </a>


            <!-- User dropdown
            <div class="nav-item dropdown">
            <a href="#" class="nav-link d-flex lh-1 text-reset p-0">
                <span class="avatar avatar-sm"></span>
                <div class="d-none d-xl-block ps-2">
                <div>{{ $page.props.auth.user.name }}</div>
                <div class="mt-1 small text-secondary">Developer</div>
                </div>
            </a>
            </div> -->
            <Dropdown align="right" width="48">
                <div>{{ $page.props.auth.user.name }}</div>
                <template #trigger>
                    <span class="inline-flex rounded-md">
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                        >
                            {{ $page.props.auth.user.name }}

                            <svg
                                class="-me-0.5 ms-2 h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </button>
                    </span>
                </template>
                <template #content>
                    <DropdownLink
                        :href="route('profile.edit')"
                    >
                        Profile
                    </DropdownLink>
                    <DropdownLink
                        :href="route('logout')"
                        method="post"
                        as="button"
                    >
                        Log Out
                    </DropdownLink>
                </template>
            </Dropdown>
        </div>
    </header>
</template>
