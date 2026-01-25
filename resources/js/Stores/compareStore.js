import { defineStore } from 'pinia'

export const useCompareStore = defineStore('compare', {
  state: () => ({
    items: []
  }),

  getters: {
    compareCount: (state) => state.items.length,
    compareItems: (state) => state.items,
    isInCompare: (state) => (productId) => {
      return state.items.some(item => item.id === productId)
    }
  },

  actions: {
    addItem(product) {
      if (this.items.length >= 4) {
        throw new Error('You can only compare up to 4 products')
      }

      const exists = this.items.find(item => item.id === product.id)
      if (!exists) {
        this.items.push(product)
      }
    },

    removeItem(productId) {
      this.items = this.items.filter(item => item.id !== productId)
    },

    clearCompare() {
      this.items = []
    }
  },

  persist: true
})
