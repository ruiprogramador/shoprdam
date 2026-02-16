<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import DashboardSidebar from '@/Components/Dashboard/DashboardSidebar.vue'

interface Props {
    address?: {
        id: string
        name: string
        email: string
        phone: string
        address: string
        city: string
        zip: string
        country: string
    }
    countries?: Array<{ code: string; name: string }>
    cities?: string[]
}

const props = withDefaults(defineProps<Props>(), {
    countries: () => [
        { code: 'US', name: 'United States' },
        { code: 'CA', name: 'Canada' },
        { code: 'UK', name: 'United Kingdom' },
        { code: 'AU', name: 'Australia' },
        { code: 'DE', name: 'Germany' },
        { code: 'FR', name: 'France' },
    ],
    cities: () => [
        'New York',
        'Los Angeles',
        'Chicago',
        'Houston',
        'Phoenix',
        'Philadelphia',
    ],
})

const isEditMode = computed(() => !!props.address)

const form = useForm({
    name: props.address?.name || '',
    email: props.address?.email || '',
    phone: props.address?.phone || '',
    address: props.address?.address || '',
    city: props.address?.city || '',
    zip: props.address?.zip || '',
    country: props.address?.country || '',
})

const submit = () => {
    if (isEditMode.value && props.address) {
        form.put(route('dashboard.address.update', props.address.id))
    } else {
        form.post(route('dashboard.address.store'))
    }
}

const handleTabChange = (tab: string) => {
    router.visit(route('dashboard.index'), {
        data: { tab },
        preserveState: true,
    })
}
</script>

<template>
    <div class="main pages">
        <div class="page-content pt-70 pb-60">
            <div class="container">
                <div class="row">
                    <div class="col-12">
                        <div class="row">
                            <div class="col-md-3">
                                <DashboardSidebar
                                    :active-tab="'address'"
                                    @tab-change="handleTabChange"
                                />
                            </div>

                            <div class="col-md-9">
                                <div class="tab-content account dashboard-content pl-50">
                                    <div class="tab-pane fade active show">
                                        <div class="add_new_address wsus__shipping_address mb_40">
                                            <div class="card">
                                                <div class="card-header p-0">
                                                    <h4>{{ isEditMode ? 'Edit Address' : 'Add New Address' }}</h4>
                                                </div>
                                                <div class="card-body p-0 mt-20">
                                                    <form @submit.prevent="submit">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        Name <span class="required">*</span>
                                                                    </label>
                                                                    <input
                                                                        v-model="form.name"
                                                                        class="form-control"
                                                                        :class="{ 'is-invalid': form.errors.name }"
                                                                        type="text"
                                                                    />
                                                                    <div v-if="form.errors.name" class="invalid-feedback">
                                                                        {{ form.errors.name }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        Email <span class="required">*</span>
                                                                    </label>
                                                                    <input
                                                                        v-model="form.email"
                                                                        class="form-control"
                                                                        :class="{ 'is-invalid': form.errors.email }"
                                                                        type="email"
                                                                    />
                                                                    <div v-if="form.errors.email" class="invalid-feedback">
                                                                        {{ form.errors.email }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        Phone <span class="required">*</span>
                                                                    </label>
                                                                    <input
                                                                        v-model="form.phone"
                                                                        class="form-control"
                                                                        :class="{ 'is-invalid': form.errors.phone }"
                                                                        type="tel"
                                                                    />
                                                                    <div v-if="form.errors.phone" class="invalid-feedback">
                                                                        {{ form.errors.phone }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        Country <span class="required">*</span>
                                                                    </label>
                                                                    <select
                                                                        v-model="form.country"
                                                                        class="form-control select-active"
                                                                        :class="{ 'is-invalid': form.errors.country }"
                                                                    >
                                                                        <option value="">Select Country</option>
                                                                        <option
                                                                            v-for="country in countries"
                                                                            :key="country.code"
                                                                            :value="country.code"
                                                                        >
                                                                        {{ country.name }}
                                                                        </option>
                                                                    </select>
                                                                    <div v-if="form.errors.country" class="invalid-feedback">
                                                                        {{ form.errors.country }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        City <span class="required">*</span>
                                                                    </label>
                                                                    <select
                                                                        v-model="form.city"
                                                                        class="form-control select-active"
                                                                        :class="{ 'is-invalid': form.errors.city }"
                                                                    >
                                                                        <option value="">Select City</option>
                                                                        <option
                                                                        v-for="city in cities"
                                                                        :key="city"
                                                                        :value="city"
                                                                        >
                                                                        {{ city }}
                                                                        </option>
                                                                    </select>
                                                                    <div v-if="form.errors.city" class="invalid-feedback">
                                                                        {{ form.errors.city }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <div class="form-group">
                                                                    <label>
                                                                        Zip <span class="required">*</span>
                                                                    </label>
                                                                    <input
                                                                        v-model="form.zip"
                                                                        class="form-control"
                                                                        :class="{ 'is-invalid': form.errors.zip }"
                                                                        type="text"
                                                                    />
                                                                    <div v-if="form.errors.zip" class="invalid-feedback">
                                                                        {{ form.errors.zip }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="form-group col-md-12">
                                                                <label>
                                                                    Address <span class="required">*</span>
                                                                </label>
                                                                <textarea
                                                                    v-model="form.address"
                                                                    rows="6"
                                                                    class="form-control"
                                                                    :class="{ 'is-invalid': form.errors.address }"
                                                                    placeholder="Address"
                                                                ></textarea>
                                                                <div v-if="form.errors.address" class="invalid-feedback">
                                                                    {{ form.errors.address }}
                                                                </div>
                                                            </div>
                                                            <div class="col-md-12">
                                                                <button
                                                                    type="submit"
                                                                    class="btn btn-fill-out submit font-weight-bold mt-10"
                                                                    :disabled="form.processing"
                                                                >
                                                                    <span v-if="form.processing">
                                                                        <i class="fa fa-spinner fa-spin me-2"></i>
                                                                        Saving...
                                                                    </span>
                                                                    <span v-else>Save</span>
                                                                </button>
                                                                <Link
                                                                    :href="route('dashboard.index')"
                                                                    class="btn btn-secondary mt-10 ml-2"
                                                                >
                                                                    Cancel
                                                                </Link>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
