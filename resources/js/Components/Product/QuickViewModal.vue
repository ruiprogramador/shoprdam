<script>
export default {
  name: 'QuickViewModal',
  props: {
    product: {
      type: Object,
      default: null
    }
  },
  data() {
    return {
      quantity: 1
    }
  },
  computed: {
    discountPercentage() {
      if (!this.product.old_price) return 0
      return Math.round(
        ((this.product.old_price - this.product.price) / this.product.old_price) * 100
      )
    }
  },
  methods: {
    increaseQty() {
      this.quantity++
    },
    decreaseQty() {
      if (this.quantity > 1) {
        this.quantity--
      }
    },
    addToCart() {
      this.$store.dispatch('cart/addItem', {
        ...this.product,
        quantity: this.quantity
      })
      this.$toast.success('Product added to cart')
      this.$emit('close')
    }
  },
  watch: {
    product() {
      this.quantity = 1
    }
  }
}
</script>
<template>
  <div
    v-if="product"
    class="modal fade custom-modal show"
    id="quickViewModal"
    tabindex="-1"
    style="display: block"
    @click.self="$emit('close')"
  >
    <div class="modal-dialog">
      <div class="modal-content">
        <button
          type="button"
          class="btn-close"
          @click="$emit('close')"
          aria-label="Close"
        ></button>

        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 col-sm-12 col-xs-12 mb-md-0 mb-sm-5">
              <div class="detail-gallery">
                <span class="zoom-icon"><i class="fi-rs-search"></i></span>

                <div class="product-image-slider">
                  <figure
                    v-for="(image, index) in product.images"
                    :key="index"
                    class="border-radius-10"
                  >
                    <img :src="image" alt="product image" />
                  </figure>
                </div>

                <div class="slider-nav-thumbnails">
                  <div
                    v-for="(image, index) in product.thumbnails"
                    :key="index"
                  >
                    <img :src="image" alt="product image" />
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6 col-sm-12 col-xs-12">
              <div class="detail-info pr-30 pl-30">
                <span v-if="product.badge" class="stock-status out-stock">
                  {{ product.badge }}
                </span>

                <h3 class="title-detail">
                  <router-link :to="`/product/${product.slug}`" class="text-heading">
                    {{ product.name }}
                  </router-link>
                </h3>

                <div class="product-detail-rating">
                  <div class="product-rate-cover text-end">
                    <div class="product-rate d-inline-block">
                      <div class="product-rating" :style="{ width: product.rating + '%' }"></div>
                    </div>
                    <span class="font-small ml-5 text-muted">
                      ({{ product.review_count }} reviews)
                    </span>
                  </div>
                </div>

                <div class="clearfix product-price-cover">
                  <div class="product-price primary-color float-left">
                    <span class="current-price text-brand">${{ product.price }}</span>
                    <span v-if="product.old_price">
                      <span class="save-price font-md color3 ml-15">
                        {{ discountPercentage }}% Off
                      </span>
                      <span class="old-price font-md ml-15">${{ product.old_price }}</span>
                    </span>
                  </div>
                </div>

                <div class="detail-extralink mb-30">
                  <div class="detail-qty border radius">
                    <a @click.prevent="decreaseQty" class="qty-down">
                      <i class="fi-rs-angle-small-down"></i>
                    </a>
                    <span class="qty-val">{{ quantity }}</span>
                    <a @click.prevent="increaseQty" class="qty-up">
                      <i class="fi-rs-angle-small-up"></i>
                    </a>
                  </div>

                  <div class="product-extra-link2">
                    <button
                      type="button"
                      class="button button-add-to-cart"
                      @click="addToCart"
                    >
                      <i class="fi-rs-shopping-cart"></i>Add to cart
                    </button>
                  </div>
                </div>

                <div class="font-xs">
                  <ul>
                    <li class="mb-5">
                      Vendor: <span class="text-brand">{{ product.vendor_name }}</span>
                    </li>
                    <li class="mb-5">
                      MFG: <span class="text-brand">{{ product.mfg_date }}</span>
                    </li>
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
