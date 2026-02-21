// Common types used across dashboard components

export interface Address {
  id?: string
  type?: string
  name: string
  address: string
  city: string
  zip: string
  country: string
  phone: string
  email?: string
}

export interface Order {
  id: string
  date: string
  status: 'Pending' | 'Processing' | 'Completed' | 'Cancel'
  total: string
  itemCount: number
}

export interface OrderItem {
  id: string
  name: string
  image: string
  price: string
  quantity: number
  total: string
  color?: string
  size?: string
}

export interface OrderDetails {
  orderId: string
  date: string
  status: string
  subtotal: string
  tax: string
  shipping?: string
  total: string
  billingAddress: Address
  shippingAddress: Address
  items: OrderItem[]
}

export interface WishlistItem {
  id: string
  name: string
  price: string
  image: string
  inStock: boolean
}

export interface DashboardCard {
  color: string
  icon: string
  count: number
  label: string
}

export interface TrackingInfo {
  estimatedDelivery: string
  shippingBy: string
  status: string
  trackingNumber: string
}

export interface TrackingStep {
  label: string
  class: string
}

export interface Country {
  code: string
  name: string
}

export interface AccountFormData {
  firstName: string
  lastName: string
  displayName: string
  email: string
  currentPassword: string
  newPassword: string
  confirmPassword: string
}
