<script setup>
import { ref } from 'vue'
import DailyDealCard from './DailyDealCard.vue'

const props = defineProps({
    products: { type: Array, default: () => [] }
})

const activeTab = ref('featured')
const tabs = [
    { id: 'featured', name: 'Featured' },
    { id: 'popular',  name: 'Popular'  },
    { id: 'new',      name: 'New added'},
]

const filteredProducts = (tabId) => {
    if (tabId === 'featured') return props.products.filter(p => p.is_featured)
    if (tabId === 'popular')  return props.products.filter(p => p.is_popular)
    if (tabId === 'new')      return props.products.filter(p => p.is_new)
    return props.products
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
                            type="button"
                            @click="activeTab = tab.id"
                        >{{ tab.name }}</button>
                    </li>
                </ul>
            </div>

            <div class="row">
                <div class="col-lg-3 d-none d-lg-flex">
                    <div class="banner-img style-2">
                        <img src="/img/banner/banner-4.jpg" alt="Banner" />
                    </div>
                </div>

                <div class="col-lg-9 col-md-12">
                    <!-- Scroll nativo com snap — sem jQuery -->
                    <div class="overflow-x-auto">
                        <div class="flex gap-4 snap-x snap-mandatory pb-2"
                             style="scroll-behavior: smooth;">
                            <div
                                v-for="product in filteredProducts(activeTab)"
                                :key="product.id"
                                class="snap-start shrink-0 w-56"
                            >
                                <DailyDealCard :product="product" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>