<script setup lang="ts">
import { ref } from 'vue'
import { createTicket } from '../api'

const emit = defineEmits<{
  (e: 'ticket-created'): void
  (e: 'cancel'): void
}>()

const subject = ref('')
const description = ref('')

const loading = ref(false)
const errors = ref<string[]>([])
const createdTicket = ref<Record<string, unknown> | null>(null)

async function handleSubmit() {
  errors.value = []
  createdTicket.value = null

  if (!subject.value.trim() || !description.value.trim()) {
    errors.value.push('Todos los campos son obligatorios.')
    return
  }

  loading.value = true

  try {
    const res = await createTicket(subject.value.trim(), description.value.trim())

    if (!res.ok) {
      errors.value = res.data.messages ?? [res.data.error ?? 'Error desconocido.']
      return
    }

    createdTicket.value = res.data.ticket
    emit('ticket-created')
    clearForm()
  } catch {
    errors.value = ['No se pudo conectar con el servidor.']
  } finally {
    loading.value = false
  }
}

function clearForm() {
  subject.value = ''
  description.value = ''
}
</script>

<template>
  <div class="ticket-form">
    <h2>Crear ticket de soporte</h2>

    <div v-if="errors.length" class="alert alert-error">
      <p v-for="(msg, i) in errors" :key="i">{{ msg }}</p>
    </div>

    <div v-if="createdTicket" class="alert alert-success">
      <p><strong>¡Ticket #{{ createdTicket.id }} creado!</strong></p>
      <p>Status: {{ createdTicket.status }}</p>
    </div>

    <form @submit.prevent="handleSubmit" novalidate>
      <div class="field">
        <label for="subject">Asunto</label>
        <input id="subject" v-model="subject" type="text" :disabled="loading" />
      </div>

      <div class="field">
        <label for="description">Descripción</label>
        <textarea id="description" v-model="description" rows="5" :disabled="loading"></textarea>
      </div>

      <div class="form-actions">
        <button type="submit" :disabled="loading" class="btn">
          {{ loading ? 'Enviando...' : 'Enviar ticket' }}
        </button>
        <button type="button" class="btn-cancel" @click="emit('cancel')" :disabled="loading">
          Cancelar
        </button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.ticket-form {
  max-width: 520px;
  margin: 2rem auto;
  padding: 2rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 10px;
}
h2 { margin: 0 0 1.25rem; font-size: 1.3rem; }

.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-size: 0.9rem; }
.alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.alert p { margin: 0; }
.alert p + p { margin-top: 0.25rem; }

.field { margin-bottom: 1rem; }
.field label { display: block; margin-bottom: 0.3rem; font-size: 0.9rem; font-weight: 500; color: #333; }
.field input, .field textarea {
  width: 100%; padding: 0.55rem 0.7rem; border: 1px solid #ccc;
  border-radius: 6px; font-size: 0.95rem; font-family: inherit; box-sizing: border-box;
}
.field input:focus, .field textarea:focus {
  outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.form-actions {
  display: flex;
  gap: 0.75rem;
}

.btn {
  display: inline-block; padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 500;
  color: #fff; background: #3b82f6; border: none; border-radius: 6px; cursor: pointer;
}
.btn:hover:not(:disabled) { background: #2563eb; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-cancel {
  padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 500;
  color: #555; background: #f5f5f5; border: 1px solid #ddd; border-radius: 6px; cursor: pointer;
}
.btn-cancel:hover:not(:disabled) { background: #eee; }
</style>
