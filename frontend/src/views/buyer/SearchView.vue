<template>
  <div class="page-container">
    <h1 class="section-title">Search & Browse Offers</h1>

    <div class="card mb-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input v-model="searchQuery" type="text" class="input-field col-span-1 md:col-span-2" placeholder="Search products..." />
        <select v-model="selectedCategory" class="input-field">
          <option value="">All Categories</option>
          <option value="raw-materials">Raw Materials</option>
          <option value="components">Components</option>
          <option value="services">Services</option>
        </select>
        <button @click="handleSearch" class="btn-primary">Search</button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="i in 6" :key="i" class="card hover:shadow-lg transition-shadow cursor-pointer">
        <div class="flex items-start justify-between mb-3">
          <div>
            <p class="font-semibold">Product Offer #{{ 5000 + i }}</p>
            <p class="text-sm text-muted">Supplier ID: {{ 1000 + i }}</p>
          </div>
          <StatusBadge status="active" size="sm" />
        </div>

        <div class="mb-4">
          <p class="text-2xl font-bold text-primary-600 mb-2">₹{{ (1000 * i).toLocaleString('en-IN') }}</p>
          <p class="text-sm text-muted">Quantity: {{ 100 * i }} units</p>
        </div>

        <button class="btn-primary w-full">View Details</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const searchQuery = ref('')
const selectedCategory = ref('')

const handleSearch = () => {
  console.log('Searching:', { query: searchQuery.value, category: selectedCategory.value })
}
</script>
