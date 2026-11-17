<template>
  <div class="page-container">
    <div class="flex items-center justify-between mb-6">
      <h1 class="section-title">Demand Group #{{ groupId }}</h1>
      <StatusBadge status="active" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Members</p>
        <p class="text-3xl font-bold text-primary-600">12</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Total Budget</p>
        <p class="text-3xl font-bold text-primary-600">₹5.2L</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Deadline</p>
        <p class="text-lg font-bold">30 Nov 2024</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Members -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Group Members</h2>
          <div class="space-y-2">
            <div v-for="i in 5" :key="i" class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Company #{{ 1000 + i }}</p>
                <p class="text-sm text-muted">Joined 2 days ago</p>
              </div>
              <span class="text-sm font-medium text-primary-600">₹{{ (100000 * i).toLocaleString('en-IN') }}</span>
            </div>
          </div>
        </div>

        <!-- Requests -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Requests in Group (3)</h2>
          <div class="space-y-2">
            <div v-for="i in 3" :key="i" class="p-3 border border-neutral-200 rounded-lg">
              <p class="font-medium">Request #{{ 1000 + i }}</p>
              <p class="text-sm text-muted">500 units • ₹50K - ₹75K</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Actions</h3>
          <div class="space-y-2">
            <button class="btn-primary w-full">Send RFQ</button>
            <button class="btn-secondary w-full">Add Members</button>
            <button class="btn-secondary w-full">View Offers</button>
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
const groupId = computed(() => route.params.id)
</script>
