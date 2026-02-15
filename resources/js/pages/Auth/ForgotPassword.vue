<script setup>
import { ref, computed } from 'vue'
import { App, Head, useForm, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue'; 
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};

const page = usePage()

const breadcrumbs = computed(() => page.props.breadcrumbs || [])

</script>

<template>
    <AppLayout>
        <Head title="Forgot Password" />

        <Breadcrumb :items="breadcrumbs" />

        <div class="page page-center">
            <div class="container container-tight py-4">

                <div class="text-center mb-4">
                    <Link :href="route('home')" class="navbar-brand navbar-brand-autodark">
                        <ApplicationLogo
                            class="block h-9 w-auto fill-current text-gray-800"
                        />
                    </Link>
                </div>

                <div class="card card-md">
                    <div class="card-body">
                        <h2 class="card-title text-center mb-4">Forgot Password</h2>
                        <p class="mb-4 text-sm text-gray-600">
                            Forgot your password? No problem. Just let us know your email
                            address and we will email you a password reset link that will allow
                            you to choose a new one.
                        </p>

                        <div
                            v-if="status"
                            class="mb-4 text-sm font-medium text-green-600"
                        >
                            {{ status }}
                        </div>

                        <form @submit.prevent="submit">
                            <div>
                                <InputLabel for="email" value="Email" />

                                <TextInput
                                    id="email"
                                    type="email"
                                    class="mt-1 block w-full"
                                    v-model="form.email"
                                    required
                                    autofocus
                                    autocomplete="username"
                                />

                                <InputError class="mt-2" :message="form.errors.email" />
                            </div>

                            <div class="mt-4 flex items-center justify-end">
                                <PrimaryButton
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Email Password Reset Link
                                </PrimaryButton>
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
