import { defineStore } from 'pinia'

export const useWishlistStore = defineStore('wishlist', {
  state: () => ({
    items: []
  }),

  getters: {
    wishlistCount: (state) => state.items.length,
    wishlistItems: (state) => state.items,
    isInWishlist: (state) => (productId) => {
      return state.items.some(item => item.id === productId)
    }
  },

  actions: {
    addItem(product) {
      const exists = this.items.find(item => item.id === product.id)
      if (!exists) {
        this.items.push(product)
      }
    },

    removeItem(productId) {
      this.items = this.items.filter(item => item.id !== productId)
    },

    clearWishlist() {
      this.items = []
    }
  },

  persist: true // pinia-plugin-persistedstate
})
