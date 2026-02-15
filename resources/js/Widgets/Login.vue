<template>

    <!-- <Head :title="title" /> -->

    <div class="page page-center">
        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <!-- <Link :href="route('home')" class="navbar-brand navbar-brand-autodark">
                    <slot name="logo" />
                </Link> -->
                <Link :href="route('home')" class="navbar-brand navbar-brand-autodark">
                    <ApplicationLogo
                        class="block h-9 w-auto fill-current text-gray-800"
                    />
                </Link>
            </div>

            <div class="card card-md">
                <div class="card-body">
                <h2 class="h2 text-center mb-4">Login to your account</h2>

                <form @submit.prevent="submit">
                    <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input
                        v-model="form.email"
                        type="email"
                        class="form-control"
                        required
                        placeholder="your@email.com"
                    />
                    <div v-if="form.errors.email" class="text-danger mt-1">
                        {{ form.errors.email }}
                    </div>
                    </div>

                    <div class="mb-2">
                    <label class="form-label">
                        Password
                        <span class="form-label-description">
                        <!-- <Link :href="route('password.request')">
                            I forgot password
                        </Link> -->
                            <Link v-if="getRoute('password')"
                                :href="getRoute('password')"
                                class="text-secondary"
                            >
                                I forgot password
                            </Link>
                        </span>
                    </label>

                    <div class="input-group input-group-flat">
                        <input
                        :type="showPassword ? 'text' : 'password'"
                        v-model="form.password"
                        required
                        class="form-control"
                        placeholder="Your password"
                        />
                        <span class="input-group-text">
                        <a href="#" class="link-secondary" @click.prevent="togglePassword">
                            👁
                        </a>
                        </span>
                    </div>

                    <div v-if="form.errors.password" class="text-danger mt-1">
                        {{ form.errors.password }}
                    </div>
                    </div>

                    <div class="mb-2">
                    <label class="form-check">
                        <input type="checkbox" v-model="form.remember" class="form-check-input" />
                        <span class="form-check-label">Remember me</span>
                    </label>
                    </div>

                    <div class="form-footer">
                    <button class="btn btn-primary w-100" :disabled="form.processing">
                        {{ form.processing ? 'Signing in...' : 'Sign in' }}
                    </button>
                    </div>
                </form>
                </div>
            </div>

            <div class="text-center text-secondary mt-3" v-if="middleware !== 'admin'">
                Don't have an account?
                <!-- <Link :href="route('register')" class="text-secondary">
                Sign up
                </Link> -->
                <Link v-if="getRoute('register')"
                    :href="getRoute('register')"
                    class="text-secondary"
                >
                    Sign up
                </Link>
            </div>
        </div>
  </div>
</template>
<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const props = defineProps({
    title: {
        type: String,
        default: "Login"
    },
    redirectIfAuthenticated: {
        type: String,
        default: "/"
    },
    middleware: {
        type: String,
        default: "guest"
    },
    path: {
        type: String,
        default: "auth.login"
    },
    status: {
        type: String,
    },
});

const showPassword = ref(false)

const form = useForm({
  email: '',
  password: '',
  remember: false
})

const togglePassword = () => {
  showPassword.value = !showPassword.value
}

const submit = () => {
    //form.post('/login')
    // form.post(route(path))
    form.post(route(props.path))
}

const routeMap = {
  guest: {
    password: "password.request",
    register: "register",
  },
  admin: {
    password: "admin.password.request",
    // register: null
  }
}
const getRoute = (type) => {
  const group = routeMap[props.middleware] || routeMap.guest
  return group[type] ? route(group[type]) : null
}


</script>
