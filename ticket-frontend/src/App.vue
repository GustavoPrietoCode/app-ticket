<script setup lang="ts">
import { ref } from 'vue'
import LoginForm from './components/LoginForm.vue'
import RegisterForm from './components/RegisterForm.vue'
import TicketForm from './components/TicketForm.vue'
import TicketList from './components/TicketList.vue'

// Directive para cerrar el dropdown al hacer clic fuera
const vClickOutside = {
  mounted(el: HTMLElement, binding: { value: () => void }) {
    (el as any)._clickOutside = (e: MouseEvent) => {
      if (!el.contains(e.target as Node)) binding.value()
    }
    document.addEventListener('click', (el as any)._clickOutside)
  },
  unmounted(el: HTMLElement) {
    document.removeEventListener('click', (el as any)._clickOutside)
  },
}

type Page = 'login' | 'register' | 'app'

interface User {
  id: number
  name: string
  email: string
  token: string
}

const savedUser = localStorage.getItem('user')
const page = ref<Page>(localStorage.getItem('token') ? 'app' : 'login')
const user = ref<User | null>(savedUser ? JSON.parse(savedUser) : null)

const ticketList = ref<InstanceType<typeof TicketList> | null>(null)
const showDropdown = ref(false)

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
  showDropdown.value = false
  localStorage.removeItem('token')
  localStorage.removeItem('user')
  user.value = null
  page.value = 'login'
}

function onTicketCreated() {
  ticketList.value?.loadTickets()
}

function toggleDropdown() {
  showDropdown.value = !showDropdown.value
}

function closeDropdown() {
  showDropdown.value = false
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

      <div class="dropdown" v-click-outside="closeDropdown">
        <button class="dropdown-toggle" @click="toggleDropdown">
          <span class="user-avatar">{{ user?.name?.charAt(0) }}</span>
          <span class="user-name">{{ user?.name }}</span>
          <span class="arrow" :class="{ open: showDropdown }">▾</span>
        </button>

        <div v-if="showDropdown" class="dropdown-menu">
          <div class="dropdown-user">
            <span class="dropdown-avatar">{{ user?.name?.charAt(0) }}</span>
            <div>
              <p class="dropdown-name">{{ user?.name }}</p>
              <p class="dropdown-email">{{ user?.email }}</p>
            </div>
          </div>
          <hr />
          <button class="dropdown-item logout" @click="logout">
            Cerrar sesión
          </button>
        </div>
      </div>
    </header>

    <main>
      <TicketList ref="ticketList" />
      <TicketForm @ticket-created="onTicketCreated" />
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

/* ─── Dropdown ─── */
.dropdown {
  position: relative;
}

.dropdown-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  color: #333;
}
.dropdown-toggle:hover {
  background: #f9f9f9;
}

.user-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #3b82f6;
  color: #fff;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
}

.user-name {
  font-weight: 500;
}

.arrow {
  font-size: 0.7rem;
  color: #888;
  transition: transform 0.15s;
}
.arrow.open {
  transform: rotate(180deg);
}

/* ─── Menú desplegable ─── */
.dropdown-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  width: auto;
  background: #fff;
  border: 1px solid #e2e2e2;
  border-radius: 8px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  z-index: 100;
  overflow: hidden;
}

.dropdown-user {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
}
.dropdown-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: #3b82f6;
  color: #fff;
  font-size: 0.95rem;
  font-weight: 600;
  flex-shrink: 0;
}
.dropdown-name {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: #1e293b;
}
.dropdown-email {
  margin: 0;
  font-size: 0.8rem;
  color: #888;
}

hr {
  margin: 0;
  border: none;
  border-top: 1px solid #f0f0f0;
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 0.65rem 1rem;
  background: none;
  border: none;
  font-size: 0.88rem;
  text-align: left;
  cursor: pointer;
  color: #333;
}
.dropdown-item:hover {
  background: #f5f5f5;
}
.dropdown-item.logout {
  color: #b91c1c;
}
</style>
