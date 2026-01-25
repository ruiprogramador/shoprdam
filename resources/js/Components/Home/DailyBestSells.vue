<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'
import DailyDealCard from './DailyDealCard.vue'

const props = defineProps({
  products: {
    type: Array,
    default: () => []
  }
})

const activeTab = ref('featured')
const tabs = [
  { id: 'featured', name: 'Featured' },
  { id: 'popular', name: 'Popular' },
  { id: 'new', name: 'New added' }
]

onMounted(async () => {
  await nextTick()
  initCarousel()
})

const initCarousel = () => {
  if (window.$ && window.$.fn.slick) {
    $('.carausel-4-columns').slick({
      slidesToShow: 4,
      slidesToScroll: 1,
      arrows: true,
      dots: false,
      responsive: [
        { breakpoint: 1400, settings: { slidesToShow: 3 } },
        { breakpoint: 992, settings: { slidesToShow: 2 } },
        { breakpoint: 768, settings: { slidesToShow: 1 } }
      ]
    })
  }
}
</script>

<template>
  <section class="section-padding pb-5">
    <div class="container">
      <div class="section-title wow animate__animated animate__fadeIn">
        <h3>Daily Best Sells</h3>
        <ul class="nav nav-tabs links" role="tablist">
          <li v-for="tab in tabs" :key="tab.id" class="nav-item">
            <button
              class="nav-link"
              :class="{ active: activeTab === tab.id }"
              @click="activeTab = tab.id"
            >
              {{ tab.name }}
            </button>
          </li>
        </ul>
      </div>

      <div class="row">
        <div class="col-lg-3 d-none d-lg-flex">
          <div class="banner-img style-2">
            <img src="/img/banner/banner-4.jpg" alt="Banner">
            <div class="banner-text">
                <h2 class="mb-50">Luxury Memory Foam Soft</h2>
                <!-- toDo -->
                <!-- <Link :href="route('shop.index')" class="btn btn-xs">
                    Shop Now <i class="fi-rs-arrow-small-right"></i>
                </Link> -->
            </div>
          </div>
        </div>

        <div class="col-lg-9 col-md-12">
          <div class="carausel-4-columns-cover arrow-center position-relative">
            <div class="carausel-4-columns">
              <DailyDealCard
                v-for="product in products"
                :key="product.id"
                :product="product"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
