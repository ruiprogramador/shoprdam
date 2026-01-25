<script setup>
import { onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import CategorySidebar from './CategorySidebar.vue'

const props = defineProps({
  slides: {
    type: Array,
    default: () => []
  }
})

onMounted(() => {
  // Initialize Slick slider if using jQuery
  if (window.$ && window.$.fn.slick) {
    $('.hero-slider-1').slick({
      slidesToShow: 1,
      slidesToScroll: 1,
      arrows: true,
      fade: true,
      dots: true,
      autoplay: true,
      autoplaySpeed: 4000
    })
  }
})
</script>

<template>
  <section class="home-slider position-relative mb-30">
    <div class="container">
      <div class="row">
        <div class="col-lg-2 d-none d-xxl-flex">
          <CategorySidebar />
        </div>

        <div class="col-lg-8 col-xl-9 col-xxl-7">
          <div class="home-slide-cover mt-30">
            <div class="hero-slider-1 style-5 dot-style-1 dot-style-1-position-2">
              <div
                v-for="(slide, index) in slides"
                :key="index"
                class="single-hero-slider single-animation-wrap"
                :style="{ backgroundImage: `url(${slide.image})` }"
              >
                <div class="slider-content">
                  <h1 class="display-2 mb-15">{{ slide.title }}</h1>
                  <p>{{ slide.description }}</p>
                  <Link :href="slide.link" class="btn">
                    Shop Now <i class="fi-rs-arrow-small-right"></i>
                  </Link>
                </div>
              </div>
            </div>
            <div class="slider-arrow hero-slider-1-arrow"></div>
          </div>
        </div>

        <div class="col-lg-4 col-xl-3 col-xxl-3">
          <div class="row">
            <div class="col-12 col-md-6 col-lg-12">
              <div class="banner-img style-4 mt-30">
                <img src="img/banner/banner-14.jpg" alt="Banner" />
                <div class="banner-text">
                    <h4 class="mb-30">Hi-Res Audio Headphones</h4>
                    <!-- toDo -->
                    <!-- <Link :href="route('shop.index')" class="btn btn-xs mb-50">
                        Shop Now <i class="fi-rs-arrow-small-right"></i>
                    </Link> -->
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-lg-12">
              <div class="banner-img style-5 mt-5 mt-md-30">
                <img src="img/banner/banner-15.jpg" alt="Banner" />
                <div class="banner-text">
                  <h5 class="mb-20">Men's Leather <br> Waterproof Boots</h5>
                  <!-- toDo -->
                  <!-- <Link :href="route('shop.index')" class="btn btn-xs">
                    Shop Now <i class="fi-rs-arrow-small-right"></i>
                  </Link> -->
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
