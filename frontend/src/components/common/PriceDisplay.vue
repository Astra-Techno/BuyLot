<template>
  <span :class="textClass">
    {{ formattedPrice }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  paise: number
  showCurrency?: boolean
  textClass?: string
}

const props = withDefaults(defineProps<Props>(), {
  showCurrency: true,
  textClass: 'text-neutral-900 font-semibold',
})

const formattedPrice = computed(() => {
  const rupees = props.paise / 100
  const formatted = rupees.toLocaleString('en-IN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })

  if (props.showCurrency) {
    return `₹${formatted}`
  }
  return formatted
})
</script>
