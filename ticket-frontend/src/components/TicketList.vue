<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { getTickets, updateTicketStatus, addComment, getComments, uploadFile } from '../api'

interface Ticket {
  id: number
  subject: string
  description: string
  status: 'open' | 'in_progress' | 'closed'
  gitea_issue_id: number | null
  created_at: string
  updated_at: string
}

const tickets = ref<Ticket[]>([])
const loading = ref(true)
const error = ref('')
const filter = ref<'all' | 'open' | 'closed'>('all')

const counts = computed(() => ({
  all: tickets.value.length,
  open: tickets.value.filter((t) => t.status !== 'closed').length,
  closed: tickets.value.filter((t) => t.status === 'closed').length,
}))

const filteredTickets = computed(() => {
  if (filter.value === 'all') return tickets.value
  if (filter.value === 'open') return tickets.value.filter((t) => t.status !== 'closed')
  return tickets.value.filter((t) => t.status === 'closed')
})

// Comentarios
const expandedTicket = ref<number | null>(null)
const commentText = ref('')
const sendingComment = ref(false)
const comments = ref<{ id: number; body: string; author: string; created_at: string }[]>([])
const loadingComments = ref(false)
const uploadingFile = ref(false)

async function handleFileUpload(ticketId: number, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return

  uploadingFile.value = true
  const res = await uploadFile(ticketId, file)
  uploadingFile.value = false

  if (res.ok && res.data.url) {
    // Insertar markdown de imagen en el textarea
    commentText.value += `\n![${file.name}](${res.data.url})\n`
  }

  // Limpiar input para permitir re-subir el mismo archivo
  input.value = ''
}

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

  // Refrescar también los comentarios si hay un ticket expandido
  if (expandedTicket.value) {
    const commentsRes = await getComments(expandedTicket.value)
    if (commentsRes.ok) {
      comments.value = commentsRes.data.comments as typeof comments.value
    }
  }
}

async function changeStatus(ticket: Ticket, newStatus: string) {
  const res = await updateTicketStatus(ticket.id, newStatus)
  if (res.ok) {
    ticket.status = newStatus as Ticket['status']
  }
}

async function toggleExpand(ticketId: number) {
  if (expandedTicket.value === ticketId) {
    expandedTicket.value = null
    comments.value = []
    return
  }

  expandedTicket.value = ticketId
  commentText.value = ''

  // Cargar historial de comentarios
  loadingComments.value = true
  const res = await getComments(ticketId)
  if (res.ok) {
    comments.value = res.data.comments as typeof comments.value
  }
  loadingComments.value = false
}

async function sendComment(ticket: Ticket) {
  const text = commentText.value.trim()
  if (!text) return

  sendingComment.value = true
  const res = await addComment(ticket.id, text)
  if (res.ok) {
    commentText.value = ''
    // Recargar comentarios para mostrar el nuevo
    const refreshed = await getComments(ticket.id)
    if (refreshed.ok) {
      comments.value = refreshed.data.comments as typeof comments.value
    }
  }
  sendingComment.value = false
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

function renderBody(body: string): string {
  // Convertir ![alt](url) en enlaces clickables
  let html = body.replace(
    /!\[([^\]]*)\]\(([^)]+)\)/g,
    '<a href="$2" target="_blank" rel="noopener">📷 $1</a>',
  )
  // Convertir saltos de línea en <br>
  html = html.replace(/\n/g, '<br>')
  return html
}

defineExpose({ loadTickets })

onMounted(loadTickets)
</script>

