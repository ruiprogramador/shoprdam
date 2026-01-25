<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
  product: {
    type: Object,
    required: true
  }
})
</script>

<template>
  <article class="row align-items-center hover-up">
    <figure class="col-sm-4 mb-0">
        <!-- toDo -->
      <!--   <Link :href="route('product.show', product.slug)">
            <img :src="product.thumbnail" :alt="product.name" />
        </Link> -->
    </figure>

    <div class="col-sm-8 mb-0">
        <h6>
            <!-- toDo -->
            <!-- <Link :href="route('product.show', product.slug)">
                {{ product.name }}
            </Link> -->
        </h6>

      <div class="product-rate-cover">
        <div class="product-rate d-inline-block">
          <div class="product-rating" :style="{ width: product.rating + '%' }"></div>
        </div>
        <span class="font-small ml-5 text-muted">({{ product.review_count }})</span>
      </div>

      <div class="product-price">
        <span>${{ product.price }}</span>
        <span v-if="product.old_price" class="old-price">${{ product.old_price }}</span>
      </div>
    </div>
  </article>
</template>
