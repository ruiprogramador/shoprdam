<script setup>
import { Link } from '@inertiajs/vue3'

const banners = [
    { id: 1, image: 'img/banner/banner-16.jpg', title: 'Aromatherapy Essential Oil <br> Diffuser – 7 LED Colors', link: null },
    { id: 2, image: 'img/banner/banner-17.jpg', title: 'Unisex Cotton Graphic <br> T-Shirt Vintage Style',        link: null },
    { id: 3, image: 'img/banner/banner-18.jpg', title: 'Luxury Cashmere Scarf <br> Ultra-Soft Essential',         link: null },
    { id: 4, image: 'img/banner/banner-19.jpg', title: '4K Ultra HD Action Camera <br> Waterproof, WiFi Enabled', link: null },
]
</script>

<template>
    <section class="four_column_banner mt-15 mb-30">
        <div class="container">
            <div class="row">
                <div v-for="(banner, index) in banners" :key="banner.id" class="col-lg-3 col-md-6">
                    <div class="banner-img style-6 wow animate__animated animate__fadeInUp">
                        <img :src="banner.image" :alt="banner.title" />
                        <div class="banner-text">
                            <h6 class="mb-10 mt-30" v-html="banner.title"></h6>
                            <Link v-if="banner.link" :href="banner.link">Go to supplier</Link>
                            <a v-else href="#">Go to supplier</a> <!-- toDo -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>