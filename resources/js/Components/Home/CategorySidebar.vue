<script setup>
import { ref, onMounted } from 'vue'
import { Link }           from '@inertiajs/vue3'
import axios              from 'axios'

const categories      = ref([])
const hoveredCategory = ref(null)

onMounted(async () => {
    try {
        const response = await axios.get('/api/categories')
        categories.value = response.data.data ?? []
    } catch (error) {
        console.error('Erro ao carregar categorias da sidebar:', error)
        categories.value = []
    }
})
</script>

<template>
    <div class="categories-dropdown-wrap style-2 font-heading mt-30">
        <div class="d-flex categori-dropdown-inner">
            <ul>
                <li
                    v-for="category in categories"
                    :key="category.id"
                    @mouseenter="hoveredCategory = category.id"
                    @mouseleave="hoveredCategory = null"
                >
                    <Link :href="route('category.show', category.slug)">
                        <img :src="category.icon" :alt="category.name" />
                        {{ category.name }}
                    </Link>
                    <ul v-if="category.children?.length">
                        <li v-for="child in category.children" :key="child.id">
                            <Link :href="route('category.show', child.slug)">{{ child.name }}</Link>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
        <!-- toDo: <Link :href="route('categories.index')" class="more_categories">view all</Link> -->
    </div>
</template>