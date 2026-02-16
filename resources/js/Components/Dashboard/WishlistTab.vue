<script setup lang="ts">
import { ref } from 'vue'

interface WishlistItem {
  id: string
  name: string
  price: string
  image: string
  inStock: boolean
}

const wishlistItems = ref<WishlistItem[]>([
  {
    id: '1',
    name: 'Organic Cage-Free Grade A Large Brown Eggs',
    price: '$28.85',
    image: '/assets/imgs/shop/product-1-1.jpg',
    inStock: true,
  },
  {
    id: '2',
    name: 'Seeds of Change Organic Quinoa',
    price: '$32.85',
    image: '/assets/imgs/shop/product-2-1.jpg',
    inStock: true,
  },
  {
    id: '3',
    name: 'Naturally Flavored Cinnamon Vanilla Light Roast Coffee',
    price: '$45.50',
    image: '/assets/imgs/shop/product-3-1.jpg',
    inStock: false,
  },
])

const addToCart = (productId: string) => {
  // In a real application, you would make an API call here
  console.log('Adding to cart:', productId)
  alert('Product added to cart!')
}

const removeFromWishlist = (productId: string) => {
  if (confirm('Are you sure you want to remove this item from your wishlist?')) {
    wishlistItems.value = wishlistItems.value.filter((item) => item.id !== productId)
  }
}
</script>

<style scoped>
.wishlist-table img {
  width: 80px;
  height: 80px;
  object-fit: cover;
}

.in-stock {
  color: #3bb77e;
}

.out-of-stock {
  color: #f74b81;
}
</style>
<template>
    <div class="card">
        <div class="card-header p-0">
            <h3 class="mb-0">My Wishlist</h3>
        </div>
        <div class="card-body p-0">
            <div v-if="wishlistItems.length > 0" class="table-responsive">
                <table class="table wishlist-table m-0 mt-20">
                    <thead>
                        <tr>
                            <th class="product-thumbnail">Image</th>
                            <th class="product-name">Product</th>
                            <th class="product-price">Price</th>
                            <th class="product-stock-status">Stock Status</th>
                            <th class="product-add-to-cart">Action</th>
                            <th class="product-remove">Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in wishlistItems" :key="item.id">
                            <td class="product-thumbnail">
                                <a :href="`/product/${item.id}`">
                                    <img :src="item.image" :alt="item.name" />
                                </a>
                            </td>
                            <td class="product-name">
                                <a :href="`/product/${item.id}`">{{ item.name }}</a>
                            </td>
                            <td class="product-price">
                                <span class="amount">{{ item.price }}</span>
                            </td>
                            <td class="product-stock-status">
                                <span :class="item.inStock ? 'in-stock' : 'out-of-stock'">
                                    {{ item.inStock ? 'In Stock' : 'Out of Stock' }}
                                </span>
                            </td>
                            <td class="product-add-to-cart">
                                <button
                                    class="btn btn-sm"
                                    :disabled="!item.inStock"
                                    @click="addToCart(item.id)"
                                >
                                    Add to Cart
                                </button>
                            </td>
                            <td class="product-remove">
                                <a href="#" @click.prevent="removeFromWishlist(item.id)">
                                    <i class="fi-rs-trash"></i>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-else class="text-center py-5">
                <p>Your wishlist is empty</p>
                <a href="/shop" class="btn btn-primary">Continue Shopping</a>
            </div>
        </div>
    </div>
</template>