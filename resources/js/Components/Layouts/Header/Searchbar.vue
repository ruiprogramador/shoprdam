<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import CategoryDrilldown from '@/Components/CategoryDrilldown.vue'

const page             = usePage()
const searchQuery      = ref('')
const selectedCategory = ref('')
const categories       = computed(() => page.props.categories ?? [])

const handleSearch = () => {
    const query = { q: searchQuery.value }
    if (selectedCategory.value) query.category = selectedCategory.value
    router.get(route('shop.index'), query)
}
</script>

<template>
    <div class="search-style-2">
        <form @submit.prevent="handleSearch">

            <!-- SearchSelect em vez de <select> nativo -->
            <div style="width:180px; flex-shrink:0; border-right: 1px solid var(--borderColor); height: 50px">
                <CategoryDrilldown
                    v-model="selectedCategory"
                    :categories="categories"
                    placeholder="All Categories"
                />
            </div>
            <div class="search-input">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search for items..."
                />

                <button class="btn-searchbox" type="submit" aria-label="Search" style="color: var(--textColor);" />
            </div>

        </form>
    </div>
</template>