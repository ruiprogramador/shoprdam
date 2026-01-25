<script setup>
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const searchQuery = ref('')
const selectedCategory = ref('')
const categories = ref([])

onMounted(async () => {
  /* try {
    const response = await axios.get('/api/categories')
    categories.value = response.data.data
  } catch (error) {
    console.error('Error fetching categories:', error)
  } */
})

const handleSearch = () => {
  const query = { q: searchQuery.value }
  if (selectedCategory.value) {
    query.category = selectedCategory.value
  }
  router.get(route('shop.index'), query)
}
</script>

<template>
    <div class="search-style-2">
        <form @submit.prevent="handleSearch">
            <select v-model="selectedCategory" class="select-active">
                <option value="">All Categories</option>
                <option v-for="category in categories" :key="category.id" :value="category.slug">
                    {{ category.name }}
                </option>
            </select>
            <input v-model="searchQuery" type="text" placeholder="Search for items..." />
            <button class="btn-searchbox" type="submit"><i class="fi-rs-search"></i></button>
        </form>
    </div>
</template>
