<script setup lang="ts">
import { ref } from 'vue'
import { register } from '../api'

const emit = defineEmits<{
  (e: 'registered'): void
  (e: 'go-login'): void
}>()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const loading = ref(false)
const error = ref('')
const success = ref(false)

async function handleSubmit() {
  error.value = ''

  if (!name.value.trim() || !email.value.trim() || !password.value.trim() || !passwordConfirm.value.trim()) {
    error.value = 'Todos los campos son obligatorios.'
    return
  }

  if (password.value.length < 6) {
    error.value = 'La contraseña debe tener al menos 6 caracteres.'
    return
  }

  if (password.value !== passwordConfirm.value) {
    error.value = 'Las contraseñas no coinciden.'
    return
  }

  loading.value = true

  const res = await register(name.value.trim(), email.value.trim(), password.value)

  if (!res.ok) {
    error.value = res.data.messages?.[0] ?? res.data.error ?? 'Error al registrarse.'
    loading.value = false
    return
  }

  success.value = true
  loading.value = false
}
</script>

<template>
  <div class="auth-form">
    <h2>Crear cuenta</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <div v-if="success" class="alert alert-success">
      <p><strong>¡Cuenta creada!</strong></p>
      <p>
        Ya puedes
        <a href="#" @click.prevent="emit('go-login')">iniciar sesión</a>.
      </p>
    </div>

    <form v-if="!success" @submit.prevent="handleSubmit" novalidate>
      <div class="field">
        <label for="name">Nombre</label>
        <input id="name" v-model="name" type="text" :disabled="loading" />
      </div>

      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" :disabled="loading" />
      </div>

      <div class="field">
        <label for="password">Contraseña</label>
        <input id="password" v-model="password" type="password" :disabled="loading" />
      </div>

      <div class="field">
        <label for="passwordConfirm">Repetir contraseña</label>
        <input id="passwordConfirm" v-model="passwordConfirm" type="password" :disabled="loading" />
      </div>

      <button type="submit" :disabled="loading" class="btn">
        {{ loading ? 'Creando cuenta...' : 'Crear cuenta' }}
      </button>
    </form>

    <p class="switch">
      ¿Ya tienes cuenta?
      <a href="#" @click.prevent="emit('go-login')">Inicia sesión</a>
    </p>
  </div>
</template>

<style scoped>
.auth-form {
  max-width: 400px;
  margin: 3rem auto;
  padding: 2rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 10px;
}
h2 {
  margin: 0 0 1.25rem;
  text-align: center;
}

.alert {
  padding: 0.75rem 1rem;
  border-radius: 6px;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}
.alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.alert p { margin: 0; }
.alert p + p { margin-top: 0.25rem; }
.alert-success a { color: #15803d; font-weight: 500; }

.field { margin-bottom: 1rem; }
.field label { display: block; margin-bottom: 0.3rem; font-size: 0.9rem; font-weight: 500; color: #333; }
.field input {
  width: 100%;
  padding: 0.55rem 0.7rem;
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.95rem;
  box-sizing: border-box;
}
.field input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }

.btn {
  width: 100%;
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

.switch {
  text-align: center;
  margin-top: 1.25rem;
  font-size: 0.9rem;
  color: #666;
}
.switch a { color: #3b82f6; text-decoration: none; }
.switch a:hover { text-decoration: underline; }

</style>
