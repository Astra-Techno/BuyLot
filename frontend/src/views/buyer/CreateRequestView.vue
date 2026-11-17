<template>
  <div class="page-container max-w-2xl">
    <h1 class="section-title">Create Buy Request</h1>

    <form @submit.prevent="handleSubmit" class="card space-y-6">
      <!-- Product Information -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Product Information</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Product Name</label>
            <input v-model="form.productName" type="text" class="input-field w-full" placeholder="e.g., Steel Coils" required />
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Description</label>
            <textarea v-model="form.description" class="input-field w-full h-24" placeholder="Detailed description..." required></textarea>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-neutral-900 mb-2">Quantity</label>
              <input v-model.number="form.quantity" type="number" class="input-field w-full" placeholder="0" required />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-900 mb-2">Unit</label>
              <select v-model="form.unit" class="input-field w-full" required>
                <option value="">Select unit</option>
                <option value="kg">Kilograms (kg)</option>
                <option value="pcs">Pieces (pcs)</option>
                <option value="liters">Liters</option>
                <option value="meters">Meters</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Budget Information -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Budget</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Budget Min (₹)</label>
            <input v-model.number="form.budgetMin" type="number" class="input-field w-full" placeholder="0" required />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Budget Max (₹)</label>
            <input v-model.number="form.budgetMax" type="number" class="input-field w-full" placeholder="0" required />
          </div>
        </div>
      </div>

      <!-- Delivery -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Delivery</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Required By Date</label>
            <input v-model="form.requiredByDate" type="date" class="input-field w-full" required />
          </div>
        </div>
      </div>

      <!-- Form Actions -->
      <div class="flex gap-3 pt-6 border-t border-neutral-200">
        <router-link to="/" class="btn-secondary flex-1 text-center">Cancel</router-link>
        <button type="submit" class="btn-primary flex-1">Create Request</button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const notificationStore = useNotificationStore()

const form = ref({
  productName: '',
  description: '',
  quantity: 0,
  unit: '',
  budgetMin: 0,
  budgetMax: 0,
  requiredByDate: '',
})

const handleSubmit = async () => {
  try {
    notificationStore.addSuccess('Request Created', 'Your buy request has been created successfully')
    router.push('/')
  } catch (error) {
    notificationStore.addError('Error', 'Failed to create request')
  }
}
</script>
