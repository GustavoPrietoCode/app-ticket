import { ref } from 'vue'

export interface ToastMessage {
  id: number
  type: 'success' | 'error'
  text: string
}

const toasts = ref<ToastMessage[]>([])
let nextId = 1

export function useToast() {
  function add(type: 'success' | 'error', text: string) {
    const id = nextId++
    toasts.value.push({ id, type, text })
    setTimeout(() => remove(id), 3500)
  }

  function remove(id: number) {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  return {
    toasts,
    success: (text: string) => add('success', text),
    error: (text: string) => add('error', text),
    remove,
  }
}
