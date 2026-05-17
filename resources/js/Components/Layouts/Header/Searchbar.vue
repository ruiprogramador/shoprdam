<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const page           = usePage()
const searchQuery    = ref('')
const selectedCategory = ref('')

// Categorias via Inertia shared props (partilhadas pelo middleware)
// Se não tiveres isto no middleware, usa axios e descomenta o bloco em baixo.
const categories = computed(() => page.props.categories ?? [])

/*
// Alternativa axios:
import axios from 'axios'
import { onMounted } from 'vue'
const categories = ref([])
onMounted(async () => {
    try {
        const response = await axios.get('/api/categories')
        categories.value = response.data.data
    } catch (error) {
        console.error('Erro ao carregar categorias:', error)
    }
})
*/

const handleSearch = () => {
    const query = { q: searchQuery.value }
    if (selectedCategory.value) query.category = selectedCategory.value
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
            <button class="btn-searchbox" type="submit"><i class="fa fa-search"></i></button>
        </form>
    </div>
</template>