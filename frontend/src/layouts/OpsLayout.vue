<template>
  <div class="flex h-screen bg-neutral-50">
    <!-- Sidebar -->
    <aside class="hidden lg:flex flex-col w-64 bg-neutral-900 text-white shadow-lg">
      <div class="p-6 border-b border-neutral-800">
        <h1 class="text-xl font-bold text-primary-400">Collective</h1>
        <p class="text-xs font-semibold text-neutral-400 uppercase mt-1">Operations</p>
      </div>

      <nav class="flex-1 px-4 py-6 space-y-2">
        <router-link to="/ops/dashboard" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 11l4-4m0 0l4 4m-4-4V3" />
          </svg>
          <span class="text-sm font-medium">Dashboard</span>
        </router-link>

        <router-link to="/ops/procurement" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
          </svg>
          <span class="text-sm font-medium">Procurement</span>
        </router-link>

        <router-link to="/ops/offers" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span class="text-sm font-medium">Offers</span>
        </router-link>

        <router-link to="/ops/fulfillment" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
          </svg>
          <span class="text-sm font-medium">Fulfillment</span>
        </router-link>

        <router-link to="/ops/disputes" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 0v2M9 7h6m0 0h2m-2 0H7m12 0v3a2 2 0 01-2 2H7a2 2 0 01-2-2V7m12 0H3" />
          </svg>
          <span class="text-sm font-medium">Disputes</span>
        </router-link>

        <router-link to="/ops/finance" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span class="text-sm font-medium">Finance</span>
        </router-link>

        <router-link to="/ops/admin" class="flex items-center gap-3 px-4 py-3 rounded-lg text-neutral-300 hover:bg-neutral-800 hover:text-primary-400 transition-colors" active-class="bg-primary-900 text-primary-400 font-semibold">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          <span class="text-sm font-medium">Administration</span>
        </router-link>
      </nav>

      <div class="p-4 border-t border-neutral-800">
        <button @click="logout" class="w-full flex items-center gap-2 px-4 py-2 rounded-lg text-danger-400 hover:bg-neutral-800 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          <span class="text-sm font-medium">Logout</span>
        </button>
      </div>
    </aside>

    <!-- Mobile Header -->
    <div class="lg:hidden fixed top-0 left-0 right-0 z-40 bg-neutral-900 text-white border-b border-neutral-800 shadow-lg">
      <div class="flex items-center justify-between px-4 py-3">
        <h1 class="text-lg font-bold text-primary-400">Collective Ops</h1>
        <button @click="toggleMobileMenu" class="p-2">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto lg:pt-0 pt-16">
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
