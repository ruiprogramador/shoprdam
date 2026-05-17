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
                    <!-- toDo: <Link :href="route('about')">About</Link> -->
                    <span class="text-muted">About</span>
                </li>
                <li>
                    <a href="#">Shop <i class="fa fa-angle-down"></i></a>
                    <ul class="sub-menu">
                        <!-- toDo: substituir quando routes existirem -->
                        <li><a href="#">Shop</a></li>
                        <li><a href="#">Shop Details</a></li>
                    </ul>
                </li>
                <li>
                    <a href="#">Vendors <i class="fa fa-angle-down"></i></a>
                    <ul class="sub-menu">
                        <li><a href="#">Vendors</a></li>
                        <li><a href="#">Vendor Details</a></li>
                    </ul>
                </li>
                <li>
                    <a href="#">Blog <i class="fa fa-angle-down"></i></a>
                    <ul class="sub-menu">
                        <li><a href="#">Blog</a></li>
                        <li><a href="#">Blog Details</a></li>
                    </ul>
                </li>
                <li>
                    <!-- toDo: <Link :href="route('contact')">Contact</Link> -->
                    <a href="#">Contact</a>
                </li>
                <li class="hot-deals">
                    <!-- toDo: <Link :href="route('flash-sell')">Flash Sell</Link> -->
                    <a href="#">Flash Sell</a>
                </li>
            </ul>
        </nav>
    </div>
</template>