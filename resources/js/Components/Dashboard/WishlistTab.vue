<script setup lang="ts">
import { ref } from 'vue'
import { useCartStore }  from '@/Stores/cartStore'
import { useToast }      from 'vue-toastification'

interface WishlistItem { id: string; name: string; price: string; image: string; inStock: boolean }

const cartStore    = useCartStore()
const toast        = useToast()
const removingId   = ref<string | null>(null)

// toDo: substituir por `defineProps<{ items: WishlistItem[] }>()` quando vier do backend
const wishlistItems = ref<WishlistItem[]>([
    { id: '1', name: 'Organic Cage-Free Grade A Large Brown Eggs',          price: '$28.85', image: '/img/product-1.png', inStock: true  },
    { id: '2', name: 'Seeds of Change Organic Quinoa',                      price: '$32.85', image: '/img/product-2.png', inStock: true  },
    { id: '3', name: 'Naturally Flavored Cinnamon Vanilla Light Roast Coffee', price: '$45.50', image: '/img/product-3.png', inStock: false },
])

const addToCart = (item: WishlistItem) => {
    if (!item.inStock) return
    cartStore.addItem(item)
    toast.success('Product added to cart')
}

const confirmRemove = (id: string) => { removingId.value = id }
const cancelRemove  = ()           => { removingId.value = null }

const removeFromWishlist = (id: string) => {
    wishlistItems.value = wishlistItems.value.filter(i => i.id !== id)
    removingId.value = null
    toast.success('Item removed from wishlist')
}
</script>

<style scoped>
.wishlist-table img { width: 80px; height: 80px; object-fit: cover; }
.in-stock           { color: #3bb77e; }
.out-of-stock       { color: #f74b81; }
</style>

<template>
    <div class="card">
        <div class="card-header p-0"><h3 class="mb-0">My Wishlist</h3></div>
        <div class="card-body p-0">
            <div v-if="wishlistItems.length" class="table-responsive">
                <table class="table wishlist-table m-0 mt-20">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Stock Status</th>
                            <th>Action</th>
                            <th>Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in wishlistItems" :key="item.id">
                            <td><a :href="`/product/${item.id}`"><img :src="item.image" :alt="item.name" /></a></td>
                            <td><a :href="`/product/${item.id}`">{{ item.name }}</a></td>
                            <td><span class="amount">{{ item.price }}</span></td>
                            <td><span :class="item.inStock ? 'in-stock' : 'out-of-stock'">{{ item.inStock ? 'In Stock' : 'Out of Stock' }}</span></td>
                            <td>
                                <button class="btn btn-sm" :disabled="!item.inStock" type="button" @click="addToCart(item)">
                                    Add to Cart
                                </button>
                            </td>
                            <td>
                                <a href="#" @click.prevent="confirmRemove(item.id)"><i class="fa fa-trash"></i></a>
                                <!-- Confirmação inline -->
                                <div v-if="removingId === item.id" class="mt-1 text-sm">
                                    <span class="text-danger">Remove? </span>
                                    <a href="#" class="text-danger mr-1" @click.prevent="removeFromWishlist(item.id)">Yes</a>
                                    <a href="#" @click.prevent="cancelRemove">No</a>
                                </div>
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
