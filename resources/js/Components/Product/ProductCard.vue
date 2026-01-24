<script setup>
import { Link } from '@inertiajs/vue3'
import { useCartStore } from '@/Stores/cartStore'
import { useWishlistStore } from '@/Stores/wishlistStore'
import { useCompareStore } from '@/Stores/compareStore'
import { useToast } from 'vue-toastification'

const props = defineProps({
  product: {
    type: Object,
    required: true
  },
  wrapClass: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['quick-view'])

const cartStore = useCartStore()
const wishlistStore = useWishlistStore()
const compareStore = useCompareStore()
const toast = useToast()

const addToCart = () => {
  cartStore.addItem(props.product)
  toast.success('Product added to cart')
}

const addToWishlist = () => {
  wishlistStore.addItem(props.product)
  toast.success('Product added to wishlist')
}

const addToCompare = () => {
  try {
    compareStore.addItem(props.product)
    toast.success('Product added to compare')
  } catch (error) {
    toast.error(error.message)
  }
}
</script>

<template>
  <div class="product-cart-wrap" :class="wrapClass">
    <div class="product-img-action-wrap">
      <div class="product-img product-img-zoom">
        <Link :href="route('product.show', product.slug)">
          <img class="default-img" :src="product.image" :alt="product.name" />
          <img class="hover-img" :src="product.hover_image" :alt="product.name" />
        </Link>
      </div>

      <div class="product-action-1">
        <a
          aria-label="Add To Wishlist"
          class="action-btn"
          @click.prevent="addToWishlist"
        >
          <i class="fi-rs-heart"></i>
        </a>
        <a
          aria-label="Compare"
          class="action-btn"
          @click.prevent="addToCompare"
        >
          <i class="fi-rs-shuffle"></i>
        </a>
        <a
          aria-label="Quick view"
          class="action-btn"
          @click.prevent="$emit('quick-view', product)"
        >
          <i class="fi-rs-eye"></i>
        </a>
      </div>

      <div
        v-if="product.badge"
        class="product-badges product-badges-position product-badges-mrg"
      >
        <span :class="product.badge_class">{{ product.badge }}</span>
      </div>
    </div>

    <div class="product-content-wrap">
      <div class="product-category">
        <Link :href="route('category.show', product.category.slug)">
          {{ product.category.name }}
        </Link>
      </div>

      <h2>
        <Link :href="route('product.show', product.slug)">
          {{ product.name }}
        </Link>
      </h2>

      <div class="product-rate-cover">
        <div class="product-rate d-inline-block">
          <div class="product-rating" :style="{ width: product.rating + '%' }"></div>
        </div>
        <span class="font-small ml-5 text-muted">({{ product.review_count }})</span>
      </div>

      <div v-if="product.vendor">
        <span class="font-small text-muted">
          By <Link :href="route('vendor.show', product.vendor.slug)">{{ product.vendor.name }}</Link>
        </span>
      </div>

      <div class="product-card-bottom">
        <div class="product-price">
          <span>${{ product.price }}</span>
          <span v-if="product.old_price" class="old-price">${{ product.old_price }}</span>
        </div>
        <div class="add-cart">
          <a class="add" @click.prevent="addToCart">
            <i class="fi-rs-shopping-cart mr-5"></i>Add
          </a>
        </div>
      </div>
    </div>
  </div>
</template>
