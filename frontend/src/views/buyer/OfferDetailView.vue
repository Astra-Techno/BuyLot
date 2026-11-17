<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="section-title">Offer Details</h1>
      <StatusBadge status="pending" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Offer Info -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Offer Information</h2>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted mb-1">Supplier</p>
              <p class="font-semibold">Premium Supplies Ltd</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Rating</p>
              <p class="font-semibold">4.8/5 (127 reviews)</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Quantity</p>
              <p class="font-semibold">500 kg</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Unit Price</p>
              <p class="font-semibold text-primary-600">₹125/unit</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Delivery</p>
              <p class="font-semibold">15 days</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Total Amount</p>
              <p class="font-semibold text-primary-600 text-lg">₹62,500</p>
            </div>
          </div>
        </div>

        <!-- Terms -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Terms & Conditions</h2>
          <div class="space-y-2 text-sm">
            <p><strong>Payment Terms:</strong> 30% advance, 70% on delivery</p>
            <p><strong>Quality Guarantee:</strong> ISO 9001 certified</p>
            <p><strong>Warranty:</strong> 6 months from delivery</p>
            <p><strong>Cancellation:</strong> Free within 48 hours</p>
          </div>
        </div>

        <!-- Description -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Description</h2>
          <p class="text-muted">Premium quality steel coils suitable for all industrial applications. Tested and certified to international standards.</p>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-3 text-lg">₹62,500</h3>
          <p class="text-sm text-muted mb-4">Including all taxes and shipping</p>
          <router-link to="/checkout/1" class="btn-primary w-full text-center mb-2">Proceed to Checkout</router-link>
          <button class="btn-secondary w-full">Save for Later</button>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Supplier Info</h3>
          <div class="space-y-2 text-sm">
            <p><strong>ID:</strong> SUPP-2024-001</p>
            <p><strong>Years Active:</strong> 15</p>
            <p><strong>Orders Completed:</strong> 2,543</p>
            <p><strong>Fulfillment Rate:</strong> 99.8%</p>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Validity</h3>
          <p class="text-sm text-muted">Expires in 48 hours</p>
          <div class="mt-3 w-full bg-neutral-200 rounded-full h-2">
            <div class="bg-primary-600 h-2 rounded-full" style="width: 65%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/common/StatusBadge.vue'
</script>
