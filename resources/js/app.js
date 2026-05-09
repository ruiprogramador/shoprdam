import './echo'
import '../css/app.css'
import '../css/navbar-vertical.css'
import '../css/tabler/tabler.min.css'
import '../css/main.css'
import '../css/header-navbar.css'
import '@fortawesome/fontawesome-free/css/all.min.css'

import 'filepond/dist/filepond.min.css'
import 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css'

import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { ZiggyVue } from '../../vendor/tightenco/ziggy'

import { createPinia } from 'pinia'
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'

import Toast, { useToast } from 'vue-toastification'
import 'vue-toastification/dist/index.css'

import vueFilePond from 'vue-filepond'
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type/dist/filepond-plugin-file-validate-type.esm.js'
import FilePondPluginImagePreview from 'filepond-plugin-image-preview/dist/filepond-plugin-image-preview.esm.js'

const appName = import.meta.env.VITE_APP_NAME || 'ShopRdam'

const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

createInertiaApp({
    title: (title) => `${title} - ${appName}`,

    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob('./pages/**/*.vue')
        ),

    setup({ el, App, props, plugin }) {
        const vueApp = createApp({ render: () => h(App, props) })

        const FilePond = vueFilePond(FilePondPluginFileValidateType, FilePondPluginImagePreview)

        vueApp
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(Toast, {
                position: 'top-right',
                timeout: 3000,
                closeOnClick: true,
                pauseOnHover: true,
                draggable: true,
            })

        vueApp.component('FilePond', FilePond)
        vueApp.mount(el)

        // ─── Global Flash → Toast ─────────────────────────────────────
        // Register AFTER mount so useToast() has access to the Toast plugin instance.
        // Using router.on('success') as the single source of truth — covers all
        // Inertia requests (navigation, form submits, reloads).
        const toast = useToast()

        router.on('success', (event) => {
            const flash = event.detail.page.props.flash
            if (!flash) return
            if (flash.success) toast.success(flash.success)
            if (flash.error)   toast.error(flash.error)
            if (flash.info)    toast.info(flash.info)
            if (flash.warning) toast.warning(flash.warning)
        })
    },

    progress: {
        color: '#4B5563',
    },
})