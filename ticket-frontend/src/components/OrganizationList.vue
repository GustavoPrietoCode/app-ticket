<script setup lang="ts">
import { ref, onMounted } from 'vue'
import {
  getOrganizations,
  getGiteaLabels,
  createOrganization,
  updateOrganization,
  deleteOrganization,
} from '../api'
import { useToast } from '../composables/useToast'

const toast = useToast()

interface Organization {
  id: number
  name: string
  gitea_label_id: number | null
  gitea_label_name: string | null
  gitea_label_color: string | null
  user_count: number
  created_at: string
}

interface GiteaLabel {
  id: number
  name: string
  color: string
}

const organizations = ref<Organization[]>([])
const giteaLabels = ref<GiteaLabel[]>([])
const loading = ref(true)
const error = ref('')
const labelsError = ref('')

// Formulario de alta/edición
const showForm = ref(false)
const editing = ref<Organization | null>(null)
const formName = ref('')
const formLabelId = ref<number | null>(null)
const saving = ref(false)

async function loadOrganizations() {
  loading.value = true
  error.value = ''

  const res = await getOrganizations()
  if (!res.ok) {
    error.value = res.data.error ?? 'Error al cargar organizaciones.'
    loading.value = false
    return
  }

  organizations.value = res.data.organizations as Organization[]
  loading.value = false
}

async function loadGiteaLabels() {
  labelsError.value = ''

  const res = await getGiteaLabels()
  if (!res.ok) {
    labelsError.value = 'No se pudieron cargar las etiquetas de Gitea.'
    return
  }

  giteaLabels.value = res.data.labels as GiteaLabel[]
  if (!giteaLabels.value.length) {
    labelsError.value =
      'El repositorio de Gitea no tiene etiquetas. Créalas en Gitea para poder asignarlas.'
  }
}

function openCreate() {
  editing.value = null
  formName.value = ''
  formLabelId.value = null
  showForm.value = true
}

function openEdit(org: Organization) {
  editing.value = org
  formName.value = org.name
  formLabelId.value = org.gitea_label_id
  showForm.value = true
}

function closeForm() {
  showForm.value = false
  editing.value = null
}

async function save() {
  saving.value = true

  const res = editing.value
    ? await updateOrganization(editing.value.id, {
        name: formName.value,
        gitea_label_id: formLabelId.value,
      })
    : await createOrganization(formName.value, formLabelId.value)

  saving.value = false

  if (!res.ok) {
    const message =
      (res.data.messages as string[] | undefined)?.join(' ') ??
      res.data.error ??
      'Error al guardar.'
    toast.error(message)
    return
  }

  toast.success(editing.value ? 'Organización actualizada' : 'Organización creada')
  closeForm()
  loadOrganizations()
}

async function remove(org: Organization) {
  if (!confirm(`¿Borrar la organización "${org.name}"? Sus usuarios quedarán sin organización.`)) {
    return
  }

  const res = await deleteOrganization(org.id)
  if (res.ok) {
    toast.success('Organización borrada')
    loadOrganizations()
  } else {
    toast.error(res.data.error ?? 'Error al borrar la organización')
  }
}

function hexColor(color: string | null): string {
  if (!color) return '#3b82f6'
  return color.startsWith('#') ? color : `#${color}`
}

defineExpose({ loadOrganizations })

onMounted(() => {
  loadOrganizations()
  loadGiteaLabels()
})
</script>

<template>
  <div class="org-list">
    <h2>Organizaciones</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <div class="org-actions">
      <button v-if="!showForm" class="btn-create" @click="openCreate">
        ＋ Nueva organización
      </button>
    </div>

    <!-- Formulario alta/edición -->
    <div v-if="showForm" class="org-form">
      <h3>{{ editing ? 'Editar organización' : 'Nueva organización' }}</h3>

      <div v-if="labelsError" class="alert alert-error">
        <p>{{ labelsError }}</p>
      </div>

      <div class="field">
        <label for="org-name">Nombre</label>
        <input id="org-name" v-model="formName" type="text" placeholder="Nombre de la organización" />
      </div>

      <div class="field">
        <label for="org-label">Etiqueta de Gitea</label>
        <select id="org-label" v-model="formLabelId" class="label-select">
          <option :value="null">Sin etiqueta</option>
          <option v-for="l in giteaLabels" :key="l.id" :value="l.id">{{ l.name }}</option>
        </select>
      </div>

      <div class="form-actions">
        <button class="btn-cancel" @click="closeForm">Cancelar</button>
        <button
          class="btn"
          :disabled="saving || !formName.trim()"
          @click="save"
        >
          {{ saving ? 'Guardando...' : 'Guardar' }}
        </button>
      </div>
    </div>

    <p v-if="loading" class="loading">Cargando organizaciones...</p>

    <table v-else>
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Etiqueta Gitea</th>
          <th>Usuarios</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="o in organizations" :key="o.id">
          <td class="col-id">{{ o.id }}</td>
          <td>{{ o.name }}</td>
          <td>
            <span
              v-if="o.gitea_label_name"
              class="label-chip"
              :style="{ backgroundColor: hexColor(o.gitea_label_color) }"
            >
              {{ o.gitea_label_name }}
            </span>
            <span v-else class="muted">—</span>
          </td>
          <td class="col-users">{{ o.user_count }}</td>
          <td class="col-actions">
            <button class="btn-icon" title="Editar" @click="openEdit(o)">✏️</button>
            <button class="btn-icon" title="Borrar" @click="remove(o)">🗑</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.org-list {
  max-width: 800px;
  margin: 2rem auto;
  padding: 1.5rem 2rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 10px;
}
h2 { margin: 0 0 1.25rem; font-size: 1.3rem; }
h3 { margin: 0 0 1rem; font-size: 1.05rem; color: #333; }

.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
.alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.alert p { margin: 0; }

.org-actions { margin-bottom: 1rem; }

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
.org-form {
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
.col-users { width: 80px; text-align: center; }
.col-actions { width: 96px; white-space: nowrap; }

.label-chip {
  display: inline-block;
  padding: 0.15rem 0.55rem;
  border-radius: 12px;
  font-size: 0.78rem;
  font-weight: 500;
  color: #fff;
}

.muted { color: #999; }

.btn-icon {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 0.95rem;
  padding: 0.1rem 0.3rem;
}
.btn-icon:hover { opacity: 0.7; }
</style>
