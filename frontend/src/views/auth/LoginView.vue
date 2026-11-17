<template>
  <div>
    <h2 class="text-2xl font-bold text-neutral-900 mb-1">Welcome to Collective Commerce</h2>
    <p class="text-neutral-600 mb-6">Enter your mobile number to get started</p>

    <form @submit.prevent="handleRequestOtp" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-neutral-900 mb-2">Mobile Number</label>
        <div class="flex">
          <span class="inline-flex items-center px-3 bg-neutral-100 border border-r-0 border-neutral-300 rounded-l-lg text-neutral-600 text-sm">+91</span>
          <input
            v-model="mobile"
            type="tel"
            inputmode="numeric"
            class="input-field w-full rounded-l-none"
            placeholder="9876543210"
            maxlength="10"
            pattern="[6-9][0-9]{9}"
            required
          />
        </div>
        <p v-if="error" class="text-red-600 text-sm mt-1">{{ error }}</p>
      </div>

      <button type="submit" :disabled="isLoading" class="btn-primary w-full">
        <span v-if="isLoading" class="inline-block animate-spin mr-2">&#8987;</span>
        {{ isLoading ? 'Sending OTP...' : 'Get OTP' }}
      </button>
    </form>

    <p v-if="devOtp" class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 text-center">
      Dev mode OTP: <strong class="text-lg tracking-widest">{{ devOtp }}</strong>
    </p>

    <div class="mt-6 text-center text-sm text-neutral-500">
      By continuing, you agree to our
      <a href="#" class="text-primary-600 hover:underline">Terms</a> and
      <a href="#" class="text-primary-600 hover:underline">Privacy Policy</a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const authStore = useAuthStore()
const notificationStore = useNotificationStore()

const mobile = ref('')
const isLoading = ref(false)
const error = ref('')
const devOtp = ref('')

const handleRequestOtp = async () => {
  error.value = ''
  devOtp.value = ''

  if (!/^[6-9]\d{9}$/.test(mobile.value)) {
    error.value = 'Enter a valid 10-digit Indian mobile number'
    return
  }

  isLoading.value = true
  try {
    const response = await authStore.requestOtp(mobile.value)
    const data = response.data ?? response

    // Show OTP in dev mode (backend returns it when APP_ENV != production)
    if (data.otp) {
      devOtp.value = data.otp
    }

    notificationStore.addSuccess('OTP Sent', `OTP sent to +91 ${mobile.value}`)
    router.push('/verify-otp')
  } catch (err: any) {
    const msg = err.response?.data?.data?.error || err.response?.data?.error || 'Failed to send OTP'
    error.value = msg
    notificationStore.addError('Error', msg)
  } finally {
    isLoading.value = false
  }
}
</script>
