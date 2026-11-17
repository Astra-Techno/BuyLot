<template>
  <div class="page-container">
    <h1 class="section-title">Deals & Promotions</h1>

    <div class="card mb-6 bg-primary-50 border border-primary-200">
      <button class="btn-primary">Create New Deal</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div v-for="i in 6" :key="i" class="card hover:shadow-lg transition-shadow">
        <div class="flex items-start justify-between mb-3">
          <div>
            <h3 class="font-semibold">{{ ['Bulk Discount', 'Seasonal Offer', 'Bundle Deal', 'Volume Discount', 'Loyalty Reward', 'Early Bird Deal'][i % 6] }}</h3>
            <p class="text-sm text-muted">Deal #DEAL-{{ 1000 + i }}</p>
          </div>
          <StatusBadge :status="i % 2 === 0 ? 'active' : 'draft'" size="sm" />
        </div>

        <div class="mb-4 p-3 bg-neutral-100 rounded-lg">
          <p class="text-2xl font-bold text-primary-600 mb-1">{{ 10 + i * 5 }}% OFF</p>
          <p class="text-xs text-muted">On orders above ₹{{ (100000 * i).toLocaleString('en-IN') }}</p>
        </div>

        <div class="text-sm space-y-1 mb-4 pb-4 border-b border-neutral-200">
          <p><strong>Validity:</strong> {{ 30 - i }} Nov - {{ 30 + i }} Dec 2024</p>
          <p><strong>Redemptions:</strong> {{ i * 15 }} / {{ 500 + i * 50 }}</p>
        </div>

        <div class="flex gap-2">
          <button class="btn-secondary flex-1 text-sm">Edit</button>
          <button class="btn-secondary flex-1 text-sm">Analytics</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/common/StatusBadge.vue'
</script>
