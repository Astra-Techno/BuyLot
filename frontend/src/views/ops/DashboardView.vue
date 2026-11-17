<template>
  <div class="page-container">
    <h1 class="section-title">Operations Dashboard</h1>

    <!-- KPIs -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
      <div class="card">
        <p class="text-sm text-muted mb-1">Total GMV</p>
        <p class="text-3xl font-bold text-primary-600">₹2.5Cr</p>
        <p class="text-xs text-success-600 mt-2">↑ 12% from last month</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Active Orders</p>
        <p class="text-3xl font-bold text-primary-600">1,234</p>
        <p class="text-xs text-warning-600 mt-2">245 awaiting fulfillment</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Disputes Open</p>
        <p class="text-3xl font-bold text-danger-600">18</p>
        <p class="text-xs text-muted mt-2">3 escalated</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Platform Health</p>
        <p class="text-3xl font-bold text-success-600">99.8%</p>
        <p class="text-xs text-success-600 mt-2">↑ 0.2% uptime</p>
      </div>
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Recent Activity -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Recent Activity</h2>
          <div class="space-y-3">
            <div v-for="i in 5" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">{{ ['Order Placed', 'Dispute Opened', 'Settlement Processed', 'RFQ Sent', 'Payment Received'][i % 5] }}</p>
                <p class="text-sm text-muted">{{ i }} minutes ago</p>
              </div>
              <StatusBadge :status="['active', 'pending', 'processed', 'submitted', 'completed'][i % 5]" size="sm" />
            </div>
          </div>
        </div>

        <!-- Fulfillment Status -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Fulfillment Pipeline</h2>
          <div class="space-y-3">
            <div>
              <div class="flex justify-between text-sm mb-1">
                <p>Confirmed</p>
                <p class="font-medium">342 / 500</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-primary-600 h-2 rounded-full" style="width: 68%"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-1">
                <p>Shipped</p>
                <p class="font-medium">187 / 300</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-success-600 h-2 rounded-full" style="width: 62%"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-1">
                <p>Delivered</p>
                <p class="font-medium">1,045 / 1,200</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-success-600 h-2 rounded-full" style="width: 87%"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Quick Actions</h3>
          <div class="space-y-2">
            <router-link to="/ops/procurement" class="btn-primary w-full text-center text-sm">Procurement</router-link>
            <router-link to="/ops/disputes" class="btn-secondary w-full text-center text-sm">Disputes</router-link>
            <router-link to="/ops/fulfillment" class="btn-secondary w-full text-center text-sm">Fulfillment</router-link>
            <router-link to="/ops/finance" class="btn-secondary w-full text-center text-sm">Finance</router-link>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">System Status</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between items-center">
              <p>API Health</p>
              <span class="w-2 h-2 rounded-full bg-success-600"></span>
            </div>
            <div class="flex justify-between items-center">
              <p>Database</p>
              <span class="w-2 h-2 rounded-full bg-success-600"></span>
            </div>
            <div class="flex justify-between items-center">
              <p>Payment Gateway</p>
              <span class="w-2 h-2 rounded-full bg-success-600"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/common/StatusBadge.vue'
</script>
