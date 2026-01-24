<script setup>
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import HeroSlider from '@/Components/Home/HeroSlider.vue'
import TopCategories from '@/Components/Home/TopCategories.vue'
import BannerSection from '@/Components/Home/BannerSection.vue'
import PopularProducts from '@/Components/Product/PopularProducts.vue'
import FourColumnBanner from '@/Components/Home/FourColumnBanner.vue'
import DailyBestSells from '@/Components/Home/DailyBestSells.vue'
import NewArrival from '@/Components/Home/NewArrival.vue'
import CtaSection from '@/Components/Home/CtaSection.vue'
import SpecialProducts from '@/Components/Product/SpecialProducts.vue'
import ProductColumns from '@/Components/Product/ProductColumns.vue'

defineProps({
  slides: Array,
  categories: Array,
  popularProducts: Array,
  dailyDeals: Array,
  newArrivals: Array,
  specialProducts: Array,
  productColumns: Array
})
</script>

<template>
  <AppLayout>
    <Head title="Home - ShopRDAM" />

    <main class="main">
      <HeroSlider :slides="slides" />
      <TopCategories :categories="categories" />
      <BannerSection />
      <PopularProducts :products="popularProducts" />
      <FourColumnBanner />
      <DailyBestSells :products="dailyDeals" />
      <NewArrival :products="newArrivals" />
      <CtaSection />
      <SpecialProducts :products="specialProducts" />
      <ProductColumns :columns="productColumns" />
    </main>
  </AppLayout>
</template>
