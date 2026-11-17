<template>
  <div class="flex items-center justify-center">
    <div class="relative w-8 h-8">
      <div class="absolute inset-0 rounded-full border-4 border-neutral-200"></div>
      <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-primary-600 animate-spin"></div>
    </div>
    <span v-if="label" class="ml-3 text-neutral-600 font-medium">{{ label }}</span>
  </div>
</template>

<script setup lang="ts">
interface Props {
  label?: string
}

defineProps<Props>()
</script>
