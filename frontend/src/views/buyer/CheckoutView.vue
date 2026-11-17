<template>
  <div class="page-container max-w-2xl">
    <h1 class="section-title">Checkout</h1>

    <form @submit.prevent="handleCheckout" class="space-y-6">
      <!-- Order Summary -->
      <div class="card">
        <h2 class="text-lg font-semibold mb-4">Order Summary</h2>
        <div class="space-y-3 pb-4 border-b border-neutral-200">
          <div class="flex justify-between">
            <p>Steel Coils - 500 kg @ ₹125/kg</p>
            <p class="font-semibold">₹62,500</p>
          </div>
          <div class="flex justify-between text-sm text-muted">
            <p>Taxes (18% GST)</p>
            <p>₹11,250</p>
          </div>
          <div class="flex justify-between text-sm text-muted">
            <p>Shipping</p>
            <p>₹2,000</p>
          </div>
        </div>
        <div class="flex justify-between pt-4 font-bold text-lg">
          <p>Total</p>
          <p class="text-primary-600">₹75,750</p>
        </div>
      </div>

      <!-- Delivery Address -->
      <div class="card">
        <h2 class="text-lg font-semibold mb-4">Delivery Address</h2>
        <div class="space-y-4">
          <input v-model="form.streetAddress" type="text" class="input-field w-full" placeholder="Street address" required />
          <div class="grid grid-cols-2 gap-4">
            <input v-model="form.city" type="text" class="input-field" placeholder="City" required />
            <input v-model="form.state" type="text" class="input-field" placeholder="State" required />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <input v-model="form.postalCode" type="text" class="input-field" placeholder="Postal Code" required />
            <input v-model="form.country" type="text" class="input-field" placeholder="Country" value="India" required />
          </div>
        </div>
      </div>

      <!-- Payment Method -->
      <div class="card">
        <h2 class="text-lg font-semibold mb-4">Payment Method</h2>
        <div class="space-y-3">
          <label class="flex items-center p-3 border border-neutral-200 rounded-lg cursor-pointer hover:bg-neutral-50">
            <input v-model="form.paymentMethod" type="radio" value="upi" required />
            <span class="ml-3">UPI</span>
          </label>
          <label class="flex items-center p-3 border border-neutral-200 rounded-lg cursor-pointer hover:bg-neutral-50">
            <input v-model="form.paymentMethod" type="radio" value="bank_transfer" />
            <span class="ml-3">Bank Transfer (NEFT/RTGS)</span>
          </label>
          <label class="flex items-center p-3 border border-neutral-200 rounded-lg cursor-pointer hover:bg-neutral-50">
            <input v-model="form.paymentMethod" type="radio" value="credit_card" />
            <span class="ml-3">Credit Card</span>
          </label>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex gap-3">
        <router-link to="/offer/1" class="btn-secondary flex-1 text-center">Go Back</router-link>
        <button type="submit" class="btn-primary flex-1">Complete Purchase</button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const notificationStore = useNotificationStore()

const form = ref({
  streetAddress: '',
  city: '',
  state: '',
  postalCode: '',
  country: 'India',
  paymentMethod: 'upi',
})

const handleCheckout = async () => {
  try {
    notificationStore.addSuccess('Order Placed', 'Your order has been placed successfully')
    router.push('/orders')
  } catch (error) {
    notificationStore.addError('Checkout Failed', 'Please try again')
  }
}
</script>
