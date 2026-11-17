<template>
  <div class="flex h-screen bg-neutral-50">
    <!-- Sidebar -->
    <aside class="hidden md:flex flex-col w-64 bg-white border-r border-neutral-200 shadow-sm">
      <div class="p-6 border-b border-neutral-200">
        <h1 class="text-xl font-bold text-primary-600">Collective</h1>
        <p class="text-xs font-semibold text-neutral-500 uppercase mt-1">Seller</p>
      </div>

      <nav class="flex-1 px-4 py-6 space-y-2">
        <router-link to="/seller/dashboard" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-700 hover:bg-primary-50 hover:text-primary-600 transition-colors" active-class="bg-primary-50 text-primary-600 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 11l4-4m0 0l4 4m-4-4V3" />
          </svg>
          <span class="text-sm font-medium">Dashboard</span>
        </router-link>

        <router-link to="/seller/rfqs" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-700 hover:bg-primary-50 hover:text-primary-600 transition-colors" active-class="bg-primary-50 text-primary-600 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
          </svg>
          <span class="text-sm font-medium">RFQs</span>
        </router-link>

        <router-link to="/seller/orders" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-700 hover:bg-primary-50 hover:text-primary-600 transition-colors" active-class="bg-primary-50 text-primary-600 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
          </svg>
          <span class="text-sm font-medium">Orders</span>
        </router-link>

        <router-link to="/seller/settlements" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-700 hover:bg-primary-50 hover:text-primary-600 transition-colors" active-class="bg-primary-50 text-primary-600 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span class="text-sm font-medium">Settlements</span>
        </router-link>

        <router-link to="/seller/deals" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-700 hover:bg-primary-50 hover:text-primary-600 transition-colors" active-class="bg-primary-50 text-primary-600 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.172l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
          <span class="text-sm font-medium">Deals</span>
        </router-link>
      </nav>

      <div class="p-4 border-t border-neutral-200">
        <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 rounded-lg text-danger-600 hover:bg-danger-50 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          <span class="text-sm font-medium">Logout</span>
        </button>
      </div>
    </aside>

    <!-- Mobile Header -->
    <div class="md:hidden fixed top-0 left-0 right-0 z-40 bg-white border-b border-neutral-200 shadow-sm">
      <div class="flex items-center justify-between px-4 py-3">
        <h1 class="text-lg font-bold text-primary-600">Collective Seller</h1>
        <button @click="toggleMobileMenu" class="p-2">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto md:pt-0 pt-16">
      <router-view />
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const mobileMenuOpen = ref(false)

const toggleMobileMenu = () => {
  mobileMenuOpen.value = !mobileMenuOpen.value
}

const logout = () => {
  authStore.logout()
  router.push('/login')
}
</script>