<template>
  <div class="ticket-list">
    <div class="list-header">
      <div class="filter-bar">
        <button
          class="filter-btn"
          :class="{ active: filter === 'all' }"
          @click="filter = 'all'"
        >
          Todos {{ counts.all }}
        </button>
        <button
          class="filter-btn"
          :class="{ active: filter === 'open' }"
          @click="filter = 'open'"
        >
          Abiertos {{ counts.open }}
        </button>
        <button
          class="filter-btn"
          :class="{ active: filter === 'closed' }"
          @click="filter = 'closed'"
        >
          Cerrados {{ counts.closed }}
        </button>
      </div>
      <button class="btn-refresh" @click="loadTickets" :disabled="loading">
        {{ loading ? 'Cargando...' : '⟳' }}
      </button>
    </div>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <p v-if="loading" class="loading">Cargando tickets...</p>

    <p v-else-if="!tickets.length" class="empty">No tienes tickets aún.</p>

    <div v-else class="cards">
      <div
        v-for="ticket in filteredTickets"
        :key="ticket.id"
        class="card"
        :class="{ 'card-closed': ticket.status === 'closed' }"
      >
        <div class="card-top">
          <div class="card-id-box">
            <span class="card-id">#{{ ticket.id }}</span>
          </div>
          <div class="card-info">
            <span class="card-subject">{{ ticket.subject }}</span>
            <span class="card-date">{{ ticket.created_at?.split(' ')[0] }}</span>
          </div>
          <span :class="statusClass(ticket.status)">{{ statusLabel(ticket.status) }}</span>
        </div>

        <p class="card-desc">{{ ticket.description }}</p>

        <hr class="card-divider" />

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
        <div v-if="expandedTicket === ticket.id" class="comment-section">
          <!-- Historial -->
          <div class="comment-history">
            <p v-if="loadingComments" class="comment-loading">Cargando conversación...</p>

            <p v-else-if="!comments.length" class="comment-empty">
              Sin comentarios aún. Sé el primero en responder.
            </p>

            <div v-else v-for="c in comments" :key="c.id" class="comment">
              <div class="comment-header">
                <span class="comment-author">{{ c.author }}</span>
                <span class="comment-date">{{ c.created_at?.split('T')[0] }}</span>
              </div>
              <div class="comment-body" v-html="renderBody(c.body)"></div>
            </div>
          </div>

          <!-- Nuevo comentario -->
          <div class="comment-form">
            <textarea
              v-model="commentText"
              placeholder="Escribe un comentario..."
              rows="2"
              :disabled="sendingComment"
            ></textarea>
            <div class="comment-actions">
              <label class="btn-attach" :class="{ disabled: uploadingFile }">
                {{ uploadingFile ? 'Subiendo...' : '📎 Imagen' }}
                <input
                  type="file"
                  accept="image/*"
                  class="file-input"
                  :disabled="uploadingFile || sendingComment"
                  @change="handleFileUpload(ticket.id, $event)"
                />
              </label>
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
  gap: 1rem;
  flex-wrap: wrap;
}

.filter-bar {
  display: flex;
  gap: 0.35rem;
}
.filter-btn {
  padding: 0.35rem 0.75rem;
  font-size: 0.83rem;
  font-weight: 500;
  color: #555;
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
  white-space: nowrap;
}
.filter-btn:hover { background: #eee; }
.filter-btn.active {
  color: #fff;
  background: #3b82f6;
  border-color: #3b82f6;
}

.btn-refresh {
  padding: 0.35rem 0.7rem;
  font-size: 0.95rem;
  color: #555;
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
  line-height: 1;
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
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.card-id-box {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  height: 48px;
  background: #eff6ff;
  border: 2px solid #bfdbfe;
  border-radius: 8px;
  flex-shrink: 0;
}
.card-id {
  font-size: 0.9rem;
  font-weight: 700;
  color: #2563eb;
}

.card-info {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  flex: 1;
  min-width: 0;
}
.card-subject {
  font-size: 1rem;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.card-date {
  font-size: 0.78rem;
  color: #999;
}

.card-desc {
  margin: 0 0 0.75rem;
  font-size: 0.9rem;
  color: #555;
  line-height: 1.5;
}

.card-divider {
  margin: 0 0 0.75rem;
  border: none;
  border-top: 1px solid #e8e8e8;
}

/* Badges */
.badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 12px;
  font-size: 0.78rem;
  font-weight: 500;
}
.badge-open { background: #dcfce7; color: #15803d; }
.badge-in_progress { background: #fef3c7; color: #b45309; }
.badge-closed { background: #fee2e2; color: #b91c1c; }

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

/* ─── Sección de comentarios ─── */
.comment-section {
  margin-top: 0.75rem;
  border-top: 1px solid #f0f0f0;
  padding-top: 0.75rem;
}

.comment-history {
  max-height: 300px;
  overflow-y: auto;
  margin-bottom: 0.75rem;
}

.comment-loading, .comment-empty {
  text-align: center;
  color: #999;
  font-size: 0.85rem;
  padding: 0.75rem 0;
}

.comment {
  padding: 0.6rem 0.75rem;
  margin-bottom: 0.5rem;
  background: #fff;
  border: 1px solid #eee;
  border-radius: 6px;
}
.comment-header {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.3rem;
}
.comment-author {
  font-size: 0.82rem;
  font-weight: 600;
  color: #3b82f6;
}
.comment-date {
  font-size: 0.75rem;
  color: #aaa;
}
.comment-body {
  font-size: 0.88rem;
  color: #444;
  line-height: 1.5;
}
.comment-body :deep(p) { margin: 0.25rem 0; }
.comment-body :deep(strong) { font-weight: 600; }
.comment-body :deep(a) { color: #3b82f6; text-decoration: none; }
.comment-body :deep(a:hover) { text-decoration: underline; }

/* ─── Formulario de comentario ─── */
.comment-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.comment-form textarea {
  width: 100%;
  padding: 0.5rem 0.7rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.9rem;
  font-family: inherit;
  box-sizing: border-box;
  resize: vertical;
}
.comment-form textarea:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.comment-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.btn-attach {
  display: inline-flex;
  align-items: center;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  color: #555;
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
}
.btn-attach:hover:not(.disabled) { background: #eee; }
.btn-attach.disabled { opacity: 0.5; cursor: not-allowed; }

.file-input {
  display: none;
}

.btn-send {
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
