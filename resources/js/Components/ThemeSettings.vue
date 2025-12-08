<template>
  <div class="settings">
    <a
      href="#"
      class="btn btn-floating btn-icon btn-primary"
      data-bs-toggle="offcanvas"
      data-bs-target="#offcanvasSettings"
    >
      🎨
    </a>

    <form
      class="offcanvas offcanvas-start offcanvas-narrow"
      id="offcanvasSettings"
    >
      <div class="offcanvas-header">
        <h2 class="offcanvas-title">Theme Builder</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" />
      </div>

      <div class="offcanvas-body d-flex flex-column">
        <div v-for="(value, key) in themeConfig" :key="key" class="mb-3">
          <label class="form-label text-capitalize">{{ key }}</label>
          <input
            class="form-control"
            :value="storage(key)"
            @input="updateTheme(key, $event.target.value)"
          />
        </div>

        <div class="mt-auto">
          <button type="button" class="btn w-100" @click="resetTheme">
            Reset changes
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

<script setup>
const themeConfig = {
  theme: "light",
  "theme-base": "gray",
  "theme-font": "sans-serif",
  "theme-primary": "blue",
  "theme-radius": "1",
}

const storage = (key) => {
  return window.localStorage.getItem('tabler-' + key) || themeConfig[key]
}

const updateTheme = (key, value) => {
  document.documentElement.setAttribute('data-bs-' + key, value)
  window.localStorage.setItem('tabler-' + key, value)
}

const resetTheme = () => {
  for (const key in themeConfig) {
    document.documentElement.removeAttribute('data-bs-' + key)
    window.localStorage.removeItem('tabler-' + key)
  }
}
</script>
