<template>
  <span :class="badgeClass">
    {{ label }}
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  status: string
  size?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
  size: 'md',
})

const statusConfig: Record<string, { bg: string; text: string; label: string }> = {
  'active': { bg: 'bg-success-50', text: 'text-success-700', label: 'Active' },
  'pending': { bg: 'bg-warning-50', text: 'text-warning-700', label: 'Pending' },
  'completed': { bg: 'bg-success-50', text: 'text-success-700', label: 'Completed' },
  'cancelled': { bg: 'bg-danger-50', text: 'text-danger-700', label: 'Cancelled' },
  'rejected': { bg: 'bg-danger-50', text: 'text-danger-700', label: 'Rejected' },
  'draft': { bg: 'bg-neutral-100', text: 'text-neutral-700', label: 'Draft' },
  'submitted': { bg: 'bg-primary-50', text: 'text-primary-700', label: 'Submitted' },
  'accepted': { bg: 'bg-success-50', text: 'text-success-700', label: 'Accepted' },
  'in_transit': { bg: 'bg-primary-50', text: 'text-primary-700', label: 'In Transit' },
  'delivered': { bg: 'bg-success-50', text: 'text-success-700', label: 'Delivered' },
  'open': { bg: 'bg-danger-50', text: 'text-danger-700', label: 'Open' },
  'resolved': { bg: 'bg-success-50', text: 'text-success-700', label: 'Resolved' },
}

const config = computed(() => statusConfig[props.status] || { bg: 'bg-neutral-100', text: 'text-neutral-700', label: props.status })

const sizeClass = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'px-2 py-1 text-xs'
    case 'lg':
      return 'px-4 py-2 text-base'
    default:
      return 'px-3 py-1.5 text-sm'
  }
})

const badgeClass = computed(() => `inline-flex items-center ${sizeClass.value} rounded-full font-medium ${config.value.bg} ${config.value.text}`)

const label = computed(() => config.value.label)
</script>
