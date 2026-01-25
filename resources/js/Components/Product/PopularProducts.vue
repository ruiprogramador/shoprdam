<script setup>
import { ref } from 'vue'
import ProductCard from '@/Components/Product/ProductCard.vue'

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  }
})

const activeTab = ref('all')

const tabs = [
  { id: 'all', name: 'All' },
  { id: 'mens', name: "Men's" },
  { id: 'womens', name: "Women's" },
  { id: 'jewelry', name: 'Jewelry' },
  { id: 'denim', name: 'Denim' },
  { id: 'cosmetics', name: 'Cosmetics' },
  { id: 'watches', name: 'Watches' }
]

const getProductsByTab = (tabId) => {
  if (tabId === 'all') return props.products
  return props.products.filter(p => p.category?.slug === tabId)
}
</script>

<template>
  <section class="product-tabs section-padding position-relative mt-30">
    <div class="container">
      <div class="section-title style-2 wow animate__animated animate__fadeIn">
        <h3>Popular Products</h3>
        <ul class="nav nav-tabs links" role="tablist">
          <li
            v-for="tab in tabs"
            :key="tab.id"
            class="nav-item"
          >
            <button
              class="nav-link"
              :class="{ active: activeTab === tab.id }"
              @click="activeTab = tab.id"
              type="button"
            >
              {{ tab.name }}
            </button>
          </li>
        </ul>
      </div>

      <div class="tab-content">
        <div class="row product-grid-4">
          <div
            v-for="product in getProductsByTab(activeTab)"
            :key="product.id"
            class="col-6 col-lg-4 col-xl-3 col-xxl-2"
          >
            <ProductCard :product="product" wrap-class="mb-30" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
