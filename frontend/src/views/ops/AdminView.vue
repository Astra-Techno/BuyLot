<template>
  <div class="page-container">
    <h1 class="section-title">Administration</h1>

    <!-- Admin Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Total Users</p>
        <p class="text-3xl font-bold text-primary-600">12,543</p>
        <p class="text-xs text-success-600 mt-2">↑ 342 this week</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Active Organizations</p>
        <p class="text-3xl font-bold text-primary-600">2,145</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">System Uptime</p>
        <p class="text-3xl font-bold text-success-600">99.98%</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Support Tickets</p>
        <p class="text-3xl font-bold text-warning-600">24</p>
        <p class="text-xs text-muted mt-2">3 awaiting response</p>
      </div>
    </div>

    <!-- Admin Tabs -->
    <div class="card mb-6">
      <div class="flex gap-2 flex-wrap border-b border-neutral-200 pb-4">
        <button v-for="tab in tabs" :key="tab" @click="selectedTab = tab" :class="[
          'px-4 py-2 font-medium transition-colors border-b-2',
          selectedTab === tab
            ? 'border-primary-600 text-primary-600'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'
        ]">
          {{ tab }}
        </button>
      </div>
    </div>

    <!-- Tab Content -->
    <div class="space-y-4">
      <!-- Users Management -->
      <template v-if="selectedTab === 'Users'">
        <div v-for="i in 5" :key="i" class="card">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-4 flex-1">
              <div class="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center font-semibold">
                {{ String.fromCharCode(64 + i) }}
              </div>
              <div>
                <p class="font-semibold">User #{{ 10000 + i }}</p>
                <p class="text-sm text-muted">user{{ i }}@company.com • {{ ['Buyer', 'Seller', 'Ops'][i % 3] }}</p>
              </div>
            </div>
            <div class="flex gap-2">
              <button class="text-sm px-3 py-1 bg-primary-50 text-primary-600 rounded hover:bg-primary-100">View</button>
              <button class="text-sm px-3 py-1 bg-danger-50 text-danger-600 rounded hover:bg-danger-100">Suspend</button>
            </div>
          </div>
        </div>
      </template>

      <!-- Organizations Management -->
      <template v-if="selectedTab === 'Organizations'">
        <div v-for="i in 5" :key="i" class="card">
          <div class="flex items-center justify-between">
            <div class="flex-1">
              <p class="font-semibold">Organization #{{ 1000 + i }}</p>
              <p class="text-sm text-muted">{{ 100 + i * 10 }} members • Revenue: ₹{{ (1000000 * i).toLocaleString('en-IN') }}</p>
              <StatusBadge :status="i % 2 === 0 ? 'verified' : 'pending'" size="sm" class="mt-2" />
            </div>
            <div class="flex gap-2">
              <button class="text-sm px-3 py-1 bg-success-50 text-success-600 rounded hover:bg-success-100">Approve</button>
              <button class="text-sm px-3 py-1 bg-danger-50 text-danger-600 rounded hover:bg-danger-100">Reject</button>
            </div>
          </div>
        </div>
      </template>

      <!-- System Settings -->
      <template v-if="selectedTab === 'Settings'">
        <div class="card">
          <h3 class="font-semibold mb-4">Platform Configuration</h3>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-900 mb-2">Platform Commission Fee (%)</label>
              <input type="number" class="input-field w-full max-w-xs" value="2.0" step="0.1" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-900 mb-2">Settlement Frequency</label>
              <select class="input-field w-full max-w-xs">
                <option>Daily</option>
                <option selected>Weekly</option>
                <option>Bi-weekly</option>
                <option>Monthly</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-900 mb-2">Max Dispute Resolution Time (days)</label>
              <input type="number" class="input-field w-full max-w-xs" value="30" />
            </div>
            <button class="btn-primary">Save Settings</button>
          </div>
        </div>
      </template>

      <!-- Logs & Monitoring -->
      <template v-if="selectedTab === 'Logs'">
        <div class="card">
          <h3 class="font-semibold mb-4">System Logs (Latest 10)</h3>
          <div class="space-y-2 font-mono text-xs">
            <div v-for="i in 10" :key="i" class="p-2 bg-neutral-100 rounded flex justify-between">
              <span>{{ String(i).padStart(4, '0') }} | 2024-11-17 10:{{ String(30 + i).padStart(2, '0') }}:{{ String(45 - i).padStart(2, '0') }}</span>
              <span>{{ ['INFO', 'WARN', 'ERROR'][i % 3] }}</span>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

const tabs = ['Users', 'Organizations', 'Settings', 'Logs']
const selectedTab = ref('Users')
</script>
