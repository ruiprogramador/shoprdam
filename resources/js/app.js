import '../css/app.css';
import '../css/navbar-vertical.css';
import '../css/tabler/tabler.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
/*import '../css/tabler/tabler-flags.min.css';
import '../css/tabler/tabler-payments.min.css';
import '../css/tabler/tabler-vendors.min.css';*/
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

import { createPinia } from 'pinia';

import piniaPluginPersistedstate from 'pinia-plugin-persistedstate'
import Toast from 'vue-toastification'
import 'vue-toastification/dist/index.css'

const appName = import.meta.env.VITE_APP_NAME || 'ShopRdam';
const pinia = createPinia()
pinia.use(piniaPluginPersistedstate)

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {

        // const pinia = createPinia();

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(pinia)
            .use(Toast, {
                position: 'top-right',
                timeout: 3000,
                closeOnClick: true,
                pauseOnHover: true
            })
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
