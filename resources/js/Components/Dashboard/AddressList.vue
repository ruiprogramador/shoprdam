<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from 'vue-toastification'

interface Address { id: string; type: string; name: string; address: string; city: string; zip: string; country: string; phone: string; email: string }
interface NewAddress { name: string; email: string; phone: string; address: string }

const toast        = useToast()
const showAddForm  = ref(false)
const deletingId   = ref<string | null>(null)

const addresses = ref<Address[]>([
    {
        id: '1', type: 'Billing Address', name: 'John Doe',
        address: '3522 Interstate 75 Business Spur', city: 'Sault Ste. Marie',
        zip: 'MI 49783', country: 'United States', phone: '906-632-1500', email: 'john@example.com',
    },
])

const newAddress = ref<NewAddress>({ name: '', email: '', phone: '', address: '' })

const editAddress = (id: string) => {
    router.visit(route('account.address.edit', id))
}

const confirmDelete = (id: string) => { deletingId.value = id }
const cancelDelete  = ()           => { deletingId.value = null }

const deleteAddress = (id: string) => {
    addresses.value = addresses.value.filter(a => a.id !== id)
    deletingId.value = null
    toast.success('Address deleted')
}

const handleAddAddress = () => {
    const address: Address = {
        id:      Date.now().toString(),
        type:    'Shipping Address',
        name:    newAddress.value.name,
        address: newAddress.value.address,
        city: '', zip: '', country: '', phone: newAddress.value.phone, email: newAddress.value.email,
    }
    addresses.value.push(address)
    newAddress.value = { name: '', email: '', phone: '', address: '' }
    showAddForm.value = false
    toast.success('Address added successfully')
}
</script>


<style scoped>
.address_box    { border: 1px solid #e0e0e0; padding: 20px; border-radius: 5px; position: relative; }
.address_actions { position: absolute; top: 10px; right: 10px; list-style: none; padding: 0; margin: 0; display: flex; gap: 10px; }
.address_actions a { font-size: 16px; color: #666; transition: color 0.3s; }
.address_actions a:hover { color: #3bb77e; }
</style>

<template>
    <div>
        <div class="card">
            <div class="card-header p-0 pb-10">
                <h3 class="mb-0">Shipping Address</h3>
            </div>
            <div class="card-body p-0">
                <p>The following addresses will be used on the checkout page by default.</p>

                <div v-if="addresses.length" class="row mt-20">
                    <div v-for="address in addresses" :key="address.id" class="col-md-6 mb-20">
                        <div class="address_box">
                            <h4>{{ address.type }}</h4>
                            <p><strong>{{ address.name }}</strong></p>
                            <p>{{ address.address }}</p>
                            <p>{{ address.city }}, {{ address.zip }}</p>
                            <p>{{ address.country }}</p>
                            <p>Phone: {{ address.phone }}</p>
                            <p>Email: {{ address.email }}</p>

                            <ul class="address_actions">
                                <li><a href="#" @click.prevent="editAddress(address.id)"><i class="fa-solid fa-pen-to-square"></i></a></li>
                                <li><a href="#" @click.prevent="confirmDelete(address.id)"><i class="fa-solid fa-trash-can"></i></a></li>
                            </ul>
                        </div>

                        <!-- Confirmação inline em vez de confirm() nativo -->
                        <div v-if="deletingId === address.id" class="mt-2 p-3 border border-danger rounded text-sm">
                            <p class="text-danger mb-2">Are you sure you want to delete this address?</p>
                            <button class="btn btn-sm btn-danger mr-2" type="button" @click="deleteAddress(address.id)">Delete</button>
                            <button class="btn btn-sm btn-secondary"   type="button" @click="cancelDelete">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulário novo endereço -->
        <div v-show="showAddForm" class="panel-body mt-3 p-3 border rounded">
            <h4>Add New Address</h4>
            <form @submit.prevent="handleAddAddress">
                <div class="row mt-20">
                    <div class="col-md-12 mb-2"><input v-model="newAddress.name"    type="text"  placeholder="Name"    class="form-control" required /></div>
                    <div class="col-md-6 mb-2"> <input v-model="newAddress.email"   type="email" placeholder="Email"   class="form-control" required /></div>
                    <div class="col-md-6 mb-2"> <input v-model="newAddress.phone"   type="text"  placeholder="Phone"   class="form-control" /></div>
                    <div class="col-md-12 mb-2"><textarea v-model="newAddress.address" placeholder="Address" rows="3" class="form-control"></textarea></div>
                </div>
                <button class="btn btn-md" type="submit">Save</button>
                <button class="btn btn-md btn-secondary ml-2" type="button" @click="showAddForm = false">Cancel</button>
            </form>
        </div>

        <button v-if="!showAddForm" class="btn btn-primary mt-3" type="button" @click="showAddForm = true">
            <i class="fa-solid fa-plus mr-2"></i> Add New Address
        </button>
    </div>
</template>