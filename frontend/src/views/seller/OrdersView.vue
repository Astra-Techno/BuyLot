<template>
  <div class="page-container">
    <h1 class="section-title">My Orders</h1>

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
      <div v-for="i in 6" :key="i" class="card hover:shadow-lg transition-shadow">
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <h3 class="text-lg font-semibold mb-1">Order #ORD-{{ 10000 + i }}</h3>
            <p class="text-muted text-sm mb-3">Customer ID: CUST-{{ 2000 + i }}</p>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <p class="text-muted">Amount</p>
                <p class="font-semibold">₹{{ (100000 * i).toLocaleString('en-IN') }}</p>
              </div>
              <div>
                <p class="text-muted">Date</p>
                <p class="font-semibold">Nov {{ 20 - i }}, 2024</p>
              </div>
              <div>
                <p class="text-muted">Items</p>
                <p class="font-semibold">{{ i * 50 }} units</p>
              </div>
              <div>
                <p class="text-muted">Status</p>
                <StatusBadge :status="['processing', 'shipped', 'delivered'][i % 3]" size="sm" />
              </div>
            </div>
          </div>
          <button class="ml-4 px-4 py-2 bg-primary-50 text-primary-600 rounded-lg hover:bg-primary-100 transition-colors whitespace-nowrap">
            View Details
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const statuses = ['All', 'Processing', 'Shipped', 'Delivered']
const selectedStatus = ref('All')
</script>
