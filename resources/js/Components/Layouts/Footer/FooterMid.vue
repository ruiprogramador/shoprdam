<script setup>
import { Link } from '@inertiajs/vue3'

const footerColumns = [
    {
        id: 1, title: 'Company',
        links: [
            { id: 1, name: 'About Us',          route: null },  // toDo: route('about')
            { id: 2, name: 'Contact Us',         route: null },  // toDo: route('contact')
            { id: 3, name: 'Privacy Policy',     route: null },
            { id: 4, name: 'Terms & Conditions', route: null },
            { id: 5, name: 'Support Center',     route: null },
            { id: 6, name: 'Careers',            route: null },
        ]
    },
    {
        id: 2, title: 'Account',
        links: [
            { id: 1, name: 'Sign In',         route: 'login' },
            { id: 2, name: 'View Cart',        route: null },
            { id: 3, name: 'My Wishlist',      route: null },
            { id: 4, name: 'Track My Order',   route: null },
            { id: 5, name: 'Help Ticket',      route: null },
            { id: 6, name: 'Shipping Details', route: null },
            { id: 7, name: 'Compare products', route: null },
        ]
    },
    {
        id: 3, title: 'Corporate',
        links: [
            { id: 1, name: 'Become a Vendor', route: null },
            { id: 2, name: 'Affiliate Program', route: null },
            { id: 3, name: 'Promotions',        route: null },
        ]
    },
    {
        id: 4, title: 'Popular',
        links: [
            { id: 1, name: 'Milk & Flavoured Milk', route: null },
            { id: 2, name: 'Butter and Margarine',  route: null },
            { id: 3, name: 'Eggs Substitutes',      route: null },
            { id: 4, name: 'Cheese',                route: null },
        ]
    }
]
</script>

<template>
    <section class="footer-mid pt-70 pb-65 mt-45">
        <div class="container">
            <div class="row justify-content-between">
                <div class="col-xl-3">
                    <div class="widget-about font-md mb-md-3 mb-lg-3 mb-xl-0">
                        <div class="logo mb-30">
                            <Link :href="route('home')" class="mb-15">
                                <img src="img/logo.png" alt="logo" />
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
                                <Link v-if="link.route" :href="route(link.route)">{{ link.name }}</Link>
                                <span v-else class="text-muted">{{ link.name }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>