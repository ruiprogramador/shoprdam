<script setup>
const features = [
    {
        id: 1,
        icon: 'img/sells/icon-1.png',
        title: 'Best prices & offers',
        description: 'Prices you will love if you sign up today'
    },
    {
        id: 2,
        icon: 'img/sells/icon-2.png',
        title: 'Free delivery',
        description: '24/7 amazing services'
    },
    {
        id: 3,
        icon: 'img/sells/icon-3.png',
        title: 'Great daily deal',
        description: 'Get your best deal today'
    },
    {
        id: 4,
        icon: 'img/sells/icon-4.png',
        title: 'Easy returns',
        description: 'Returns within 7 days'
    }
]
</script>

<template>
  <section class="featured section-padding mt-45">
    <div class="container">
      <div class="row">
        <div v-for="(feature, index) in features" :key="feature.id" class="col-xl-3 col-lg-4 col-12 col-sm-6">
          <div class="banner-left-icon d-flex align-items-center wow animate__animated animate__fadeInUp" :data-wow-delay="index * 0.1">
            <div class="banner-icon">
              <img :src="feature.icon" alt="" />
            </div>
            <div class="banner-text">
              <h3 class="icon-box-title">{{ feature.title }}</h3>
              <p>{{ feature.description }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
