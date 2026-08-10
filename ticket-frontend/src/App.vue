<script setup lang="ts">
import TicketForm from './components/TicketForm.vue'
</script>

<template>
  <header>
    <h1>App Tickets</h1>
  </header>

  <main>
    <TicketForm />
  </main>
</template>

<style scoped>
header {
  text-align: center;
  padding: 1.5rem 0 0.5rem;
}
header h1 {
  font-size: 1.5rem;
  color: #1e293b;
}
</style>
