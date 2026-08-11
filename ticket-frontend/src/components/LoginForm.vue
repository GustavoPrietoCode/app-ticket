<script setup lang="ts">
import { ref } from 'vue'
import { login } from '../api'

const emit = defineEmits<{
  (e: 'logged-in', user: { id: number; name: string; email: string; token: string }): void
  (e: 'go-register'): void
}>()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const showForgotMessage = ref(false)

async function handleSubmit() {
  error.value = ''

  if (!email.value.trim() || !password.value.trim()) {
    error.value = 'Todos los campos son obligatorios.'
    return
  }

  loading.value = true

  const res = await login(email.value.trim(), password.value)

  if (!res.ok) {
    error.value = res.data.messages?.[0] ?? res.data.error ?? 'Error al iniciar sesión.'
    loading.value = false
    return
  }

  localStorage.setItem('token', res.data.user.token)
  localStorage.setItem('user', JSON.stringify({
    id: res.data.user.id,
    name: res.data.user.name,
    email: res.data.user.email,
  }))
  emit('logged-in', res.data.user)
}
</script>

<template>
  <div class="auth-form">
    <h2>Iniciar sesión</h2>

    <div v-if="error" class="alert alert-error">
      <p>{{ error }}</p>
    </div>

    <form @submit.prevent="handleSubmit" novalidate>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" v-model="email" type="email" :disabled="loading" />
      </div>

      <div class="field">
        <label for="password">Contraseña</label>
        <input id="password" v-model="password" type="password" :disabled="loading" />
      </div>

      <button type="submit" :disabled="loading" class="btn">
        {{ loading ? 'Entrando...' : 'Entrar' }}
      </button>
    </form>

    <p class="forgot-password">
      <a href="#" @click.prevent="showForgotMessage = !showForgotMessage">
        He olvidado la contraseña
      </a>
    </p>

    <p v-if="showForgotMessage" class="forgot-message">
      Póngase en contacto con el administrador.
    </p>

    <p class="switch">
      ¿No tienes cuenta?
      <a href="#" @click.prevent="emit('go-register')">Regístrate</a>
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

.forgot-password {
  text-align: center;
  margin-top: 1rem;
  font-size: 0.85rem;
}
.forgot-password a { color: #888; text-decoration: none; }
.forgot-password a:hover { color: #555; text-decoration: underline; }

.forgot-message {
  text-align: center;
  margin-top: 0.5rem;
  padding: 0.5rem;
  background: #f5f5f5;
  border-radius: 6px;
  font-size: 0.85rem;
  color: #666;
}
</style>
