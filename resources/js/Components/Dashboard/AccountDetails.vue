<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3'

const page = usePage()
const user = page.props.auth.user

const form = useForm({
    first_name:       user.first_name      ?? '',
    last_name:        user.last_name       ?? '',
    display_name:     user.display_name    ?? '',
    email:            user.email           ?? '',
    current_password: '',
    new_password:     '',
    confirm_password: '',
})

const handleSubmit = () => {
    if (form.new_password && form.new_password !== form.confirm_password) {
        form.setError('confirm_password', 'Passwords do not match')
        return
    }
    form.post(route('profile.update'), { preserveScroll: true })
}
</script>

<template>
    <div class="card">
        <div class="card-header p-0">
            <h5>Account Details</h5>
        </div>
        <div class="card-body p-0">
            <form @submit.prevent="handleSubmit">
                <div class="row mt-30">
                    <div class="form-group col-md-6">
                        <label for="first_name">First Name <span class="required">*</span></label>
                        <input id="first_name" v-model="form.first_name" required class="form-control" type="text" />
                        <p v-if="form.errors.first_name" class="text-danger small">{{ form.errors.first_name }}</p>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="last_name">Last Name <span class="required">*</span></label>
                        <input id="last_name" v-model="form.last_name" required class="form-control" type="text" />
                        <p v-if="form.errors.last_name" class="text-danger small">{{ form.errors.last_name }}</p>
                    </div>
                    <div class="form-group col-md-12">
                        <label for="display_name">Display Name <span class="required">*</span></label>
                        <input id="display_name" v-model="form.display_name" required class="form-control" type="text" />
                    </div>
                    <div class="form-group col-md-12">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input id="email" v-model="form.email" required class="form-control" type="email" />
                        <p v-if="form.errors.email" class="text-danger small">{{ form.errors.email }}</p>
                    </div>
                    <div class="form-group col-md-12">
                        <label for="current_password">Current Password</label>
                        <input id="current_password" v-model="form.current_password" class="form-control" type="password" autocomplete="current-password" />
                        <p v-if="form.errors.current_password" class="text-danger small">{{ form.errors.current_password }}</p>
                    </div>
                    <div class="form-group col-md-12">
                        <label for="new_password">New Password</label>
                        <input id="new_password" v-model="form.new_password" class="form-control" type="password" autocomplete="new-password" />
                    </div>
                    <div class="form-group col-md-12">
                        <label for="confirm_password">Confirm Password</label>
                        <input id="confirm_password" v-model="form.confirm_password" class="form-control" type="password" autocomplete="new-password" />
                        <p v-if="form.errors.confirm_password" class="text-danger small">{{ form.errors.confirm_password }}</p>
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-fill-out submit font-weight-bold" :disabled="form.processing">
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>