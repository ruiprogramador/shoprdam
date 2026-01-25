<script setup>

import { ref } from 'vue'
const email = ref('')
const subscribe = async () => {
  try {
    await $axios.post('/api/newsletter/subscribe', {
      email: email.value
    })
    $toast.success('Successfully subscribed to newsletter!')
    email.value = ''
  } catch (error) {
    $toast.error('Subscription failed. Please try again.')
  }
}



/* export default {
  name: 'NewsletterSection',
  data() {
    return {
      email: ''
    }
  },
  methods: {
    async subscribe() {
      try {
        await this.$axios.post('/api/newsletter/subscribe', {
          email: this.email
        })
        this.$toast.success('Successfully subscribed to newsletter!')
        this.email = ''
      } catch (error) {
        this.$toast.error('Subscription failed. Please try again.')
      }
    }
  }
} */
</script>
<template>
  <section class="newsletter mb-15 wow animate__animated animate__fadeIn">
    <div class="container">
      <div class="row">
        <div class="col-lg-12">
          <div class="newsletter_bg" style="background:url(img/banner/banner-9.jpg);">
            <div class="position-relative newsletter-inner">
              <div class="newsletter-content">
                <h2 class="mb-20">Stay Stylish at Home Shop <br> the Latest Looks Online</h2>
                <p class="mb-45">
                  Start You'r Daily Shopping with <span class="text-brand">ShopRDAM</span>
                </p>
                <form class="form-subcriber d-flex" @submit.prevent="subscribe">
                  <input
                    v-model="email"
                    type="email"
                    placeholder="Your email address"
                    required
                  />
                  <button class="btn" type="submit">Subscribe</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
