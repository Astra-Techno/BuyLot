<template>
  <div class="page-container">
    <h1 class="section-title">RFQs</h1>

    <div class="card mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="searchQuery" type="text" class="input-field col-span-1 md:col-span-2" placeholder="Search RFQs..." />
        <select v-model="selectedStatus" class="input-field">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="bidding">Bidding</option>
          <option value="closed">Closed</option>
        </select>
        <button @click="handleSearch" class="btn-primary">Search</button>
      </div>
    </div>

    <div class="space-y-4">
      <div v-for="i in 8" :key="i" class="card hover:shadow-lg transition-shadow cursor-pointer">
        <div class="flex items-start justify-between mb-3">
          <div>
            <h3 class="text-lg font-semibold">RFQ #{{ 5000 + i }}</h3>
            <p class="text-muted">Posted {{ i }} days ago</p>
          </div>
          <StatusBadge status="active" />
        </div>

        <p class="text-muted mb-4">{{ ['Raw Materials', 'Components', 'Machinery', 'Chemicals'][i % 4] }} • Quantity: {{ 100 * i }} units</p>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
          <div>
            <p class="text-muted mb-1">Budget</p>
            <p class="font-semibold">₹{{ (500000 * i).toLocaleString('en-IN') }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Deadline</p>
            <p class="font-semibold">{{ 20 + i }} Nov 2024</p>
          </div>
          <div>
            <p class="text-muted mb-1">Bids Received</p>
            <p class="font-semibold">{{ i * 2 }} / {{ 50 + i * 5 }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Your Bid</p>
            <p class="font-semibold text-primary-600" v-if="i % 3 === 0">Submitted</p>
            <p class="font-semibold text-warning-600" v-else>Not Yet</p>
          </div>
        </div>

        <div class="flex gap-2">
          <router-link :to="`/seller/rfq/${i}/bid`" class="px-4 py-2 bg-primary-50 text-primary-600 rounded-lg hover:bg-primary-100 transition-colors font-medium">
            {{ i % 3 === 0 ? 'Edit Bid' : 'Submit Bid' }}
          </router-link>
          <button class="px-4 py-2 bg-neutral-100 text-neutral-700 rounded-lg hover:bg-neutral-200 transition-colors">Details</button>
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
  console.log('Searching:', { query: searchQuery.value, status: selectedStatus.value })
}
</script>
