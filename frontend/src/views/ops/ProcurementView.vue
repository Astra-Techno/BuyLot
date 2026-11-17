<template>
  <div class="page-container">
    <h1 class="section-title">Procurement Management</h1>

    <div class="card mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="searchQuery" type="text" class="input-field col-span-1 md:col-span-2" placeholder="Search procurement..." />
        <select v-model="selectedStatus" class="input-field">
          <option value="">All Status</option>
          <option value="active">Active RFQs</option>
          <option value="evaluation">Evaluation</option>
          <option value="awarded">Awarded</option>
        </select>
        <button @click="handleSearch" class="btn-primary">Search</button>
      </div>
    </div>

    <div class="space-y-4">
      <div v-for="i in 8" :key="i" class="card hover:shadow-lg transition-shadow">
        <div class="flex items-start justify-between mb-3">
          <div>
            <h3 class="text-lg font-semibold">RFQ #{{ 5000 + i }}</h3>
            <p class="text-muted">{{ ['Raw Materials', 'Components', 'Machinery', 'Services'][i % 4] }} • {{ i * 10 }} units</p>
          </div>
          <StatusBadge :status="['active', 'evaluation', 'awarded'][i % 3]" />
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4 pb-4 border-b border-neutral-200">
          <div>
            <p class="text-muted mb-1">Budget</p>
            <p class="font-semibold">₹{{ (500000 * i).toLocaleString('en-IN') }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Deadline</p>
            <p class="font-semibold">{{ 20 + i }} Nov 2024</p>
          </div>
          <div>
            <p class="text-muted mb-1">Bids</p>
            <p class="font-semibold">{{ i * 2 }} / {{ 50 + i * 5 }}</p>
          </div>
          <div>
            <p class="text-muted mb-1">Top Bidder</p>
            <p class="font-semibold">Supplier #{{ 1000 + i }}</p>
          </div>
        </div>

        <div class="flex gap-2">
          <router-link :to="`/ops/case/${i}`" class="btn-primary text-sm px-4 py-2">Review Bids</router-link>
          <button class="btn-secondary text-sm px-4 py-2">Add Notes</button>
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
  console.log('Searching procurement:', searchQuery.value)
}
</script>
