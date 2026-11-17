<template>
  <div class="page-container">
    <h1 class="section-title">Dispute Management</h1>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Open Disputes</p>
        <p class="text-3xl font-bold text-danger-600">18</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">In Resolution</p>
        <p class="text-3xl font-bold text-warning-600">8</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Resolved This Month</p>
        <p class="text-3xl font-bold text-success-600">42</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Avg Resolution Time</p>
        <p class="text-3xl font-bold">2.5</p>
        <p class="text-xs text-muted">days</p>
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
            <h3 class="text-lg font-semibold">Dispute #DIS-{{ 4000 + i }}</h3>
            <p class="text-muted">Order #ORD-{{ 5000 + i }} • Opened {{ i }} days ago</p>
          </div>
          <StatusBadge :status="['open', 'in_resolution', 'resolved'][i % 3]" />
        </div>

        <div class="mb-4 pb-4 border-b border-neutral-200">
          <p class="font-semibold mb-1">{{ ['Quality Issue', 'Quantity Mismatch', 'Late Delivery', 'Damaged Product'][i % 4] }}</p>
          <p class="text-sm text-muted">Initiated by {{ ['Buyer', 'Seller'][i % 2] }}</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm mb-4">
          <div>
            <p class="text-muted mb-1">Buyer</p>
            <p class="font-semibold">Company #{{ 2000 + i }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Seller</p>
            <p class="font-semibold">Supplier #{{ 1000 + i }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Amount at Risk</p>
            <p class="font-semibold text-danger-600">₹{{ (100000 * i).toLocaleString('en-IN') }}</p>
          </div>
        </div>

        <div class="flex gap-2">
          <button class="btn-primary text-sm px-4 py-2">Investigate</button>
          <button class="btn-secondary text-sm px-4 py-2">Message Parties</button>
          <button class="btn-secondary text-sm px-4 py-2">View Evidence</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const statuses = ['All', 'Open', 'In Resolution', 'Escalated', 'Resolved']
const selectedStatus = ref('All')
</script>
