<script setup>
import ProductListItem from './ProductListItem.vue'

const props = defineProps({
  columns: {
    type: Array,
    default: () => []
  }
})
</script>

<template>
  <section class="section-padding mt-10 mb-30">
    <div class="container">
      <div class="row">
        <div
          v-for="column in columns"
          :key="column.id"
          class="col-6 col-xl-3 col-lg-4 col-md-6 mb-30"
        >
          <h4 class="section-title style-1 mb-30">{{ column.title }}</h4>
          <div class="product-list-small">
            <ProductListItem
              v-for="product in column.products"
              :key="product.id"
              :product="product"
            />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
