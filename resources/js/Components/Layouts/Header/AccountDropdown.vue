<script setup>

import { Link, router, usePage } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted, computed } from 'vue'
const page = usePage()
/* import { useRouter } from 'vue-router'
import { useToast } from 'vue-toastification' */

// const toast = useToast()

/* const isAuthenticated = ref(false)
onMounted(() => {
  isAuthenticated.value = store.getters['auth/isAuthenticated']
}) */

const isAuthenticated = computed(() => !!page.props.auth?.user)
/* const logout = async () => {
  try {
    await store.dispatch('auth/logout')
    // router.push('/login')
    router.visit('/login')
    toast.success('Logged out successfully')
  } catch (error) {
    console.error('Logout error:', error)
  }
} */
const logout = () => {
  router.post('/logout', {}, {
    onSuccess: () => {
      //toast.success('Logged out successfully')
      console.log('Logged out successfully')
    }
  })
}

/* import { mapGetters } from 'vuex'

export default {
  name: 'AccountDropdown',
  computed: {
    ...mapGetters('auth', ['isAuthenticated'])
  },
  methods: {
    async logout() {
      try {
        await this.$store.dispatch('auth/logout')
        this.$router.push('/login')
        this.$toast.success('Logged out successfully')
      } catch (error) {
        console.error('Logout error:', error)
      }
    }
  }
} */
</script>
<template>
  <div class="header-action-icon-2">
    <Link href="/account">
       <i class="fa fa-user"></i>
    </Link>
    <Link href="/account"><span class="lable ml-0">Account</span></Link>

    <div class="cart-dropdown-wrap cart-dropdown-hm2 account-dropdown">
      <ul v-if="isAuthenticated">
        <li>
            <Link href="/account">
                <i class="fa fa-user mr-10"></i>My Account
            </Link>
        </li>
        <li>
          <Link href="/order-tracking">
            <i class="fa fa-map-marker mr-10"></i>Order Tracking
          </Link>
        </li>
        <li>
          <Link href="/vouchers">
            <i class="fa fa-gift mr-10"></i>My Vouchers
          </Link>
        </li>
        <li>
          <Link href="/wishlist">
            <i class="fa fa-heart mr-10"></i>My Wishlist
          </Link>
        </li>
        <li>
          <Link href="/settings">
            <i class="fa fa-cog mr-10"></i>Settings
          </Link>
        </li>
        <li>
          <a @click.prevent="logout">
            <i class="fa fa-sign-out mr-10"></i>Logout
          </a>
        </li>
      </ul>

      <ul v-else>
        <li>
          <Link href="/login">
            <i class="fa fa-sign-in mr-10"></i>Login
          </Link>
        </li>
        <li>
          <Link href="/register">
            <i class="fa fa-user-plus mr-10"></i>Register
          </Link>
        </li>
      </ul>
    </div>
  </div>
</template>
