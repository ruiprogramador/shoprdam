<script setup>

import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted, computed } from 'vue'

const page = usePage()
const wishlistCount = computed(() => page.props.wishlist?.totalItems || 0)

/* import { mapGetters } from 'vuex'

export default {
  name: 'WishlistIcon',
  computed: {
    ...mapGetters('wishlist', ['wishlistCount'])
  }
} */
</script>
<template>
  <div class="header-action-icon-2">
    <Link href="/wishlist">
      <i class="far fa-heart"></i>
      <span class="pro-count blue">{{ wishlistCount }}</span>
    </Link>
    <Link href="/wishlist"><span class="lable">Wishlist</span></Link>
  </div>
</template>
