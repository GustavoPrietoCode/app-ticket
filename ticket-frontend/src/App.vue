<script setup lang="ts">
import { ref } from 'vue'
import LoginForm from './components/LoginForm.vue'
import RegisterForm from './components/RegisterForm.vue'
import TicketForm from './components/TicketForm.vue'
import TicketList from './components/TicketList.vue'

type Page = 'login' | 'register' | 'app'

interface User {
  id: number
  name: string
  email: string
  token: string
}

const page = ref<Page>(localStorage.getItem('token') ? 'app' : 'login')
const user = ref<User | null>(null)

const ticketList = ref<InstanceType<typeof TicketList> | null>(null)

function onLoggedIn(loggedUser: User) {
  user.value = loggedUser
  page.value = 'app'
}

function onGoRegister() {
  page.value = 'register'
}

function onGoLogin() {
  page.value = 'login'
}

function onRegistered() {
  page.value = 'login'
}

function logout() {
  localStorage.removeItem('token')
  user.value = null
  page.value = 'login'
}

function onTicketCreated() {
  ticketList.value?.loadTickets()
}
</script>

<template>
  <!-- Auth: Login / Registro -->
  <template v-if="page !== 'app'">
    <header>
      <h1>App Tickets</h1>
    </header>
    <main>
      <LoginForm
        v-if="page === 'login'"
        @logged-in="onLoggedIn"
        @go-register="onGoRegister"
      />
      <RegisterForm
        v-else
        @registered="onRegistered"
        @go-login="onGoLogin"
      />
    </main>
  </template>

  <!-- App principal (autenticado) -->
  <template v-else>
    <header class="app-header">
      <h1>App Tickets</h1>
      <div class="user-bar">
        <span v-if="user" class="user-name">{{ user.name }}</span>
        <button class="btn-logout" @click="logout">Salir</button>
      </div>
    </header>

    <main>
      <TicketForm @ticket-created="onTicketCreated" />
      <TicketList ref="ticketList" />
    </main>
  </template>
</template>

<style scoped>
/* ─── Header (público) ─── */
header {
  text-align: center;
  padding: 1.5rem 0 0.5rem;
}
header h1 {
  font-size: 1.5rem;
  color: #1e293b;
}

/* ─── Header (app) ─── */
.app-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 800px;
  margin: 0 auto;
  padding: 1.5rem 2rem 0.5rem;
}
.app-header h1 {
  font-size: 1.5rem;
  color: #1e293b;
}
.user-bar {
  display: flex;
  align-items: center;
  gap: 1rem;
}
.user-name {
  font-size: 0.9rem;
  color: #555;
  font-weight: 500;
}
.btn-logout {
  padding: 0.35rem 1rem;
  font-size: 0.85rem;
  color: #b91c1c;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  cursor: pointer;
}
.btn-logout:hover {
  background: #fee2e2;
}
</style>
