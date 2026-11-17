<template>
  <div class="min-h-screen bg-gradient-to-br from-primary-600 to-primary-900 flex items-center justify-center p-4">
    <!-- Background Pattern -->
    <div class="absolute inset-0 opacity-10">
      <svg class="w-full h-full" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
            <path d="M 20 0 L 0 0 0 20" fill="none" stroke="white" stroke-width="0.5" />
          </pattern>
        </defs>
        <rect width="100" height="100" fill="url(#grid)" />
      </svg>
    </div>

    <!-- Content -->
    <div class="relative z-10 w-full max-w-md">
      <!-- Logo -->
      <div class="text-center mb-8">
        <h1 class="text-4xl font-bold text-white mb-2">Collective</h1>
        <p class="text-primary-100 text-lg">Commerce Platform</p>
      </div>

      <!-- Card -->
      <div class="bg-white rounded-2xl shadow-2xl p-8">
        <router-view />
      </div>

      <!-- Footer Links -->
      <div class="mt-6 text-center text-primary-100 text-sm">
        <p>
          Collective Commerce
          <span class="mx-2">•</span>
          <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
          <span class="mx-2">•</span>
          <a href="#" class="hover:text-white transition-colors">Terms</a>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Auth layout wrapper - no logic needed
</script>
