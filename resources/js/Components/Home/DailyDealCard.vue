<script setup>
import { computed } from 'vue'
import { Link }           from '@inertiajs/vue3'
import { useCartStore }   from '@/Stores/cartStore'
import { useWishlistStore } from '@/Stores/wishlistStore'
import { useCompareStore } from '@/Stores/compareStore'
import { useToast }       from 'vue-toastification'

const props  = defineProps({ product: { type: Object, required: true } })
const emit   = defineEmits(['quick-view'])

const cartStore     = useCartStore()
const wishlistStore = useWishlistStore()
const compareStore  = useCompareStore()
const toast         = useToast()

const soldPercentage = computed(() => (props.product.sold / props.product.stock) * 100)

const addToCart     = () => { cartStore.addItem(props.product);     toast.success('Product added to cart') }
const addToWishlist = () => { wishlistStore.addItem(props.product); toast.success('Product added to wishlist') }
const addToCompare  = () => {
    try   { compareStore.addItem(props.product); toast.success('Product added to compare') }
    catch (e) { toast.error(e.message) }
}
</script>

<template>
    <div class="product-cart-wrap">
        <div class="product-img-action-wrap">
            <div class="product-img product-img-zoom">
                <Link :href="route('product.show', product.slug)">
                    <img class="default-img" :src="product.image"       :alt="product.name" />
                    <img class="hover-img"   :src="product.hover_image" :alt="product.name" />
                </Link>
            </div>

            <div class="product-action-1">
                <a aria-label="Quick view"       class="action-btn small hover-up" href="#" @click.prevent="emit('quick-view', product)"><i class="fa fa-eye"></i></a>
                <a aria-label="Add To Wishlist"  class="action-btn small hover-up" href="#" @click.prevent="addToWishlist"><i class="fa fa-heart"></i></a>
                <a aria-label="Compare"          class="action-btn small hover-up" href="#" @click.prevent="addToCompare"><i class="fa fa-exchange-alt"></i></a>
            </div>

            <div v-if="product.discount_percentage" class="product-badges product-badges-position product-badges-mrg">
                <span :class="product.badge_class">Save {{ product.discount_percentage }}%</span>
            </div>
        </div>

        <div class="product-content-wrap">
            <div class="product-category">
                <Link :href="route('category.show', product.category.slug)">{{ product.category.name }}</Link>
            </div>
            <h2>
                <Link :href="route('product.show', product.slug)">{{ product.name }}</Link>
            </h2>
            <div class="product-rate d-inline-block">
                <div class="product-rating" :style="{ width: product.rating + '%' }"></div>
            </div>
            <div class="product-price mt-10">
                <span>${{ product.price }}</span>
                <span v-if="product.old_price" class="old-price">${{ product.old_price }}</span>
            </div>
            <div class="sold mt-15 mb-15">
                <div class="progress mb-5">
                    <div class="progress-bar" role="progressbar" :style="{ width: soldPercentage + '%' }" :aria-valuenow="soldPercentage" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <span class="font-xs text-heading">Sold: {{ product.sold }}/{{ product.stock }}</span>
            </div>
            <a href="#" class="btn w-100 hover-up" @click.prevent="addToCart">
                <i class="fa fa-shopping-cart mr-5"></i>Add To Cart
            </a>
        </div>
    </div>
</template>