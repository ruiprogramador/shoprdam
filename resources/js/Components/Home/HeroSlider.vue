<script setup>
import { Link }           from '@inertiajs/vue3'
import CategorySidebar    from './CategorySidebar.vue'

const props = defineProps({
    slides: { type: Array, default: () => [] }
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
                        <!-- Scroll snap nativo — sem jQuery -->
                        <div class="overflow-hidden rounded-lg">
                            <div class="flex snap-x snap-mandatory overflow-x-auto" style="scroll-behavior: smooth;">
                                <div
                                    v-for="(slide, index) in slides"
                                    :key="index"
                                    class="snap-center shrink-0 w-full single-hero-slider single-animation-wrap relative"
                                    :style="{ backgroundImage: `url(${slide.image})` }"
                                >
                                    <div class="slider-content">
                                        <h1 class="display-2 mb-15">{{ slide.title }}</h1>
                                        <p>{{ slide.description }}</p>
                                        <Link :href="slide.link" class="btn">
                                            Shop Now <i class="fa fa-arrow-right"></i>
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-xl-3 col-xxl-3">
                    <div class="row">
                        <div class="col-12 col-md-6 col-lg-12">
                            <div class="banner-img style-4 mt-30">
                                <img src="img/banner/banner-14.jpg" alt="Banner" />
                                <div class="banner-text">
                                    <h4 class="mb-30">Hi-Res Audio Headphones</h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-12">
                            <div class="banner-img style-5 mt-5 mt-md-30">
                                <img src="img/banner/banner-15.jpg" alt="Banner" />
                                <div class="banner-text">
                                    <h5 class="mb-20">Men's Leather <br> Waterproof Boots</h5>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>