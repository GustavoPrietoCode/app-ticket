const BASE_URL = 'http://localhost:8000/api'

function getToken(): string | null {
  return localStorage.getItem('token')
}

 
async function request(
  path: string,
  options: RequestInit = {},
): Promise<{ ok: boolean; status: number; data: Record<string, any> }> {
  const token = getToken()

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string> | undefined),
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers,
  })

  const data = await res.json()

  return { ok: res.ok, status: res.status, data }
}

// ─── Auth ────────────────────────────────────────────────────────────

export function login(email: string, password: string) {
  return request('/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  })
}

export function register(name: string, email: string, password: string) {
  return request('/register', {
    method: 'POST',
    body: JSON.stringify({ name, email, password }),
  })
}

// ─── Tickets ─────────────────────────────────────────────────────────

export function createTicket(subject: string, description: string) {
  return request('/tickets', {
    method: 'POST',
    body: JSON.stringify({ subject, description }),
  })
}

export async function createTicketWithFiles(
  subject: string,
  description: string,
  files: File[],
) {
  const token = localStorage.getItem('token')
  const formData = new FormData()
  formData.append('subject', subject)
  formData.append('description', description)
  files.forEach((f) => formData.append('images[]', f))

  const res = await fetch(`${BASE_URL}/tickets`, {
    method: 'POST',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    body: formData,
  })

  const data = await res.json()
  return { ok: res.ok, status: res.status, data }
}

export function getTickets(userId?: number | null) {
  const path = userId ? `/tickets?user_id=${userId}` : '/tickets'
  return request(path)
}

export function getUsers() {
  return request('/admin/users')
}

export function updateUser(
  userId: number,
  patch: { role_id?: number; organization_id?: number | null },
) {
  return request(`/admin/users/${userId}`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  })
}

export function createUser(data: {
  name: string
  email: string
  password: string
  role_id?: number
  organization_id?: number | null
}) {
  return request('/admin/users', {
    method: 'POST',
    body: JSON.stringify(data),
  })
}

// ─── Organizaciones (admin) ─────────────────────────────────────────

export function getOrganizations() {
  return request('/admin/organizations')
}

export function createOrganization(name: string, gitea_label_id?: number | null) {
  return request('/admin/organizations', {
    method: 'POST',
    body: JSON.stringify({ name, gitea_label_id: gitea_label_id ?? null }),
  })
}

export function updateOrganization(
  id: number,
  patch: { name?: string; gitea_label_id?: number | null },
) {
  return request(`/admin/organizations/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  })
}

export function deleteOrganization(id: number) {
  return request(`/admin/organizations/${id}`, { method: 'DELETE' })
}

export function getGiteaLabels() {
  return request('/admin/gitea-labels')
}

export function updateTicketStatus(id: number, status: string) {
  return request(`/tickets/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  })
}

export async function uploadFile(ticketId: number, file: File) {
  const token = localStorage.getItem('token')
  const formData = new FormData()
  formData.append('file', file)

  const res = await fetch(`${BASE_URL}/tickets/${ticketId}/upload`, {
    method: 'POST',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    body: formData,
  })

  const data = await res.json()
  return { ok: res.ok, status: res.status, data }
}

export function getComments(id: number) {
  return request(`/tickets/${id}/comments`)
}

export function addComment(id: number, comment: string) {
  return request(`/tickets/${id}/comments`, {
    method: 'POST',
    body: JSON.stringify({ comment }),
  })
}
