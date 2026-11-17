<template>
  <div class="page-container max-w-4xl">
    <h1 class="section-title">My Profile</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Profile Sidebar -->
      <div class="lg:col-span-1">
        <div class="card text-center">
          <div class="w-24 h-24 rounded-full bg-primary-500 flex items-center justify-center text-white text-4xl font-bold mx-auto mb-4">
            {{ getInitials() }}
          </div>
          <h2 class="text-lg font-bold">{{ user?.name }}</h2>
          <p class="text-sm text-muted">{{ user?.email }}</p>
          <div class="flex gap-2 mt-4">
            <button class="btn-primary flex-1">Edit Profile</button>
          </div>
        </div>
      </div>

      <!-- Profile Details -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Personal Information -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Personal Information</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm text-muted mb-1">Full Name</label>
              <input v-model="form.name" type="text" class="input-field w-full" />
            </div>
            <div>
              <label class="block text-sm text-muted mb-1">Email</label>
              <input v-model="form.email" type="email" class="input-field w-full" disabled />
            </div>
            <div>
              <label class="block text-sm text-muted mb-1">Phone</label>
              <input v-model="form.phone" type="tel" class="input-field w-full" />
            </div>
            <div>
              <label class="block text-sm text-muted mb-1">Organization</label>
              <input v-model="form.organization" type="text" class="input-field w-full" disabled />
            </div>
          </div>
        </div>

        <!-- Organization Information -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Organization Details</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm text-muted mb-1">GST Number</label>
              <input v-model="form.gstNumber" type="text" class="input-field w-full" />
            </div>
            <div>
              <label class="block text-sm text-muted mb-1">PAN Number</label>
              <input v-model="form.panNumber" type="text" class="input-field w-full" />
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm text-muted mb-1">Address</label>
              <input v-model="form.address" type="text" class="input-field w-full" />
            </div>
          </div>
        </div>

        <!-- Settings -->
        <div class="card">
          <h2 class="text-lg font-semibold mb-4">Account Settings</h2>
          <div class="space-y-3">
            <label class="flex items-center">
              <input type="checkbox" class="rounded" checked />
              <span class="ml-3 text-sm">Receive email notifications</span>
            </label>
            <label class="flex items-center">
              <input type="checkbox" class="rounded" checked />
              <span class="ml-3 text-sm">Receive SMS alerts</span>
            </label>
          </div>
        </div>

        <!-- Save Button -->
        <div class="flex gap-3">
          <button class="btn-secondary flex-1">Cancel</button>
          <button @click="handleSave" class="btn-primary flex-1">Save Changes</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'

const authStore = useAuthStore()
const notificationStore = useNotificationStore()

const user = computed(() => authStore.user)

const form = ref({
  name: authStore.user?.name || '',
  email: authStore.user?.email || '',
  phone: authStore.user?.phone || '',
  organization: 'ABC Corporation',
  gstNumber: '27AABCT1234H1Z0',
  panNumber: 'AABCT1234H',
  address: '123 Business Park, Mumbai, MH 400001',
})

const getInitials = () => {
  if (!user.value) return 'U'
  const parts = user.value.name.split(' ')
  return parts.map((p) => p[0]).join('').toUpperCase()
}

const handleSave = () => {
  notificationStore.addSuccess('Profile Updated', 'Your profile has been updated successfully')
}
</script>
