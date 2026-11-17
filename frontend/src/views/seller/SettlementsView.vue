<template>
  <div class="page-container">
    <h1 class="section-title">Settlements & Payouts</h1>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Total Earnings</p>
        <p class="text-3xl font-bold text-success-600">₹45.2L</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Pending Settlement</p>
        <p class="text-3xl font-bold text-warning-600">₹8.5L</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Platform Fees</p>
        <p class="text-3xl font-bold text-neutral-600">₹2.1L</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Last Payout</p>
        <p class="text-lg font-bold">₹12.5L</p>
        <p class="text-xs text-muted mt-1">Nov 15, 2024</p>
      </div>
    </div>

    <!-- Settlement History -->
    <div class="card">
      <h2 class="text-lg font-semibold mb-4">Settlement History</h2>

      <div class="space-y-3">
        <div v-for="i in 8" :key="i" class="flex items-start justify-between p-4 border border-neutral-200 rounded-lg hover:bg-neutral-50">
          <div class="flex-1">
            <p class="font-semibold">Settlement #SET-{{ 2000 + i }}</p>
            <p class="text-sm text-muted">Orders processed: {{ i * 5 }} • Period: Nov {{ 30 - (i * 2) }} - Nov {{ 30 - (i * 2) + 15 }}, 2024</p>
          </div>
          <div class="text-right">
            <div class="space-y-1 text-sm">
              <div>
                <p class="text-muted">Gross</p>
                <p class="font-semibold">₹{{ (500000 * i).toLocaleString('en-IN') }}</p>
              </div>
              <div>
                <p class="text-muted">Fees</p>
                <p class="font-semibold">-₹{{ (50000 * i).toLocaleString('en-IN') }}</p>
              </div>
              <div>
                <p class="text-muted">Net</p>
                <p class="font-semibold text-success-600">₹{{ (450000 * i).toLocaleString('en-IN') }}</p>
              </div>
            </div>
          </div>
          <div class="ml-4">
            <StatusBadge :status="i % 2 === 0 ? 'processed' : 'pending'" size="sm" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/common/StatusBadge.vue'
</script>
