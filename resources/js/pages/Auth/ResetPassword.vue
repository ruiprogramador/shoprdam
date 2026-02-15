<script setup>
// 1️⃣ Vue core
import { computed } from 'vue'
// 2️⃣ Framework / plugins
import { Link, Head, useForm, usePage } from '@inertiajs/vue3';
// 3️⃣ Layouts
import AppLayout from '@/Layouts/AppLayout.vue';
// 4️⃣ Local components
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

const page = usePage()

const breadcrumbs = computed(() => page.props.breadcrumbs || [])

</script>

<template>
    <AppLayout>
        <Head title="Reset Password" />

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
                        <h2 class="card-title text-center mb-4">Reset Password</h2>
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

                            <div class="mt-4">
                                <InputLabel for="password" value="Password" />

                                <TextInput
                                    id="password"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password"
                                    required
                                    autocomplete="new-password"
                                />

                                <InputError class="mt-2" :message="form.errors.password" />
                            </div>

                            <div class="mt-4">
                                <InputLabel
                                    for="password_confirmation"
                                    value="Confirm Password"
                                />

                                <TextInput
                                    id="password_confirmation"
                                    type="password"
                                    class="mt-1 block w-full"
                                    v-model="form.password_confirmation"
                                    required
                                    autocomplete="new-password"
                                />

                                <InputError
                                    class="mt-2"
                                    :message="form.errors.password_confirmation"
                                />
                            </div>

                            <div class="mt-4 flex items-center justify-end">
                                <PrimaryButton
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Reset Password
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="text-center text-secondary mt-3">
                    <Link :href="route('login')" class="link link-sm link-secondary">
                        Remember your password? Sign in
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
