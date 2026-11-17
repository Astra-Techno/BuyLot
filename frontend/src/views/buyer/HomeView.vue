<template>
  <div class="page-container">
    <div class="mb-8">
      <h1 class="section-title">Welcome back, {{ userName }}</h1>
      <p class="text-muted">Here's what's happening with your procurement</p>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
      <div class="card">
        <div class="text-sm text-muted mb-1">Active Requests</div>
        <div class="text-3xl font-bold text-primary-600">12</div>
        <p class="text-xs text-muted mt-2">3 awaiting bids</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Pending Orders</div>
        <div class="text-3xl font-bold text-warning-600">8</div>
        <p class="text-xs text-muted mt-2">2 in transit</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Total Spent</div>
        <div class="text-3xl font-bold text-success-600">₹52.3L</div>
        <p class="text-xs text-muted mt-2">This month</p>
      </div>
      <div class="card">
        <div class="text-sm text-muted mb-1">Demand Groups</div>
        <div class="text-3xl font-bold text-primary-600">5</div>
        <p class="text-xs text-muted mt-2">2 active</p>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2">
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Recent Requests</h2>
          <div class="space-y-3">
            <div v-for="i in 3" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg hover:bg-neutral-50 cursor-pointer">
              <div>
                <p class="font-medium">Raw Materials Request #{{ 1000 + i }}</p>
                <p class="text-sm text-muted">Posted 2 days ago</p>
              </div>
              <StatusBadge status="active" />
            </div>
          </div>
        </div>
      </div>

      <div>
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Quick Actions</h2>
          <div class="space-y-2">
            <router-link to="/request/new" class="btn-primary w-full text-center">Create Request</router-link>
            <router-link to="/search" class="btn-secondary w-full text-center">Browse Offers</router-link>
            <router-link to="/orders" class="btn-secondary w-full text-center">View Orders</router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import StatusBadge from '@/components/common/StatusBadge.vue'

const authStore = useAuthStore()

const userName = computed(() => authStore.user?.name.split(' ')[0] || 'User')
</script>
