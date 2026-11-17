<template>
  <div>
    <h2 class="text-2xl font-bold text-neutral-900 mb-1">Verify OTP</h2>
    <p class="text-neutral-600 mb-6">
      Enter the 6-digit code sent to
      <strong>+91 {{ authStore.pendingMobile }}</strong>
    </p>

    <form @submit.prevent="handleVerify" class="space-y-4">
      <div>
        <label class="block text-sm font-medium text-neutral-900 mb-2">OTP Code</label>
        <input
          v-model="otpCode"
          type="text"
          inputmode="numeric"
          class="input-field w-full text-center text-2xl tracking-[0.5em] font-mono"
          placeholder="000000"
          maxlength="6"
          pattern="\d{6}"
          autofocus
          required
        />
      </div>

      <p v-if="error" class="text-red-600 text-sm">{{ error }}</p>

      <div v-if="authStore.devOtp" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-center">
        <p class="text-xs text-amber-600 mb-1">Dev mode — your OTP is:</p>
        <p class="text-2xl font-mono font-bold tracking-[0.3em] text-amber-800">{{ authStore.devOtp }}</p>
      </div>

      <button type="submit" :disabled="isLoading || otpCode.length !== 6" class="btn-primary w-full">
        {{ isLoading ? 'Verifying...' : 'Verify & Sign In' }}
      </button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm text-neutral-600">
      <router-link to="/login" class="text-primary-600 hover:underline">&larr; Change number</router-link>
      <button
        @click="resendOtp"
        :disabled="resendCooldown > 0 || isResending"
        class="text-primary-600 hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
      >
        {{ resendCooldown > 0 ? `Resend in ${resendCooldown}s` : 'Resend OTP' }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const authStore = useAuthStore()
const notificationStore = useNotificationStore()

const otpCode = ref('')
const isLoading = ref(false)
const isResending = ref(false)
const error = ref('')
const resendCooldown = ref(30)
let cooldownTimer: ReturnType<typeof setInterval>

onMounted(() => {
  if (!authStore.pendingMobile) {
    router.replace('/login')
    return
  }
  startCooldown()
})

onUnmounted(() => {
  if (cooldownTimer) clearInterval(cooldownTimer)
})

const startCooldown = () => {
  resendCooldown.value = 30
  cooldownTimer = setInterval(() => {
    resendCooldown.value--
    if (resendCooldown.value <= 0) clearInterval(cooldownTimer)
  }, 1000)
}

const handleVerify = async () => {
  error.value = ''
  if (!/^\d{6}$/.test(otpCode.value)) {
    error.value = 'Enter a valid 6-digit OTP'
    return
  }

  isLoading.value = true
  try {
    await authStore.verifyOtp(otpCode.value)
    notificationStore.addSuccess('Welcome!', 'You are now signed in')
    router.push('/')
  } catch (err: any) {
    const msg = err.response?.data?.data?.error || err.response?.data?.error || 'Invalid or expired OTP'
    error.value = msg
    otpCode.value = ''
  } finally {
    isLoading.value = false
  }
}

const resendOtp = async () => {
  isResending.value = true
  try {
    await authStore.requestOtp(authStore.pendingMobile)
    notificationStore.addInfo('OTP Sent', 'A new OTP has been sent')
    startCooldown()
  } catch {
    notificationStore.addError('Error', 'Failed to resend OTP')
  } finally {
    isResending.value = false
  }
}
</script>
