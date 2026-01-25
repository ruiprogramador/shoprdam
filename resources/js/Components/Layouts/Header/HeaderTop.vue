<script setup>
import { Link } from '@inertiajs/vue3'

const newsItems = [
  '100% Secure delivery without contacting the courier',
  'Supper Value Deals - Save more with coupons',
  'Trendy 25silver jewelry, save up 35% off today'
]

const contactNumber = '+0000-000'
</script>

<template>
    <div class="header-top header-top-ptb-1 d-none d-lg-block">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-xxl-3 col-xl-4 col-lg-7">
                    <div class="header-info">
                        <ul>
                            <li>
                                <!-- toDo -->
                                About Us
                                <!-- <Link :href="route('about')">About Us</Link> -->
                            </li>
                            <li>
                                <!-- toDo -->
                                My Account
                                <!-- <Link :href="route('account.index')">
                                    My Account</Link>
                                </li> -->
                            </li>
                            <li>
                                <!-- toDo -->
                                Wishlist
                                <!-- <Link :href="route('wishlist.index')">
                                    Wishlist
                                </Link> -->
                            </li>
                            <li>
                                <!-- toDo -->
                                Order Tracking
                                <!-- <Link :href="route('order.tracking')">
                                    Order Tracking
                                </Link> -->
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-xxl-6 col-xl-5 col-lg-4 d-none d-xl-block">
                    <div class="text-center">
                        <div id="news-flash" class="d-inline-block">
                            <ul>
                                <li v-for="(news, index) in newsItems" :key="index">{{ news }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-xxl-3 col-xl-3 col-lg-5">
                    <div class="header-info header-info-right">
                        <ul>
                            <li>Need help? Call Us: <strong class="text-brand">{{ contactNumber }}</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
