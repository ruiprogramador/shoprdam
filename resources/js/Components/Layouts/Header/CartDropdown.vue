<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page      = usePage()
const cartCount = computed(() => page.props.cart?.totalItems || 0)
const cartTotal = computed(() => page.props.cart?.totalPrice || 0.00)
const cartItems = computed(() => page.props.cart?.items || [])

const removeItem = (productId) => {
    router.post('/cart/remove', { product_id: productId }, { preserveScroll: true })
}
</script>

<template>
    <div class="header-action-icon-2">
        <Link class="mini-cart-icon" href="/cart">
            <i class="fa fa-shopping-cart"></i>
            <span class="pro-count blue">{{ cartCount }}</span>
        </Link>
        <Link href="/cart"><span class="lable">Cart</span></Link>

        <div class="cart-dropdown-wrap cart-dropdown-hm2">
            <ul v-if="cartItems.length">
                <li v-for="item in cartItems" :key="item.id">
                    <div class="shopping-cart-img">
                        <Link :href="`/product/${item.slug}`">
                            <img :alt="item.name" :src="item.thumbnail" />
                        </Link>
                    </div>
                    <div class="shopping-cart-title">
                        <h4><Link :href="`/product/${item.slug}`">{{ item.name }}</Link></h4>
                        <h4><span>{{ item.quantity }} × </span>${{ item.price }}</h4>
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
                    <Link href="/cart" class="outline">View cart</Link>
                    <Link href="/checkout">Checkout</Link>
                </div>
            </div>
            <div v-else class="shopping-cart-footer">
                <p class="text-center py-3">Your cart is empty</p>
            </div>
        </div>
    </div>
</template>