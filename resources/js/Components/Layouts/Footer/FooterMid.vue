<script setup>
import { Link } from '@inertiajs/vue3'

const footerColumns = [];

// toDo
/* const footerColumns = [
    {
        id: 1,
        title: 'Company',
        links: [
            { id: 1, name: 'About Us', route: 'about' },
            { id: 2, name: 'Contact Us', route: 'contact' },
            { id: 3, name: 'Privacy Policy', route: 'privacy' },
            { id: 4, name: 'Terms & Conditions', route: 'terms' },
            { id: 5, name: 'Contact Us', route: 'contact' },
            { id: 6, name: 'Support Center', route: 'support' },
            { id: 7, name: 'Careers', route: 'careers' }
        ]
    },
    {
        id: 2,
        title: 'Account',
        links: [
            { id: 1, name: 'Sign In', route: 'login' },
            { id: 2, name: 'View Cart', route: 'cart.index' },
            { id: 3, name: 'My Wishlist', route: 'wishlist.index' },
            { id: 4, name: 'Track My Order', route: 'order.tracking' },
            { id: 5, name: 'Help Ticket', route: 'help' },
            { id: 6, name: 'Shipping Details', route: 'shipping' },
            { id: 7, name: 'Compare products', route: 'compare' }
        ]
    },
    {
        id: 3,
        title: 'Corporate',
        links: [
            { id: 1, name: 'Become a Vendor', route: 'vendor.register' },
            { id: 2, name: 'Affiliate Program', route: 'affiliate' },
            { id: 3, name: 'Farm Business', route: 'farm-business' },
            { id: 4, name: 'Farm Careers', route: 'farm-careers' },
            { id: 5, name: 'Our Suppliers', route: 'suppliers' },
            { id: 6, name: 'Accessibility', route: 'accessibility' },
            { id: 7, name: 'Promotions', route: 'promotions' }
        ]
    },
    {
        id: 4,
        title: 'Popular',
        links: [
            { id: 1, name: 'Milk & Flavoured Milk', route: 'category.milk' },
            { id: 2, name: 'Butter and Margarine', route: 'category.butter' },
            { id: 3, name: 'Eggs Substitutes', route: 'category.eggs' },
            { id: 4, name: 'Marmalades', route: 'category.marmalades' },
            { id: 5, name: 'Sour Cream and Dips', route: 'category.cream' },
            { id: 6, name: 'Tea & Kombucha', route: 'category.tea' },
            { id: 7, name: 'Cheese', route: 'category.cheese' }
        ]
    }
] */
</script>

<template>
  <section class="footer-mid pt-70 pb-65 mt-45">
    <div class="container">
      <div class="row justify-content-between">
        <div class="col-xl-3">
          <div class="widget-about font-md mb-md-3 mb-lg-3 mb-xl-0">
            <div class="logo mb-30">
              <Link :href="route('home')" class="mb-15">
                <img src="/assets/imgs/theme/logo.svg" alt="logo" />
              </Link>
              <p class="font-lg text-heading">Awesome eCommerce store website</p>
            </div>
          </div>
        </div>

        <div v-for="column in footerColumns" :key="column.id" class="col-xl-2 col-sm-6 col-lg-3">
          <div class="footer-link-widget">
            <h4 class="widget-title">{{ column.title }}</h4>
            <ul class="footer-list mb-sm-5 mb-md-0">
              <li v-for="link in column.links" :key="link.id">
                <!-- toDo -->
                <!-- <Link :href="route(link.route)">{{ link.name }}</Link> -->
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
