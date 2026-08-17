<script setup lang="ts">
defineOptions({ name: 'AppToast' })
import { useToast } from '../composables/useToast'

const { toasts, remove } = useToast()
</script>

<template>
  <div class="toast-container">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="toast"
      :class="'toast-' + t.type"
      @click="remove(t.id)"
    >
      {{ t.text }}
    </div>
  </div>
</template>

<style scoped>
.toast-container {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: 360px;
}

.toast {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  font-size: 0.88rem;
  font-weight: 500;
  cursor: pointer;
  box-shadow: 0 2px 12px rgba(0,0,0,0.12);
  animation: slideIn 0.25s ease-out;
  opacity: 0.95;
}

.toast-success {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #bbf7d0;
}

.toast-error {
  background: #fee2e2;
  color: #b91c1c;
  border: 1px solid #fecaca;
}

@keyframes slideIn {
  from { transform: translateX(100%); opacity: 0; }
  to { transform: translateX(0); opacity: 0.95; }
}
</style>
