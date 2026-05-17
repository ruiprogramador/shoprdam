<script setup>
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    categories: { type: Array, default: () => [] }
})
</script>

<template>
    <section class="popular-categories section-padding">
        <div class="container wow animate__animated animate__fadeIn">
            <div class="section-title">
                <div class="title"><h3>Top Categories</h3></div>
            </div>

            <div class="overflow-x-auto">
                <div class="flex gap-4 snap-x snap-mandatory pb-2" style="scroll-behavior: smooth;">
                    <div
                        v-for="(category, index) in categories"
                        :key="category.id"
                        class="snap-start shrink-0 card-2"
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