<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getTickets, updateTicketStatus, addComment } from '../api'

interface Ticket {
  id: number
  subject: string
  description: string
  status: 'open' | 'in_progress' | 'closed'
  gitea_issue_id: number | null
  gitea_url?: string
  created_at: string
  updated_at: string
}

const tickets = ref<Ticket[]>([])
const loading = ref(true)
const error = ref('')

// Comentarios
const expandedTicket = ref<number | null>(null)
const commentText = ref('')
const sendingComment = ref(false)

async function loadTickets() {
  loading.value = true
  error.value = ''

  const res = await getTickets()

  if (!res.ok) {
    error.value = res.data.error ?? 'Error al cargar los tickets.'
    loading.value = false
    return
  }

  tickets.value = res.data.tickets as Ticket[]
  loading.value = false
}

async function changeStatus(ticket: Ticket, newStatus: string) {
  const res = await updateTicketStatus(ticket.id, newStatus)
  if (res.ok) {
    ticket.status = newStatus as Ticket['status']
  }
}

async function sendComment(ticket: Ticket) {
  const text = commentText.value.trim()
  if (!text) return

  sendingComment.value = true
  const res = await addComment(ticket.id, text)
  if (res.ok) {
    commentText.value = ''
  }
  sendingComment.value = false
}

function toggleExpand(ticketId: number) {
  expandedTicket.value = expandedTicket.value === ticketId ? null : ticketId
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
    <div class="list-header">
      <h2>Tus tickets</h2>
      <button class="btn-refresh" @click="loadTickets" :disabled="loading">
        {{ loading ? 'Cargando...' : '⟳ Refrescar' }}
      </button>
    </div>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <p v-if="loading" class="loading">Cargando tickets...</p>

    <p v-else-if="!tickets.length" class="empty">No tienes tickets aún.</p>

    <div v-else class="cards">
      <div
        v-for="ticket in tickets"
        :key="ticket.id"
        class="card"
        :class="{ 'card-closed': ticket.status === 'closed' }"
      >
        <div class="card-top">
          <span class="card-id">#{{ ticket.id }}</span>
          <span :class="statusClass(ticket.status)">{{ statusLabel(ticket.status) }}</span>
        </div>

        <h3 class="card-subject">{{ ticket.subject }}</h3>
        <p class="card-desc">{{ ticket.description }}</p>

        <div class="card-meta">
          <span>{{ ticket.created_at?.split(' ')[0] }}</span>
          <span v-if="ticket.gitea_url">
            · <a :href="ticket.gitea_url" target="_blank" rel="noopener">Ver en Gitea ↗</a>
          </span>
        </div>

        <!-- Acciones -->
        <div class="card-actions">
          <button
            v-if="ticket.status !== 'closed'"
            class="btn-action btn-close"
            @click="changeStatus(ticket, 'closed')"
          >
            Cerrar
          </button>
          <button
            v-if="ticket.status === 'closed'"
            class="btn-action btn-open"
            @click="changeStatus(ticket, 'open')"
          >
            Reabrir
          </button>

          <button class="btn-action btn-comment" @click="toggleExpand(ticket.id)">
            💬 Comentar
          </button>
        </div>

        <!-- Comentarios -->
        <div v-if="expandedTicket === ticket.id" class="comment-box">
          <textarea
            v-model="commentText"
            placeholder="Escribe un comentario..."
            rows="3"
            :disabled="sendingComment"
          ></textarea>
          <button
            class="btn-send"
            @click="sendComment(ticket)"
            :disabled="sendingComment || !commentText.trim()"
          >
            {{ sendingComment ? 'Enviando...' : 'Enviar' }}
          </button>
        </div>
      </div>
    </div>
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

.list-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.25rem;
}
.list-header h2 {
  margin: 0;
  font-size: 1.3rem;
}

.btn-refresh {
  padding: 0.4rem 1rem;
  font-size: 0.85rem;
  color: #555;
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
}
.btn-refresh:hover:not(:disabled) { background: #eee; }

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

.loading, .empty {
  text-align: center;
  color: #888;
  font-size: 0.95rem;
  padding: 1rem 0;
}

/* ─── Cards ─── */
.cards {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.card {
  padding: 1.25rem;
  border: 1px solid #e2e2e2;
  border-radius: 8px;
  background: #fafafa;
}
.card-closed {
  opacity: 0.65;
}

.card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.5rem;
}
.card-id { font-size: 0.8rem; color: #888; font-weight: 500; }

.card-subject { margin: 0 0 0.4rem; font-size: 1.05rem; }
.card-desc { margin: 0 0 0.75rem; font-size: 0.9rem; color: #555; }

.card-meta {
  font-size: 0.8rem;
  color: #999;
  margin-bottom: 0.75rem;
}
.card-meta a { color: #3b82f6; text-decoration: none; }
.card-meta a:hover { text-decoration: underline; }

/* Badges */
.badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 12px;
  font-size: 0.78rem;
  font-weight: 500;
}
.badge-open { background: #dbeafe; color: #1d4ed8; }
.badge-in_progress { background: #fef3c7; color: #b45309; }
.badge-closed { background: #d1fae5; color: #065f46; }

/* ─── Acciones ─── */
.card-actions {
  display: flex;
  gap: 0.5rem;
}
.btn-action {
  padding: 0.3rem 0.75rem;
  font-size: 0.82rem;
  border-radius: 6px;
  border: 1px solid #ddd;
  cursor: pointer;
  background: #fff;
}
.btn-action:hover { background: #f5f5f5; }
.btn-close { color: #b91c1c; border-color: #fecaca; }
.btn-close:hover { background: #fef2f2; }
.btn-open { color: #15803d; border-color: #bbf7d0; }
.btn-open:hover { background: #f0fdf4; }
.btn-comment { color: #555; }

/* ─── Caja de comentario ─── */
.comment-box {
  margin-top: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.comment-box textarea {
  width: 100%;
  padding: 0.5rem 0.7rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.9rem;
  font-family: inherit;
  box-sizing: border-box;
  resize: vertical;
}
.comment-box textarea:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.btn-send {
  align-self: flex-end;
  padding: 0.35rem 1rem;
  font-size: 0.85rem;
  color: #fff;
  background: #3b82f6;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}
.btn-send:hover:not(:disabled) { background: #2563eb; }
.btn-send:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
