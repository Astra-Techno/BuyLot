import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Notification } from '@/types'

export const useNotificationStore = defineStore('notifications', () => {
  const notifications = ref<Notification[]>([])

  const addNotification = (
    notification: Omit<Notification, 'id' | 'createdAt'>
  ): string => {
    const id = `notif-${Date.now()}-${Math.random()}`
    const newNotification: Notification = {
      ...notification,
      id,
      createdAt: new Date(),
    }

    notifications.value.push(newNotification)

    // Auto-dismiss after 5 seconds for non-error notifications
    if (notification.type !== 'error') {
      setTimeout(() => {
        dismissNotification(id)
      }, 5000)
    }

    return id
  }

  const dismissNotification = (id: string) => {
    notifications.value = notifications.value.filter((n) => n.id !== id)
  }

  const clearAll = () => {
    notifications.value = []
  }

  const addSuccess = (title: string, message: string) => {
    return addNotification({
      type: 'success',
      title,
      message,
      userId: '', // Will be set by API response
      read: false,
    })
  }

  const addError = (title: string, message: string) => {
    return addNotification({
      type: 'error',
      title,
      message,
      userId: '',
      read: false,
    })
  }

  const addWarning = (title: string, message: string) => {
    return addNotification({
      type: 'warning',
      title,
      message,
      userId: '',
      read: false,
    })
  }

  const addInfo = (title: string, message: string) => {
    return addNotification({
      type: 'info',
      title,
      message,
      userId: '',
      read: false,
    })
  }

  return {
    notifications,
    addNotification,
    dismissNotification,
    clearAll,
    addSuccess,
    addError,
    addWarning,
    addInfo,
  }
})
