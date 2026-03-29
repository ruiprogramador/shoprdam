<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import VerticalLayout from '@/Layouts/VerticalLayout.vue';
import DeleteUserForm from '@/pages/Profile/Partials/DeleteUserForm.vue';
import UpdatePasswordForm from '@/pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/pages/Profile/Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
        default: false
    },
    status: {
        type: String,
        default: null
    },
    imageUrl: {
        type: String,
        default: null
    },
    updateProfileUrl: {
        type: String,
        default: null
    },
    updatePasswordUrl: {
        type: String,
        default: null
    },
    deleteUserUrl: {
        type: String,
        default: null
    }
})

</script>

<template>
    <Head title="Profile" />

    <VerticalLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Profile
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        :image-url="imageUrl"
                        :update-profile-url="updateProfileUrl"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <UpdatePasswordForm
                        :update-password-url="updatePasswordUrl"
                        class="max-w-xl"
                    />
                </div>

                <div
                    class="bg-white p-4 shadow sm:rounded-lg sm:p-8"
                >
                    <DeleteUserForm
                        :delete-user-url="deleteUserUrl"
                        class="max-w-xl"
                    />
                </div>
            </div>
        </div>
    </VerticalLayout>
</template>
