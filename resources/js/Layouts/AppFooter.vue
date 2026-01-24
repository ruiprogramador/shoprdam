<script setup>

import NewsletterSection from '@/Components/Layouts/Footer/NewsletterSection.vue'
import FeaturedSection from '@/Components/Layouts/Footer/FeaturedSection.vue'
import FooterMid from '@/Components/Layouts/Footer/FooterMid.vue'
import FooterBottom from '@/Components/Layouts/Footer/FooterBottom.vue'

/* import NewsletterSection from './Footer/NewsletterSection.vue'
import FeaturedSection from './Footer/FeaturedSection.vue'
import FooterMid from './Footer/FooterMid.vue'
import FooterBottom from './Footer/FooterBottom.vue'

export default {
  name: 'AppFooter',
  components: {
    NewsletterSection,
    FeaturedSection,
    FooterMid,
    FooterBottom
  }
} */
</script>
<template>
  <footer class="main pt-10">
    <NewsletterSection />
    <FeaturedSection />
    <FooterMid />
    <FooterBottom />
  </footer>
</template>
