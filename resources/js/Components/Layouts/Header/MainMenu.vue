<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
    <div class="main-menu main-menu-padding-1 main-menu-lh-2 d-none d-lg-block font-heading">
        <nav>
            <ul>
                <li>
                    <Link class="active" :href="route('home')">Home</Link>
                </li>
                <li>
                    <!-- toDo -->
                    <!-- <Link :href="route('about')">About</Link> -->
                </li>
                <li>
                    <a href="#">Shop <i class="fi-rs-angle-down"></i></a>
                    <ul class="sub-menu">
                        <li>Shop</li>
                        <li>Shop Details</li>
                        <!-- toDo -->
                        <!-- <li><Link :href="route('shop.index')">Shop</Link></li>
                        <li><Link :href="route('shop.index')">Shop Details</Link></li> -->
                    </ul>
                </li>
                <li>
                    <a href="#">Vendors <i class="fi-rs-angle-down"></i></a>
                    <ul class="sub-menu">
                        <li>Vendors</li>
                        <li>Vendors Details</li>
                        <!-- toDo -->
                        <!-- <li><Link :href="route('vendors.index')">Vendors</Link></li>
                        <li><Link :href="route('vendors.index')">Vendor Details</Link></li> -->
                    </ul>
                </li>
                <li>
                    <a href="#">Blog <i class="fi-rs-angle-down"></i></a>
                    <ul class="sub-menu">
                        <li>Blog</li>
                        <li>Blog Details</li>
                        <!-- toDo -->
                        <!-- <li><Link :href="route('blog.index')">Blog</Link></li>
                        <li><Link :href="route('blog.index')">Blog Details</Link></li> -->
                    </ul>
                </li>
                <li>
                    Contact
                    <!-- <Link :href="route('contact')">Contact</Link> -->
                </li>
                <li class="hot-deals">
                    Flash Sell
                    <!-- <Link :href="route('flash-sell')">Flash Sell</Link> -->
                </li>
            </ul>
        </nav>
    </div>
</template>
