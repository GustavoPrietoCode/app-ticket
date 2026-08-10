<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getTickets } from '../api'

const tickets = ref<Record<string, unknown>[]>([])
const loading = ref(true)
const error = ref('')

async function loadTickets() {
  loading.value = true
  error.value = ''

  const res = await getTickets()

  if (!res.ok) {
    error.value = res.data.error ?? 'Error al cargar los tickets.'
    loading.value = false
    return
  }

  tickets.value = res.data.tickets
  loading.value = false
}

function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    open: 'Abierto',
    in_progress: 'En proceso',
    closed: 'Cerrado',
  }
  return labels[status] ?? status
}

function statusClass(status: string): string {
  return `badge badge-${status}`
}

defineExpose({ loadTickets })

onMounted(loadTickets)
</script>

<template>
  <div class="ticket-list">
    <h2>Tus tickets</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <!-- Loading -->
    <p v-if="loading" class="loading">Cargando tickets...</p>

    <!-- Vacío -->
    <p v-else-if="!tickets.length" class="empty">No tienes tickets aún. Crea el primero.</p>

    <!-- Tabla -->
    <table v-else>
      <thead>
        <tr>
          <th>#</th>
          <th>Asunto</th>
          <th>Descripción</th>
          <th>Estado</th>
          <th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="ticket in tickets" :key="ticket.id as number">
          <td>{{ ticket.id }}</td>
          <td>{{ ticket.subject }}</td>
          <td class="desc">{{ ticket.description }}</td>
          <td>
            <span :class="statusClass(ticket.status as string)">
              {{ statusLabel(ticket.status as string) }}
            </span>
          </td>
          <td>{{ (ticket.created_at as string)?.split(' ')[0] }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.ticket-list {
  max-width: 800px;
  margin: 2rem auto;
  padding: 1.5rem 2rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 10px;
}
h2 {
  margin: 0 0 1.25rem;
  font-size: 1.3rem;
}

.loading, .empty {
  text-align: center;
  color: #888;
  font-size: 0.95rem;
  padding: 1rem 0;
}

.alert-error {
  padding: 0.75rem 1rem;
  border-radius: 6px;
  margin-bottom: 1rem;
  background: #fef2f2;
  color: #b91c1c;
  border: 1px solid #fecaca;
  font-size: 0.9rem;
}
.alert-error p { margin: 0; }

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
th {
  text-align: left;
  padding: 0.6rem 0.5rem;
  border-bottom: 2px solid #e2e2e2;
  color: #555;
  font-weight: 600;
}
td {
  padding: 0.6rem 0.5rem;
  border-bottom: 1px solid #f0f0f0;
  vertical-align: middle;
}
.desc {
  max-width: 250px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Badges de estado */
.badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 12px;
  font-size: 0.8rem;
  font-weight: 500;
}
.badge-open {
  background: #dbeafe;
  color: #1d4ed8;
}
.badge-in_progress {
  background: #fef3c7;
  color: #b45309;
}
.badge-closed {
  background: #d1fae5;
  color: #065f46;
}
</style>
