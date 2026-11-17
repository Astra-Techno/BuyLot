<template>
  <div class="page-container">
    <h1 class="section-title">Active Offers</h1>

    <div class="card mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="searchQuery" type="text" class="input-field col-span-1 md:col-span-2" placeholder="Search offers..." />
        <select v-model="selectedStatus" class="input-field">
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="expired">Expired</option>
        </select>
        <button @click="handleSearch" class="btn-primary">Search</button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="i in 9" :key="i" class="card hover:shadow-lg transition-shadow">
        <div class="flex items-start justify-between mb-3">
          <div>
            <p class="font-semibold">Offer #{{ 3000 + i }}</p>
            <p class="text-sm text-muted">Supplier ID: SUPP-{{ 1000 + i }}</p>
          </div>
          <StatusBadge :status="['pending', 'accepted', 'expired'][i % 3]" size="sm" />
        </div>

        <div class="mb-4 pb-4 border-b border-neutral-200">
          <p class="text-2xl font-bold text-primary-600">₹{{ (100000 * i).toLocaleString('en-IN') }}</p>
          <p class="text-sm text-muted">{{ 100 * i }} units</p>
        </div>

        <div class="text-sm space-y-2 mb-4">
          <p><strong>Buyer:</strong> Company #{{ 2000 + i }}</p>
          <p><strong>Deadline:</strong> {{ 25 + i }} Nov 2024</p>
          <p><strong>Validity:</strong> {{ 48 - (i * 2) }} hours</p>
        </div>

        <div class="flex gap-2">
          <button class="btn-primary flex-1 text-sm">Review</button>
          <button class="btn-secondary flex-1 text-sm">Details</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const searchQuery = ref('')
const selectedStatus = ref('')

const handleSearch = () => {
  console.log('Searching offers:', searchQuery.value)
}
</script>
