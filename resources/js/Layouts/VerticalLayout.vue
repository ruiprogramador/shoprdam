<!-- resources/js/Layouts/VerticalLayout.vue -->
<script setup>
import { ref } from 'vue'
import Sidebar from '@/Components/Sidebar.vue'
import Navbar from '@/Components/Navbar.vue';
import Menu from '@/Components/Menu.vue';

const sidebarOpen = ref(true)

const toggleSidebar = () => {
  sidebarOpen.value = !sidebarOpen.value;
}
</script>

<template>
  <div class="page">
    <!-- <Sidebar :open="sidebarOpen" /> -->
    <Sidebar :open="sidebarOpen">
        <template #menu>
            <Menu />
        </template>
    </Sidebar>

    <div class="page-wrapper">
      <Navbar @toggle-sidebar="toggleSidebar" />

      <div class="page-body">
        <div class="container-xl">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>
