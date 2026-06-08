<script setup>
import { ref, watch } from 'vue'
import { Link }          from '@inertiajs/vue3'
import { useCartStore }  from '@/Stores/cartStore'
import { useToast }      from 'vue-toastification'

const props = defineProps({
    product: { type: Object, default: null }
})
const emit = defineEmits(['close'])

const cartStore = useCartStore()
const toast     = useToast()
const quantity  = ref(1)

// Reset qty quando o produto muda
watch(() => props.product, () => { quantity.value = 1 })

const discountPercentage = () => {
    if (!props.product?.old_price) return 0
    return Math.round(((props.product.old_price - props.product.price) / props.product.old_price) * 100)
}

const increaseQty = () => quantity.value++
const decreaseQty = () => { if (quantity.value > 1) quantity.value-- }

const addToCart = () => {
    cartStore.addItem({ ...props.product, quantity: quantity.value })
    toast.success('Product added to cart')
    emit('close')
}
</script>

<template>
    <div
        v-if="product"
        class="modal fade custom-modal show"
        style="display:block"
        @click.self="emit('close')"
        role="dialog"
        aria-modal="true"
    >
        <div class="modal-dialog">
            <div class="modal-content">
                <button type="button" class="btn-close" @click="emit('close')" aria-label="Close"></button>

                <div class="modal-body">
                    <div class="row">
                        <!-- Imagens -->
                        <div class="col-md-6 col-sm-12 mb-md-0 mb-sm-5">
                            <div class="detail-gallery">
                                <div class="product-image-slider">
                                    <figure v-for="(image, index) in product.images" :key="index" class="border-radius-10">
                                        <img :src="image" alt="product image" />
                                    </figure>
                                </div>
                                <div class="slider-nav-thumbnails">
                                    <div v-for="(thumb, index) in product.thumbnails" :key="index">
                                        <img :src="thumb" alt="product thumbnail" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Info -->
                        <div class="col-md-6 col-sm-12">
                            <div class="detail-info pr-30 pl-30">
                                <span v-if="product.badge" class="stock-status out-stock">{{ product.badge }}</span>

                                <h3 class="title-detail">
                                    <Link :href="route('product.show', product.slug)" class="text-heading">
                                        {{ product.name }}
                                    </Link>
                                </h3>

                                <div class="product-detail-rating">
                                    <div class="product-rate d-inline-block">
                                        <div class="product-rating" :style="{ width: product.rating + '%' }"></div>
                                    </div>
                                    <span class="font-small ml-5 text-muted">({{ product.review_count }} reviews)</span>
                                </div>

                                <div class="clearfix product-price-cover">
                                    <div class="product-price primary-color float-left">
                                        <span class="current-price text-brand">${{ product.price }}</span>
                                        <span v-if="product.old_price">
                                            <span class="save-price font-md color3 ml-15">{{ discountPercentage() }}% Off</span>
                                            <span class="old-price font-md ml-15">${{ product.old_price }}</span>
                                        </span>
                                    </div>
                                </div>

                                <div class="detail-extralink mb-30">
                                    <div class="detail-qty border radius">
                                        <a href="#" @click.prevent="decreaseQty" aria-label="Diminuir quantidade">
                                            <i class="fa fa-angle-down"></i>
                                        </a>
                                        <span class="qty-val">{{ quantity }}</span>
                                        <a href="#" @click.prevent="increaseQty" aria-label="Aumentar quantidade">
                                            <i class="fa fa-angle-up"></i>
                                        </a>
                                    </div>
                                    <div class="product-extra-link2">
                                        <button type="button" class="button button-add-to-cart" @click="addToCart">
                                            <i class="fa fa-shopping-cart"></i>Add to cart
                                        </button>
                                    </div>
                                </div>

                                <div class="font-xs">
                                    <ul>
                                        <li class="mb-5">Vendor: <span class="text-brand">{{ product.vendor_name }}</span></li>
                                        <li class="mb-5">MFG: <span class="text-brand">{{ product.mfg_date }}</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>