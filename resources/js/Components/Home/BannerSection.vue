<script setup>
import { Link } from '@inertiajs/vue3'

const banners = [
    { id: 1, image: 'img/banner/banner-1.jpg', title: 'Everyday Fresh & Clean with Our Products' },
    { id: 2, image: 'img/banner/banner-2.jpg', title: 'Make your Breakfast Healthy and Easy' },
    { id: 3, image: 'img/banner/banner-3.jpg', title: 'The best Organic Products Online' },
]
</script>

<template>
    <section class="banners mt-25">
        <div class="container">
            <div class="row">
                <div v-for="(banner, index) in banners" :key="banner.id" class="col-lg-4 col-md-6">
                    <div class="banner-img wow animate__animated animate__fadeInUp" :data-wow-delay="index * 0.2">
                        <img :src="banner.image" :alt="banner.title" />
                        <div class="banner-text">
                            <h4>{{ banner.title }}</h4>
                            <!-- toDo: <Link :href="route('shop.index')" class="btn btn-xs">Shop Now</Link> -->
                            <a href="#" class="btn btn-xs">Shop Now <i class="fa fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>