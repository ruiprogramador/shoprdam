<script setup>
import { onMounted, nextTick } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
  categories: {
    type: Array,
    default: () => []
  }
})

onMounted(async () => {
  await nextTick()
  initCarousel()
})

const initCarousel = () => {
  if (window.$ && window.$.fn.slick) {
    $('.carausel-10-columns').slick({
      slidesToShow: 10,
      slidesToScroll: 1,
      arrows: true,
      dots: false,
      responsive: [
        { breakpoint: 1400, settings: { slidesToShow: 8 } },
        { breakpoint: 1200, settings: { slidesToShow: 6 } },
        { breakpoint: 992, settings: { slidesToShow: 4 } },
        { breakpoint: 768, settings: { slidesToShow: 3 } },
        { breakpoint: 576, settings: { slidesToShow: 2 } }
      ]
    })
  }
}
</script>

<template>
  <section class="popular-categories section-padding">
    <div class="container wow animate__animated animate__fadeIn">
      <div class="section-title">
        <div class="title">
          <h3>Top Categories</h3>
        </div>
        <div class="slider-arrow slider-arrow-2 flex-right carausel-10-columns-arrow"></div>
      </div>

      <div class="carausel-10-columns-cover position-relative">
        <div class="carausel-10-columns">
          <div
            v-for="(category, index) in categories"
            :key="category.id"
            class="card-2"
            :class="`bg-${9 + (index % 7)}`"
          >
            <figure class="img-hover-scale overflow-hidden">
              <Link :href="route('category.show', category.slug)">
                <img :src="category.image" :alt="category.name" />
              </Link>
            </figure>
            <h6>
              <Link :href="route('category.show', category.slug)">{{ category.name }}</Link>
            </h6>
            <span>{{ category.products_count }} items</span>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
