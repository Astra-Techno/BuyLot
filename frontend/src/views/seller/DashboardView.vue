<template>
  <div class="page-container">
    <h1 class="section-title">Dashboard</h1>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
      <div class="card">
        <div class="text-sm text-muted mb-1">Active RFQs</div>
        <div class="text-3xl font-bold text-primary-600">24</div>
        <p class="text-xs text-muted mt-2">5 bids pending</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Total Orders</div>
        <div class="text-3xl font-bold text-success-600">156</div>
        <p class="text-xs text-muted mt-2">12 this month</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Revenue</div>
        <div class="text-3xl font-bold text-primary-600">₹45.2L</div>
        <p class="text-xs text-muted mt-2">This month</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Rating</div>
        <div class="text-3xl font-bold text-warning-600">4.8/5</div>
        <p class="text-xs text-muted mt-2">892 reviews</p>
      </div>
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Pending RFQs -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Recent RFQs</h2>
          <div class="space-y-3">
            <div v-for="i in 3" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg hover:bg-neutral-50">
              <div>
                <p class="font-medium">RFQ #{{ 5000 + i }}</p>
                <p class="text-sm text-muted">Raw Materials • Budget: ₹{{ (500000 * i).toLocaleString('en-IN') }}</p>
              </div>
              <StatusBadge status="active" size="sm" />
            </div>
          </div>
        </div>

        <!-- Recent Orders -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Recent Orders</h2>
          <div class="space-y-3">
            <div v-for="i in 3" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Order #ORD-{{ 10000 + i }}</p>
                <p class="text-sm text-muted">₹{{ (100000 * i).toLocaleString('en-IN') }}</p>
              </div>
              <StatusBadge :status="['processing', 'shipped', 'delivered'][i % 3]" size="sm" />
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Quick Actions</h3>
          <div class="space-y-2">
            <router-link to="/seller/rfqs" class="btn-primary w-full text-center">View All RFQs</router-link>
            <router-link to="/seller/orders" class="btn-secondary w-full text-center">View Orders</router-link>
            <button class="btn-secondary w-full">View Settlements</button>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Performance</h3>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <p class="text-muted">Response Rate</p>
              <p class="font-medium">98%</p>
            </div>
            <div class="flex justify-between">
              <p class="text-muted">Win Rate</p>
              <p class="font-medium">45%</p>
            </div>
            <div class="flex justify-between">
              <p class="text-muted">On-time Delivery</p>
              <p class="font-medium">99%</p>
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
