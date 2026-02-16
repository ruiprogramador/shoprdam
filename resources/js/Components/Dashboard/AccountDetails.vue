<script setup lang="ts">
import { ref } from 'vue'

interface AccountFormData {
  firstName: string
  lastName: string
  displayName: string
  email: string
  currentPassword: string
  newPassword: string
  confirmPassword: string
}

const formData = ref<AccountFormData>({
  firstName: '',
  lastName: '',
  displayName: '',
  email: '',
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
})

const handleSubmit = () => {
  // Validate passwords match if new password is entered
  if (formData.value.newPassword && formData.value.newPassword !== formData.value.confirmPassword) {
    alert('New password and confirm password do not match')
    return
  }

  // In a real application, you would make an API call here
  console.log('Saving account details:', formData.value)
  alert('Account details saved successfully!')
}
</script>
<template>
    <div class="card">
        <div class="card-header p-0">
            <h5>Account Details</h5>
        </div>
        <div class="card-body p-0">
            <p>
                Already have an account?
                <a href="/login">Log in instead!</a>
            </p>
            <form @submit.prevent="handleSubmit">
                <div class="row mt-30">
                    <div class="form-group col-md-6">
                        <label>
                            First Name <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.firstName"
                            required
                            class="form-control"
                            type="text"
                        />
                    </div>
                    <div class="form-group col-md-6">
                        <label>
                            Last Name <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.lastName"
                            required
                            class="form-control"
                            type="text"
                        />
                    </div>
                    <div class="form-group col-md-12">
                        <label>
                            Display Name <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.displayName"
                            required
                            class="form-control"
                            type="text"
                        />
                    </div>
                    <div class="form-group col-md-12">
                        <label>
                            Email Address <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.email"
                            required
                            class="form-control"
                            type="email"
                        />
                    </div>
                    <div class="form-group col-md-12">
                        <label>
                            Current Password <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.currentPassword"
                            required
                            class="form-control"
                            type="password"
                        />
                    </div>
                    <div class="form-group col-md-12">
                        <label>
                            New Password <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.newPassword"
                            class="form-control"
                            type="password"
                        />
                    </div>
                    <div class="form-group col-md-12">
                        <label>
                            Confirm Password <span class="required">*</span>
                        </label>
                        <input
                            v-model="formData.confirmPassword"
                            class="form-control"
                            type="password"
                        />
                    </div>
                    <div class="col-md-12">
                        <button type="submit" class="btn btn-fill-out submit font-weight-bold">
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>