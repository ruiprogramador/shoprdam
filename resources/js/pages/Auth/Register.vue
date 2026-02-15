<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, Head, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';

const showPassword = ref(false)

const form = useForm({
  name: '',
  email: '',
  password: '',
  agree: false
})

const togglePassword = () => {
  showPassword.value = !showPassword.value
}

const submit = () => {
  if (!form.agree) return
  form.post('/register')
}

const page = usePage()

const breadcrumbs = computed(() => page.props.breadcrumbs || [])

</script>
<template>
    <AppLayout>
        <Head title="Register" />

        <Breadcrumb :items="breadcrumbs" />

        <div class="page page-center">
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
                <h2 class="card-title text-center mb-4">Create new account</h2>

                <form @submit.prevent="submit">
                    <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input v-model="form.name" type="text" class="form-control" required />
                    <div v-if="form.errors.name" class="text-danger mt-1">
                        {{ form.errors.name }}
                    </div>
                    </div>

                    <div class="mb-3">
                    <label class="form-label">Email address</label>
                    <input v-model="form.email" type="email" class="form-control" required />
                    <div v-if="form.errors.email" class="text-danger mt-1">
                        {{ form.errors.email }}
                    </div>
                    </div>

                    <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group input-group-flat">
                        <input
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        class="form-control"
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

                    <div class="mb-3">
                    <label class="form-label">Password Confirmation</label>
                    <div class="input-group input-group-flat">
                        <input
                        v-model="form.password_confirmation"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        class="form-control"
                        />
                        <span class="input-group-text">
                        <a href="#" class="link-secondary" @click.prevent="togglePassword">
                            👁
                        </a>
                        </span>
                    </div>
                    <div v-if="form.errors.password_confirmation" class="text-danger mt-1">
                        {{ form.errors.password_confirmation }}
                    </div>
                    </div>

                    <div class="mb-3">
                    <label class="form-check">
                        <input type="checkbox" v-model="form.agree" class="form-check-input" />
                        <span class="form-check-label">
                        Agree the
                        <!-- <a href="/terms-of-service" target="_blank">terms and policy</a> -->
                        <Link :href="route('terms-of-service')" class="text-secondary">
                            terms and policy
                        </Link>
                        </span>
                    </label>
                    </div>

                    <div class="form-footer">
                    <button
                        class="btn btn-primary w-100"
                        :disabled="form.processing || !form.agree"
                    >
                        {{ form.processing ? 'Creating...' : 'Create new account' }}
                    </button>
                    </div>
                </form>
                </div>
            </div>

            <div class="text-center text-secondary mt-3">
                Already have an account?
                <Link :href="route('login')">Sign in</Link>
            </div>
            </div>
        </div>
    </AppLayout>
</template>
