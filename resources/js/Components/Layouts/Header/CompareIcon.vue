<script setup>
import { computed } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'

const page = usePage()
const compareCount = computed(() => page.props.compareCount)
</script>

<template>
  <div class="header-action-icon-2">
    <Link href="/compare">
      <i class="fa-solid fa-code-compare"></i>
      <span class="pro-count blue">{{ compareCount }}</span>
    </Link>
    <Link href="/compare"><span class="lable ml-0">Compare</span></Link>
  </div>
</template>
