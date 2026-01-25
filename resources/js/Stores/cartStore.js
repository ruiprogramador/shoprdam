import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

export const useCartStore = defineStore('cart', {
  state: () => ({
    items: [],
    total: 0
  }),

  getters: {
    itemCount: (state) => state.items.reduce((sum, item) => sum + item.quantity, 0),
    cartTotal: (state) => state.total,
    cartItems: (state) => state.items
  },

  actions: {
    addItem(product) {
      const existingItem = this.items.find(item => item.id === product.id)

      if (existingItem) {
        existingItem.quantity++
      } else {
        this.items.push({
          ...product,
          quantity: 1
        })
      }

      this.calculateTotal()
      this.syncWithBackend()
    },

    removeItem(productId) {
      this.items = this.items.filter(item => item.id !== productId)
      this.calculateTotal()
      this.syncWithBackend()
    },

    updateQuantity(productId, quantity) {
      const item = this.items.find(item => item.id === productId)
      if (item) {
        item.quantity = quantity
        this.calculateTotal()
        this.syncWithBackend()
      }
    },

    calculateTotal() {
      this.total = this.items.reduce((sum, item) => {
        return sum + (item.price * item.quantity)
      }, 0)
    },

    clearCart() {
      this.items = []
      this.total = 0
      this.syncWithBackend()
    },

    syncWithBackend() {
      // Sync cart with backend using Inertia
      router.post('/cart/sync', {
        items: this.items
      }, {
        preserveScroll: true,
        preserveState: true,
        only: []
      })
    },

    loadCart(cartData) {
      this.items = cartData.items || []
      this.calculateTotal()
    }
  }
})
