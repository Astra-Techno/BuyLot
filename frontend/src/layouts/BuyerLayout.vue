<template>
  <div class="flex flex-col h-screen bg-neutral-50">
    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-40 bg-white border-b border-neutral-200 shadow-sm">
      <div class="flex items-center justify-between px-4 py-3 md:px-6">
        <div class="flex items-center gap-2">
          <h1 class="text-xl font-bold text-primary-600">Collective</h1>
          <span class="text-xs font-semibold text-neutral-500 uppercase">Buyer</span>
        </div>

        <div class="flex items-center gap-4">
          <router-link to="/search" class="hidden md:flex items-center gap-2 px-3 py-2 rounded-lg bg-neutral-100 hover:bg-neutral-200 transition-colors">
            <svg class="w-4 h-4 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <span class="text-sm text-neutral-700">Search</span>
          </router-link>

          <button class="relative p-2 hover:bg-neutral-100 rounded-lg transition-colors">
            <svg class="w-5 h-5 text-neutral-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <span class="absolute top-1 right-1 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-danger-500 rounded-full">
              2
            </span>
          </button>

          <button @click="toggleProfileMenu" class="flex items-center gap-2 px-3 py-2 hover:bg-neutral-100 rounded-lg transition-colors">
            <div class="w-6 h-6 rounded-full bg-primary-500 flex items-center justify-center text-white text-xs font-bold">
              {{ getInitials() }}
            </div>
            <svg class="w-4 h-4 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
            </svg>
          </button>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto pt-16 pb-20 md:pb-0">
      <router-view />
    </main>

    <!-- Bottom Navigation (Mobile) -->
    <nav class="fixed bottom-0 left-0 right-0 md:hidden bg-white border-t border-neutral-200 shadow-lg">
      <div class="flex justify-around">
        <router-link to="/" class="flex flex-col items-center justify-center w-full py-3 text-primary-600" active-class="text-primary-600 bg-primary-50">
          <svg class="w-5 h-5 mb-1" fill="currentColor" viewBox="0 0 24 24">
            <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V20a2 2 0 002 2h12a2 2 0 002-2v-9.586l.707.707a1 1 0 001.414-1.414l-7-7z" />
          </svg>
          <span class="text-xs font-medium">Home</span>
        </router-link>

        <router-link to="/request/new" class="flex flex-col items-center justify-center w-full py-3 text-neutral-500 hover:text-primary-600" active-class="text-primary-600 bg-primary-50">
          <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span class="text-xs font-medium">Request</span>
        </router-link>

        <router-link to="/orders" class="flex flex-col items-center justify-center w-full py-3 text-neutral-500 hover:text-primary-600" active-class="text-primary-600 bg-primary-50">
          <svg class="w-5 h-5 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
          </svg>
          <span class="text-xs font-medium">Orders</span>
        </router-link>

        <router-link to="/profile" class="flex flex-col items-center justify-center w-full py-3 text-neutral-500 hover:text-primary-600" active-class="text-primary-600 bg-primary-50">
          <svg class="w-5 h-5 mb-1" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 12a4 4 0 100-8 4 4 0 000 8z" />
            <path d="M12 14c-6.627 0-9.953 3.325-9.953 9.953 0 .848.359 2 1.5 2h15.906c1.141 0 1.5-1.152 1.5-2 0-6.628-3.326-9.953-9.953-9.953z" />
          </svg>
          <span class="text-xs font-medium">Profile</span>
        </router-link>
      </div>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const profileMenuOpen = ref(false)

const getInitials = () => {
  if (!authStore.user) return 'U'
  const parts = authStore.user.name.split(' ')
  return parts.map((p) => p[0]).join('').toUpperCase()
}

const toggleProfileMenu = () => {
  profileMenuOpen.value = !profileMenuOpen.value
}
</script>
