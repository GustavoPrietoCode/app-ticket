<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getUsers, updateUserRole } from '../api'

interface UserRow {
  id: number
  name: string
  email: string
  role: string
  role_display: string
  created_at: string
}

const users = ref<UserRow[]>([])
const loading = ref(true)
const error = ref('')

const roles = [
  { id: 1, name: 'admin', display: 'Administrador' },
  { id: 2, name: 'user', display: 'Usuario' },
]

async function loadUsers() {
  loading.value = true
  error.value = ''

  const res = await getUsers()
  if (!res.ok) {
    error.value = res.data.error ?? 'Error al cargar usuarios.'
    loading.value = false
    return
  }

  users.value = res.data.users as UserRow[]
  loading.value = false
}

async function changeRole(user: UserRow, roleId: number) {
  const res = await updateUserRole(user.id, roleId)
  if (res.ok) {
    const newRole = roles.find((r) => r.id === roleId)
    if (newRole) {
      user.role = newRole.name
      user.role_display = newRole.display
    }
  }
}

defineExpose({ loadUsers })

onMounted(loadUsers)
</script>

<template>
  <div class="user-list">
    <h2>Usuarios</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <p v-if="loading" class="loading">Cargando usuarios...</p>

    <table v-else>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Registro</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td class="col-id">{{ u.id }}</td>
          <td>{{ u.name }}</td>
          <td>{{ u.email }}</td>
          <td>
            <select
              class="role-select"
              :value="roles.find(r => r.name === u.role)?.id ?? 2"
              @change="changeRole(u, parseInt(($event.target as HTMLSelectElement).value))"
            >
              <option v-for="r in roles" :key="r.id" :value="r.id">
                {{ r.display }}
              </option>
            </select>
          </td>
          <td class="col-date">{{ u.created_at?.split(' ')[0] }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.user-list {
  max-width: 800px;
  margin: 2rem auto;
  padding: 1.5rem 2rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 10px;
}
h2 { margin: 0 0 1.25rem; font-size: 1.3rem; }

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

.loading { text-align: center; color: #888; padding: 1rem 0; }

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
}

.col-id { width: 48px; color: #888; }
.col-date { color: #999; font-size: 0.85rem; }

.role-select {
  padding: 0.35rem 0.5rem;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 0.85rem;
  background: #fff;
  cursor: pointer;
}
.role-select:focus {
  outline: none;
  border-color: #3b82f6;
}
</style>
