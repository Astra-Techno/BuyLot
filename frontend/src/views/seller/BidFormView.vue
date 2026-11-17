<template>
  <div class="page-container max-w-2xl">
    <h1 class="section-title">Submit Bid for RFQ #{{ rfqId }}</h1>

    <div class="card mb-6 bg-primary-50 border border-primary-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
        <div>
          <p class="text-primary-600">Required Quantity</p>
          <p class="font-semibold text-neutral-900">500 units</p>
        </div>
        <div>
          <p class="text-primary-600">Budget Range</p>
          <p class="font-semibold text-neutral-900">₹50K - ₹75K</p>
        </div>
        <div>
          <p class="text-primary-600">Deadline</p>
          <p class="font-semibold text-neutral-900">30 Nov 2024</p>
        </div>
        <div>
          <p class="text-primary-600">Delivery Days</p>
          <p class="font-semibold text-neutral-900">15-20 days</p>
        </div>
      </div>
    </div>

    <form @submit.prevent="handleSubmit" class="card space-y-6">
      <!-- Pricing -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Pricing</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Unit Price (₹)</label>
            <input v-model.number="form.unitPrice" type="number" class="input-field w-full" placeholder="0" step="0.01" required />
            <p class="text-xs text-muted mt-2">Total: ₹{{ (form.unitPrice * 500).toLocaleString('en-IN') }}</p>
          </div>
        </div>
      </div>

      <!-- Delivery -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Delivery</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-900 mb-2">Delivery Days</label>
            <input v-model.number="form.deliveryDays" type="number" class="input-field w-full" placeholder="0" required />
          </div>
        </div>
      </div>

      <!-- Payment Terms -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Payment Terms</h2>
        <div class="space-y-3">
          <label v-for="term in paymentTerms" :key="term" class="flex items-center p-3 border border-neutral-200 rounded-lg cursor-pointer hover:bg-neutral-50">
            <input v-model="form.paymentTerms" type="radio" :value="term" required />
            <span class="ml-3">{{ term }}</span>
          </label>
        </div>
      </div>

      <!-- Certifications -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Quality Certifications</h2>
        <div class="space-y-2">
          <label v-for="cert in certifications" :key="cert" class="flex items-center">
            <input v-model="form.certifications" type="checkbox" :value="cert" />
            <span class="ml-2 text-sm">{{ cert }}</span>
          </label>
        </div>
      </div>

      <!-- Notes -->
      <div>
        <h2 class="text-lg font-semibold mb-4">Additional Notes</h2>
        <textarea v-model="form.notes" class="input-field w-full h-20" placeholder="Add any special offers or notes..."></textarea>
      </div>

      <!-- Form Actions -->
      <div class="flex gap-3 pt-6 border-t border-neutral-200">
        <router-link to="/seller/rfqs" class="btn-secondary flex-1 text-center">Cancel</router-link>
        <button type="submit" class="btn-primary flex-1">Submit Bid</button>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const route = useRoute()
const notificationStore = useNotificationStore()

const rfqId = computed(() => route.params.id)

const paymentTerms = ['30% advance, 70% on delivery', '50% advance, 50% on delivery', '100% advance', '100% on delivery']
const certifications = ['ISO 9001', 'ISO 14001', 'OHSAS 18001', 'BIS', 'FSSAI']

const form = ref({
  unitPrice: 0,
  deliveryDays: 15,
  paymentTerms: '30% advance, 70% on delivery',
  certifications: [],
  notes: '',
})

const handleSubmit = async () => {
  try {
    notificationStore.addSuccess('Bid Submitted', 'Your bid has been submitted successfully')
    router.push('/seller/rfqs')
  } catch (error) {
    notificationStore.addError('Error', 'Failed to submit bid')
  }
}
</script>
