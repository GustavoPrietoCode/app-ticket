<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getUsers, updateUser, createUser, getOrganizations } from '../api'
import { useToast } from '../composables/useToast'

const toast = useToast()

interface UserRow {
  id: number
  name: string
  email: string
  role: string
  role_display: string
  organization_id: number | null
  organization_name: string | null
  created_at: string
}

interface OrganizationRow {
  id: number
  name: string
}

const users = ref<UserRow[]>([])
const organizations = ref<OrganizationRow[]>([])
const loading = ref(true)
const error = ref('')

const roles = [
  { id: 1, name: 'admin', display: 'Administrador' },
  { id: 2, name: 'user', display: 'Usuario' },
]

// Formulario de alta de usuario
const showForm = ref(false)
const formName = ref('')
const formEmail = ref('')
const formPassword = ref('')
const formRoleId = ref(2)
const formOrganizationId = ref<number | null>(null)
const saving = ref(false)

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

async function loadOrganizations() {
  const res = await getOrganizations()
  if (res.ok) {
    organizations.value = res.data.organizations as OrganizationRow[]
  }
}

async function changeRole(user: UserRow, roleId: number) {
  const res = await updateUser(user.id, { role_id: roleId })
  if (res.ok) {
    const newRole = roles.find((r) => r.id === roleId)
    if (newRole) {
      user.role = newRole.name
      user.role_display = newRole.display
    }
    toast.success(`Rol de ${user.name} actualizado a ${newRole?.display ?? ''}`)
  } else {
    toast.error('Error al cambiar el rol')
  }
}

async function changeOrganization(user: UserRow, organizationId: number | null) {
  const res = await updateUser(user.id, { organization_id: organizationId })
  if (res.ok) {
    user.organization_id = organizationId
    user.organization_name =
      organizations.value.find((o) => o.id === organizationId)?.name ?? null
    toast.success(
      organizationId
        ? `${user.name} asignado a ${user.organization_name}`
        : `${user.name} sin organización`,
    )
  } else {
    toast.error(res.data.error ?? 'Error al asignar la organización')
    loadUsers()
  }
}

function openForm() {
  formName.value = ''
  formEmail.value = ''
  formPassword.value = ''
  formRoleId.value = 2
  formOrganizationId.value = null
  showForm.value = true
}

function closeForm() {
  showForm.value = false
}

async function saveUser() {
  saving.value = true

  const res = await createUser({
    name: formName.value.trim(),
    email: formEmail.value.trim(),
    password: formPassword.value,
    role_id: formRoleId.value,
    organization_id: formOrganizationId.value,
  })

  saving.value = false

  if (!res.ok) {
    const message =
      (res.data.messages as string[] | undefined)?.join(' ') ??
      res.data.error ??
      'Error al crear el usuario.'
    toast.error(message)
    return
  }

  toast.success('Usuario creado')
  closeForm()
  loadUsers()
}

defineExpose({ loadUsers })

onMounted(() => {
  loadUsers()
  loadOrganizations()
})
</script>

<template>
  <div class="user-list">
    <h2>Usuarios</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <div class="user-actions">
      <button v-if="!showForm" class="btn-create" @click="openForm">＋ Crear usuario</button>
    </div>

    <!-- Formulario alta de usuario -->
    <div v-if="showForm" class="user-form">
      <h3>Nuevo usuario</h3>

      <div class="field">
        <label for="user-name">Nombre</label>
        <input id="user-name" v-model="formName" type="text" placeholder="Nombre" />
      </div>

      <div class="field">
        <label for="user-email">Email</label>
        <input id="user-email" v-model="formEmail" type="email" placeholder="correo@empresa.com" />
      </div>

      <div class="field">
        <label for="user-password">Contraseña</label>
        <input id="user-password" v-model="formPassword" type="password" placeholder="Mínimo 6 caracteres" />
      </div>

      <div class="field">
        <label for="user-role">Rol</label>
        <select id="user-role" v-model="formRoleId">
          <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.display }}</option>
        </select>
      </div>

      <div class="field">
        <label for="user-organization">Organización</label>
        <select id="user-organization" v-model="formOrganizationId">
          <option :value="null">Sin organización</option>
          <option v-for="o in organizations" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>
      </div>

      <div class="form-actions">
        <button class="btn-cancel" @click="closeForm">Cancelar</button>
        <button
          class="btn"
          :disabled="saving || !formName.trim() || !formEmail.trim() || !formPassword"
          @click="saveUser"
        >
          {{ saving ? 'Creando...' : 'Crear usuario' }}
        </button>
      </div>
    </div>

    <p v-if="loading" class="loading">Cargando usuarios...</p>

    <table v-else>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Email</th>
          <th>Rol</th>
          <th>Organización</th>
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
          <td>
            <select
              class="role-select"
              :value="u.organization_id ?? ''"
              @change="changeOrganization(u, ($event.target as HTMLSelectElement).value ? parseInt(($event.target as HTMLSelectElement).value) : null)"
            >
              <option value="">Sin organización</option>
              <option v-for="o in organizations" :key="o.id" :value="o.id">
                {{ o.name }}
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
h3 { margin: 0 0 1rem; font-size: 1.05rem; color: #333; }

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

.user-actions { margin-bottom: 1rem; }

.btn-create {
  padding: 0.45rem 1rem;
  font-size: 0.88rem;
  font-weight: 500;
  color: #fff;
  background: #3b82f6;
  border: none;
  border-radius: 8px;
  cursor: pointer;
}
.btn-create:hover:not(:disabled) { background: #2563eb; }
.btn-create:disabled { opacity: 0.6; cursor: not-allowed; }

/* Formulario */
.user-form {
  margin-bottom: 1.5rem;
  padding: 1rem 1.25rem;
  background: #f9f9f9;
  border: 1px solid #eee;
  border-radius: 8px;
}

.field { margin-bottom: 1rem; }
.field label {
  display: block;
  margin-bottom: 0.3rem;
  font-size: 0.9rem;
  font-weight: 500;
  color: #333;
}
.field input, .field select {
  width: 100%;
  padding: 0.55rem 0.7rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.95rem;
  font-family: inherit;
  box-sizing: border-box;
  background: #fff;
}
.field input:focus, .field select:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
}

.form-actions {
  display: flex;
  gap: 0.75rem;
  margin-top: 0.5rem;
}

.btn {
  display: inline-block;
  padding: 0.6rem 1.5rem;
  font-size: 1rem;
  font-weight: 500;
  color: #fff;
  background: #3b82f6;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}
.btn:hover:not(:disabled) { background: #2563eb; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }

.btn-cancel {
  padding: 0.6rem 1.5rem;
  font-size: 1rem;
  font-weight: 500;
  color: #555;
  background: #f5f5f5;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
}
.btn-cancel:hover:not(:disabled) { background: #eee; }

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
