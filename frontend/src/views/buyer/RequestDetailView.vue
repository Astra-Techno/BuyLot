<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="section-title">Request #{{ requestId }}</h1>
        <p class="text-muted">Steel Coils - 500 kg</p>
      </div>
      <StatusBadge status="active" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Details Card -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Request Details</h2>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-muted mb-1">Quantity</p>
              <p class="font-semibold">500 kg</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Unit Price Range</p>
              <p class="font-semibold">₹100 - ₹150 per kg</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Total Budget</p>
              <p class="font-semibold">₹50,000 - ₹75,000</p>
            </div>
            <div>
              <p class="text-sm text-muted mb-1">Required By</p>
              <p class="font-semibold">30 Nov 2024</p>
            </div>
          </div>
        </div>

        <!-- Description -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Description</h2>
          <p class="text-muted">High-quality cold-rolled steel coils suitable for automotive and construction applications. Must meet ISO 9001 standards.</p>
        </div>

        <!-- Bids -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Received Bids (5)</h2>
          <div class="space-y-3">
            <div v-for="i in 3" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Supplier #{{ 1000 + i }}</p>
                <p class="text-sm text-muted">₹{{ (120 + i * 5).toLocaleString('en-IN') }}/unit</p>
              </div>
              <StatusBadge status="submitted" size="sm" />
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Actions</h3>
          <div class="space-y-2">
            <button class="btn-primary w-full">Create Demand Group</button>
            <button class="btn-secondary w-full">Share Request</button>
            <button class="btn-danger w-full">Close Request</button>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Status Timeline</h3>
          <div class="space-y-3 text-sm">
            <div class="flex gap-3">
              <div class="w-3 h-3 rounded-full bg-success-600 mt-1"></div>
              <div>
                <p class="font-medium">Request Created</p>
                <p class="text-muted">Nov 10, 2024</p>
              </div>
            </div>
            <div class="flex gap-3">
              <div class="w-3 h-3 rounded-full bg-primary-600 mt-1"></div>
              <div>
                <p class="font-medium">Bids Received</p>
                <p class="text-muted">Currently active</p>
              </div>
            </div>
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
const requestId = computed(() => route.params.id)
</script>
