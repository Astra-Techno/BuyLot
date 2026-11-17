<template>
  <div class="page-container">
    <h1 class="section-title">Fulfillment Operations</h1>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Orders to Pack</p>
        <p class="text-3xl font-bold text-warning-600">45</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">In Transit</p>
        <p class="text-3xl font-bold text-primary-600">128</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Delivered Today</p>
        <p class="text-3xl font-bold text-success-600">234</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Fulfillment Rate</p>
        <p class="text-3xl font-bold text-primary-600">99.2%</p>
      </div>
    </div>

    <div class="card mb-6">
      <div class="flex gap-2 flex-wrap">
        <button v-for="status in statuses" :key="status" @click="selectedStatus = status" :class="[
          'px-4 py-2 rounded-lg font-medium transition-colors',
          selectedStatus === status
            ? 'bg-primary-600 text-white'
            : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'
        ]">
          {{ status }}
        </button>
      </div>
    </div>

    <div class="space-y-4">
      <div v-for="i in 8" :key="i" class="card hover:shadow-lg transition-shadow">
        <div class="flex items-start justify-between mb-3">
          <div>
            <h3 class="text-lg font-semibold">Shipment #SHIP-{{ 10000 + i }}</h3>
            <p class="text-muted">Order #ORD-{{ 5000 + i }} • {{ 50 * i }} units</p>
          </div>
          <StatusBadge :status="['picked_up', 'in_transit', 'out_for_delivery', 'delivered'][i % 4]" />
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4 pb-4 border-b border-neutral-200">
          <div>
            <p class="text-muted mb-1">Carrier</p>
            <p class="font-semibold">{{ ['FedEx', 'UPS', 'DHL', 'Local'][i % 4] }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Tracking</p>
            <p class="font-semibold text-primary-600 cursor-pointer">Track</p>
          </div>
          <div>
            <p class="text-muted mb-1">Expected</p>
            <p class="font-semibold">{{ 20 + i }} Nov 2024</p>
          </div>
          <div>
            <p class="text-muted mb-1">Destination</p>
            <p class="font-semibold">{{ ['Mumbai', 'Delhi', 'Bangalore', 'Pune'][i % 4] }}</p>
          </div>
        </div>

        <div class="flex gap-2">
          <button class="btn-secondary text-sm px-4 py-2">Update Status</button>
          <button class="btn-secondary text-sm px-4 py-2">Contact Carrier</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const statuses = ['All', 'Pending', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delivered']
const selectedStatus = ref('All')
</script>
