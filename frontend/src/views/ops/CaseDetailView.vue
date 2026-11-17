<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="section-title">RFQ Case #{{ caseId }}</h1>
      <StatusBadge status="evaluation" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- RFQ Overview -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">RFQ Details</h2>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted mb-1">Required Quantity</p>
              <p class="font-semibold">500 units</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Budget Range</p>
              <p class="font-semibold">₹50K - ₹75K</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Deadline</p>
              <p class="font-semibold">30 Nov 2024</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Bids Received</p>
              <p class="font-semibold">12 / 50</p>
            </div>
          </div>
        </div>

        <!-- Bids Comparison -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Top Bids Comparison</h2>
          <div class="space-y-3">
            <div v-for="i in 5" :key="i" class="p-4 border border-neutral-200 rounded-lg hover:bg-neutral-50">
              <div class="flex items-start justify-between mb-2">
                <div>
                  <p class="font-semibold">Supplier #{{ 1000 + i }}</p>
                  <p class="text-sm text-muted">Rating: 4.{{ 8 - i }}/5</p>
                </div>
                <span v-if="i === 1" class="px-2 py-1 bg-success-100 text-success-700 text-xs font-semibold rounded">Recommended</span>
              </div>

              <div class="grid grid-cols-3 gap-4 text-sm mb-2">
                <div>
                  <p class="text-muted">Unit Price</p>
                  <p class="font-semibold">₹{{ (120 + i * 5).toLocaleString('en-IN') }}/unit</p>
                </div>
                <div>
                  <p class="text-muted">Total</p>
                  <p class="font-semibold">₹{{ ((120 + i * 5) * 500).toLocaleString('en-IN') }}</p>
                </div>
                <div>
                  <p class="text-muted">Delivery</p>
                  <p class="font-semibold">{{ 15 - i }} days</p>
                </div>
              </div>

              <button class="text-sm text-primary-600 hover:text-primary-700 font-medium">View Full Bid</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Decision Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Make Decision</h3>
          <div class="space-y-2">
            <select class="input-field w-full">
              <option>Select Winner...</option>
              <option>Supplier #1001 - ₹60,000</option>
              <option>Supplier #1002 - ₹62,500</option>
              <option>Supplier #1003 - ₹65,000</option>
            </select>
            <button class="btn-primary w-full">Award Contract</button>
            <button class="btn-secondary w-full">Request More Bids</button>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Case Notes</h3>
          <textarea class="input-field w-full h-24 text-sm" placeholder="Add case notes..."></textarea>
          <button class="btn-secondary w-full mt-2 text-sm">Save Notes</button>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Compliance</h3>
          <div class="space-y-2 text-sm">
            <label class="flex items-center">
              <input type="checkbox" class="rounded" checked disabled />
              <span class="ml-2">Budget approved</span>
            </label>
            <label class="flex items-center">
              <input type="checkbox" class="rounded" />
              <span class="ml-2">Procurement verified</span>
            </label>
            <label class="flex items-center">
              <input type="checkbox" class="rounded" />
              <span class="ml-2">Compliance cleared</span>
            </label>
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
const caseId = computed(() => route.params.id)
</script>
