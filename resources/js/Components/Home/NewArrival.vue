<script setup>
import { Link } from '@inertiajs/vue3'
import ProductCard from '@/Components/Product/ProductCard.vue'

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  }
})
</script>

<template>
  <section class="new_arrival mt-40">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <div class="section-title wow animate__animated animate__fadeIn">
                <h3>New Arrival</h3>
                <!-- toDo -->
                <!-- <Link :href="route('shop.index', { filter: 'new' })" class="view_all_btn">
                    View All <i class="fa-solid fa-arrow-right ms-2"></i>
                </Link> -->
          </div>
        </div>
      </div>

      <div class="row">
        <div
          v-for="product in products"
          :key="product.id"
          class="col-6 col-lg-4 col-xl-3 col-xxl-2"
        >
          <ProductCard :product="product" />
        </div>
      </div>
    </div>
  </section>
</template>
