<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="section-title">Order #ORD-2024-{{ orderId }}</h1>
      <StatusBadge status="in_transit" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Order Details -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Order Details</h2>
          <table class="w-full text-sm">
            <tbody>
              <tr class="border-b border-neutral-200">
                <td class="py-3 text-muted">Order ID</td>
                <td class="py-3 font-semibold">ORD-2024-{{ orderId }}</td>
              </tr>
              <tr class="border-b border-neutral-200">
                <td class="py-3 text-muted">Date</td>
                <td class="py-3 font-semibold">Nov 10, 2024</td>
              </tr>
              <tr class="border-b border-neutral-200">
                <td class="py-3 text-muted">Supplier</td>
                <td class="py-3 font-semibold">Premium Supplies Ltd</td>
              </tr>
              <tr>
                <td class="py-3 text-muted">Amount</td>
                <td class="py-3 font-semibold text-primary-600 text-lg">₹75,750</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Items -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Items</h2>
          <div class="space-y-3">
            <div class="flex justify-between items-center p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Steel Coils</p>
                <p class="text-sm text-muted">500 kg @ ₹125/unit</p>
              </div>
              <p class="font-semibold">₹62,500</p>
            </div>
          </div>
        </div>

        <!-- Shipment -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Shipment Tracking</h2>
          <div class="space-y-4">
            <div class="flex items-start gap-4">
              <div class="w-10 h-10 rounded-full bg-success-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-success-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <div>
                <p class="font-semibold">Picked up</p>
                <p class="text-sm text-muted">Nov 11, 2024 at 10:30 AM</p>
              </div>
            </div>
            <div class="flex items-start gap-4">
              <div class="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-primary-600 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <div>
                <p class="font-semibold">In Transit</p>
                <p class="text-sm text-muted">Expected on Nov 20, 2024</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Order Summary</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <p class="text-muted">Subtotal</p>
              <p>₹62,500</p>
            </div>
            <div class="flex justify-between">
              <p class="text-muted">Tax (GST)</p>
              <p>₹11,250</p>
            </div>
            <div class="flex justify-between">
              <p class="text-muted">Shipping</p>
              <p>₹2,000</p>
            </div>
            <div class="flex justify-between border-t border-neutral-200 pt-2 font-semibold">
              <p>Total</p>
              <p class="text-primary-600">₹75,750</p>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Delivery Address</h3>
          <p class="text-sm">123 Business Park<br>Mumbai, MH 400001<br>India</p>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Need Help?</h3>
          <div class="space-y-2">
            <button class="w-full text-left text-sm text-primary-600 hover:text-primary-700 py-2">Track Shipment</button>
            <button class="w-full text-left text-sm text-primary-600 hover:text-primary-700 py-2">Contact Support</button>
            <button class="w-full text-left text-sm text-danger-600 hover:text-danger-700 py-2">Report Issue</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import StatusBadge from '@/components/common/StatusBadge.vue'

const route = useRoute()
const orderId = computed(() => route.params.id)
</script>
