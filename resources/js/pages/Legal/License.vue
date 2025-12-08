<template>
    <Head title="License" />

  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="row g-2 align-items-center">
        <div class="col">
          <h2 class="page-title">License</h2>
        </div>
      </div>
    </div>
  </div>

  <div class="page-body">
    <div class="container-xl">
      <div class="row row-cards">
        <div class="col-lg-8">
          <div class="card card-lg">
            <div class="card-body">
              <div class="markdown">
                <p>
                  This is a legal agreement between you, the Purchaser, and Shop By RDAM. Purchasing or downloading of any Shop By RDAM product (Shop By RDAM Free, Shop By RDAM PRO,
                  Shop By RDAM Email), constitutes your acceptance of the terms of this license,
                  <a :href="route('terms-of-service')">Shop By RDAM terms of service</a>, and
                  <a :href="route('privacy-policy')">Shop By RDAM privacy policy</a>.
                </p>
                <h3 id="license">License</h3>
                <p>
                  The License grants you, the Purchaser, an ongoing, non-exclusive, worldwide license to make use of the Shop By RDAM products you have purchased.
                  We are the owner of the source code and you are granted the license to use the source code.
                </p>
                <h3 id="you-are-allowed-to">You are allowed to:</h3>
                <ol>
                  <li>Create an unlimited number of projects.</li>
                  <li>Use in open source projects.</li>
                  <li>Use in personal projects.</li>
                  <li>Use in commercial projects.</li>
                  <li>Use in design and development projects.</li>
                  <li>Modify the source code for personal or commercial use.</li>
                  <li>Distribute our product as part of a larger project (SaaS, Open Source, or personal project).</li>
                </ol>
                <h3 id="you-are-not-allowed-to">You are not allowed to:</h3>
                <ol>
                  <li>Resell, sublicense, share, or redistribute any of the products without express written permission from Shop by RDAM.</li>
                  <li>Use our products in any resalable web applications or software programs, you cannot include our graphic resources as an additional
                    attachment.</li>
                  <li>You cannot redistribute any of the software, or products created with Shop by RDAM paid products.</li>
                  <li>You cannot add our source code to any open source repository.</li>
                  <li>The source code may not be placed on any website in a complete or archived downloadable format.</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
  import { Head } from '@inertiajs/vue3'
</script>
