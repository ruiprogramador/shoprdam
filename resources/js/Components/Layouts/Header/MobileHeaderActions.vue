<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const page          = usePage()
const wishlistCount = computed(() => page.props.wishlist?.totalItems || 0)
const cartItems     = computed(() => page.props.cart?.items || [])
const cartCount     = computed(() => page.props.cart?.totalItems || 0)
const cartTotal     = computed(() => page.props.cart?.totalPrice || 0)

const removeItem = (itemId) => {
    router.post('/cart/remove', { item_id: itemId }, { preserveScroll: true })
}
</script>

<template>
    <div class="header-action-right d-block d-xl-none">
        <div class="header-action-2">
            <!-- Wishlist -->
            <div class="header-action-icon-2">
                <Link href="/wishlist">
                    <i class="fa fa-heart"></i>
                    <span class="pro-count white">{{ wishlistCount }}</span>
                </Link>
            </div>

            <!-- Cart -->
            <div class="header-action-icon-2">
                <Link class="mini-cart-icon" href="/cart">
                    <i class="fa fa-shopping-cart"></i>
                    <span class="pro-count white">{{ cartCount }}</span>
                </Link>

                <div class="cart-dropdown-wrap cart-dropdown-hm2">
                    <ul v-if="cartItems.length">
                        <li v-for="item in cartItems.slice(0, 2)" :key="item.id">
                            <div class="shopping-cart-img">
                                <Link :href="`/product/${item.slug}`">
                                    <img :alt="item.name" :src="item.thumbnail" />
                                </Link>
                            </div>
                            <div class="shopping-cart-title">
                                <h4><Link :href="`/product/${item.slug}`">{{ item.name }}</Link></h4>
                                <h3><span>{{ item.quantity }} × </span>${{ item.price }}</h3>
                            </div>
                            <div class="shopping-cart-delete">
                                <a href="#" @click.prevent="removeItem(item.id)">
                                    <i class="fa fa-times"></i>
                                </a>
                            </div>
                        </li>
                    </ul>

                    <div v-if="cartItems.length" class="shopping-cart-footer">
                        <div class="shopping-cart-total">
                            <h4>Total <span>${{ cartTotal.toFixed(2) }}</span></h4>
                        </div>
                        <div class="shopping-cart-button">
                            <Link class="outline" href="/cart">View cart</Link>
                            <Link href="/checkout">Checkout</Link>
                        </div>
                    </div>
                    <div v-else class="text-center p-4">
                        <p>Your cart is currently empty.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>