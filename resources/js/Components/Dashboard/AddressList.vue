<script setup lang="ts">
import { ref } from 'vue'

interface Address {
  id: string
  type: string
  name: string
  address: string
  city: string
  zip: string
  country: string
  phone: string
  email: string
}

interface NewAddress {
  name: string
  email: string
  phone: string
  address: string
}

const showAddForm = ref(false)

const addresses = ref<Address[]>([
  {
    id: '1',
    type: 'Billing Address',
    name: 'John Doe',
    address: '3522 Interstate 75 Business Spur',
    city: 'Sault Ste. Marie',
    zip: 'MI 49783',
    country: 'United States',
    phone: '906-632-1500',
    email: 'john@example.com',
  },
])

const newAddress = ref<NewAddress>({
  name: '',
  email: '',
  phone: '',
  address: '',
})

const editAddress = (id: string) => {
  // Navigate to address edit page or show edit modal
  console.log('Edit address:', id)
}

const deleteAddress = (id: string) => {
  if (confirm('Are you sure you want to delete this address?')) {
    addresses.value = addresses.value.filter((addr) => addr.id !== id)
  }
}

const handleAddAddress = () => {
  // In a real application, you would make an API call here
  const address: Address = {
    id: Date.now().toString(),
    type: 'Shipping Address',
    name: newAddress.value.name,
    address: newAddress.value.address,
    city: 'City',
    zip: '00000',
    country: 'Country',
    phone: newAddress.value.phone,
    email: newAddress.value.email,
  }
  
  addresses.value.push(address)
  
  // Reset form
  newAddress.value = {
    name: '',
    email: '',
    phone: '',
    address: '',
  }
  
  showAddForm.value = false
}
</script>

<style scoped>
.address_box {
  border: 1px solid #e0e0e0;
  padding: 20px;
  border-radius: 5px;
  position: relative;
}

.address_actions {
  position: absolute;
  top: 10px;
  right: 10px;
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  gap: 10px;
}

.address_actions a {
  font-size: 16px;
  color: #666;
  transition: color 0.3s;
}

.address_actions a:hover {
  color: #3bb77e;
}
</style>
<template>
    <div>
        <div class="card">
            <div class="card-header p-0 pb-10">
                <h3 class="mb-0">Shipping Address</h3>
            </div>
            <div class="card-body p-0">
                <p>
                    The following addresses will be used on the checkout page by default.
                </p>
                
                <div v-if="addresses.length > 0" class="row mt-20">
                    <div
                        v-for="address in addresses"
                        :key="address.id"
                        class="col-md-6 mb-20"
                    >
                        <div class="address_box">
                            <h4>{{ address.type }}</h4>
                            <p><strong>{{ address.name }}</strong></p>
                            <p>{{ address.address }}</p>
                            <p>{{ address.city }}, {{ address.zip }}</p>
                            <p>{{ address.country }}</p>
                            <p>Phone: {{ address.phone }}</p>
                            <p>Email: {{ address.email }}</p>
                            <ul class="address_actions">
                                <li>
                                    <a href="#" @click.prevent="editAddress(address.id)">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" @click.prevent="deleteAddress(address.id)">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add New Address Form (Collapsible) -->
        <div class="panel-collapse collapse login_form" :class="{ show: showAddForm }">
            <div class="panel-body">
                <h4>Add New Address</h4>
                <form @submit.prevent="handleAddAddress">
                    <div class="row mt-20">
                        <div class="col-md-12">
                            <div class="form-group">
                                <input v-model="newAddress.name" type="text" placeholder="Name" />
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <input v-model="newAddress.email" type="email" placeholder="Email" />
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <input v-model="newAddress.phone" type="text" placeholder="Phone" />
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <textarea
                                    v-model="newAddress.address"
                                    placeholder="Address"
                                    rows="3"
                                ></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <button class="btn btn-md" type="submit">Save</button>
                        <button
                            class="btn btn-md btn-secondary ml-2"
                            type="button"
                            @click="showAddForm = false"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Address Button -->
        <button
            v-if="!showAddForm"
            class="btn btn-primary mt-3"
            @click="showAddForm = true"
        >
            <i class="fa-solid fa-plus mr-2"></i> Add New Address
        </button>
    </div>
</template>