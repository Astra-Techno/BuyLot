<template>
  <div class="page-container">
    <h1 class="section-title">Financial Management</h1>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="card">
        <p class="text-sm text-muted mb-1">Platform Revenue</p>
        <p class="text-3xl font-bold text-primary-600">₹1.2Cr</p>
        <p class="text-xs text-success-600 mt-2">↑ 15% from last month</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Pending Settlements</p>
        <p class="text-3xl font-bold text-warning-600">₹85L</p>
        <p class="text-xs text-muted mt-2">42 pending payouts</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Transaction Volume</p>
        <p class="text-3xl font-bold text-primary-600">₹5.3Cr</p>
        <p class="text-xs text-muted mt-2">This month</p>
      </div>
      <div class="card">
        <p class="text-sm text-muted mb-1">Platform Fees Collected</p>
        <p class="text-3xl font-bold text-success-600">₹52L</p>
        <p class="text-xs text-muted mt-2">2% average</p>
      </div>
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 space-y-6">
        <!-- Revenue Breakdown -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Revenue Breakdown</h2>
          <div class="space-y-3">
            <div>
              <div class="flex justify-between text-sm mb-2">
                <p>Commission Fees (2%)</p>
                <p class="font-semibold">₹52L</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-primary-600 h-2 rounded-full" style="width: 65%"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2">
                <p>Settlement Fees</p>
                <p class="font-semibold">₹18L</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-primary-600 h-2 rounded-full" style="width: 22%"></div>
              </div>
            </div>
            <div>
              <div class="flex justify-between text-sm mb-2">
                <p>Dispute Resolution Fees</p>
                <p class="font-semibold">₹8L</p>
              </div>
              <div class="w-full bg-neutral-200 rounded-full h-2">
                <div class="bg-primary-600 h-2 rounded-full" style="width: 10%"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Payment Status -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Payment Processing</h2>
          <div class="space-y-3">
            <div class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Successful Transactions</p>
                <p class="text-sm text-muted">99.2% success rate</p>
              </div>
              <p class="font-semibold text-success-600">45,230</p>
            </div>
            <div class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Failed/Disputed</p>
                <p class="text-sm text-muted">0.8% failure rate</p>
              </div>
              <p class="font-semibold text-danger-600">365</p>
            </div>
            <div class="flex items-center justify-between p-3 border border-neutral-200 rounded-lg">
              <div>
                <p class="font-medium">Refunds Processed</p>
                <p class="text-sm text-muted">Average refund time: 2 days</p>
              </div>
              <p class="font-semibold">₹8.5L</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions Sidebar -->
      <div class="space-y-4">
        <div class="card">
          <h3 class="font-semibold mb-4">Financial Actions</h3>
          <div class="space-y-2">
            <button class="btn-primary w-full text-sm">Process Settlements</button>
            <button class="btn-secondary w-full text-sm">Generate Reports</button>
            <button class="btn-secondary w-full text-sm">Export Data</button>
          </div>
        </div>

        <div class="card">
          <h3 class="font-semibold mb-3">Recent Transactions</h3>
          <div class="space-y-2 text-sm">
            <div v-for="i in 5" :key="i" class="flex justify-between pb-2 border-b border-neutral-200 last:border-0">
              <p class="text-muted">TXN-{{ 500000 + i }}</p>
              <p class="font-semibold">₹{{ (10000 * i).toLocaleString('en-IN') }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Financial dashboard - view only
</script>
