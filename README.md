# app-ticket

Aplicación de gestión de tickets donde los clientes pueden crear y hacer seguimiento de sus solicitudes.

## Stack

- **Backend:** PHP (FlightPHP) + MySQL — servidor local con XAMPP
- **Frontend:** Vue 3 + TypeScript + Vite

## Requisitos

- XAMPP (Apache + MySQL)
- Node.js (ver `ticket-frontend/package.json` para la versión requerida)
- Composer

## Arranque en local

1. Iniciar Apache y MySQL desde XAMPP
2. Backend: `php -S localhost:8080 index.php` desde `ticket-backend/`
3. Frontend: `npm run dev` desde `ticket-frontend/`
