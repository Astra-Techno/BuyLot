<template>
  <div class="flex flex-col items-center justify-center py-12 text-center">
    <svg class="w-16 h-16 text-danger-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
    <h3 class="text-lg font-semibold text-neutral-900 mb-2">{{ title }}</h3>
    <p class="text-neutral-600 max-w-md mb-4">{{ message }}</p>
    <slot name="actions" />
  </div>
</template>

<script setup lang="ts">
interface Props {
  title?: string
  message?: string
}

const props = withDefaults(defineProps<Props>(), {
  title: 'Something went wrong',
  message: 'Please try again or contact support if the problem persists.',
})
</script>
