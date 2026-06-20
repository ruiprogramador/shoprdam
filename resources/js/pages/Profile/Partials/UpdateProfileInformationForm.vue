<script setup>
import { watch, onMounted } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import FileUpload from '@/Components/FileUpload.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    mustVerifyEmail: Boolean,
    status: String,
    imageUrl: String,
    updateProfileUrl: {
        type: String,
        default: 'profile.update'
    }
});

const page = usePage();
const user = page.props.auth?.user ?? page.props.user;

// updateProfileUrl fora do useForm
const form = useForm({
    image: props.imageUrl ?? null,
    name: user?.name ?? '',
    email: user?.email ?? '',
});

const submit = () => {
    console.log('Submitting to:', props.updateProfileUrl)
    console.log('form.image:', form.image)

    form.patch(route(props.updateProfileUrl), {
        forceFormData: true, // necessário para enviar ficheiros
    })
}

watch(() => form.image, (value) => {
    console.log('form.image changed:', value)
})

watch(() => props.imageUrl, (value) => {
    console.log('imageUrl watch fired:', value)
    if (value) {
        form.image = value
    }
}, { immediate: true })
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Profile Information</h2>
            <p class="mt-1 text-sm text-gray-600">
                Update your account's profile information and email address.
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <div>
                <InputLabel for="image" value="Profile Image" />
                <FileUpload v-model:src="form.image" class="mt-2" accepted-file-types="image/*" />
                <InputError class="mt-2" :message="form.errors.image" />
            </div>

            <div>
                <InputLabel for="name" value="Name" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-gray-800">
                    Your email address is unverified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>
                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton type="button" @click="submit" :disabled="form.processing">Save</PrimaryButton>
            </div>
        </form>
    </section>
</template>