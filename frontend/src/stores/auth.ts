import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import apiClient from '@/api/client'
import type { User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string>('')
  const pendingMobile = ref<string>(sessionStorage.getItem('pendingMobile') ?? '')
  const devOtp = ref<string>(sessionStorage.getItem('devOtp') ?? '')
  const isAuthenticated = computed(() => !!token.value && !!user.value)

  const initializeAuth = () => {
    const storedToken = localStorage.getItem('token')
    const storedUser = localStorage.getItem('user')

    if (storedToken && storedUser) {
      token.value = storedToken
      try {
        user.value = JSON.parse(storedUser)
      } catch {
        logout()
      }
    }
  }

  const requestOtp = async (mobile: string) => {
    const response = await apiClient.post('/auth/otp/request', { mobile })
    pendingMobile.value = mobile
    sessionStorage.setItem('pendingMobile', mobile)

    const data = response.data.data ?? response.data
    devOtp.value = data.otp ?? ''
    if (data.otp) sessionStorage.setItem('devOtp', data.otp)

    return response.data
  }

  const verifyOtp = async (otp: string) => {
    const response = await apiClient.post('/auth/otp/verify', {
      mobile: pendingMobile.value,
      otp,
    })

    const data = response.data.data ?? response.data
    token.value = data.token
    user.value = data.user

    localStorage.setItem('token', token.value)
    localStorage.setItem('user', JSON.stringify(user.value))

    // Clean up OTP state
    pendingMobile.value = ''
    devOtp.value = ''
    sessionStorage.removeItem('pendingMobile')
    sessionStorage.removeItem('devOtp')

    return data
  }

  const logout = () => {
    user.value = null
    token.value = ''
    pendingMobile.value = ''
    devOtp.value = ''
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    sessionStorage.removeItem('pendingMobile')
    sessionStorage.removeItem('devOtp')
  }

  const fetchProfile = async () => {
    const response = await apiClient.get('/me')
    const data = response.data.data ?? response.data
    user.value = data
    localStorage.setItem('user', JSON.stringify(user.value))
  }

  return {
    user,
    token,
    pendingMobile,
    devOtp,
    isAuthenticated,
    initializeAuth,
    requestOtp,
    verifyOtp,
    logout,
    fetchProfile,
  }
})
