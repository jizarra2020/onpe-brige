# ONPE Live Bridge - Microservicio Serverless Gratuito (Vercel)

Este microservicio permite consultar datos oficiales en vivo directamente de la plataforma de la ONPE (`https://consultaelectoral.onpe.gob.pe`) sin necesidad de instalar Chrome ni paquetes de Linux en tu servidor cPanel.

---

## 🚀 Despliegue en Vercel (Gratis en 2 minutos)

### Método 1: Despliegue con Vercel CLI (Más rápido)
1. Abre tu terminal en esta carpeta:
   ```bash
   cd onpe-bridge-vercel
   ```
2. Ejecuta:
   ```bash
   npx vercel
   ```
3. Sigue las instrucciones en pantalla (presiona `Y` y `Enter` a todo).
4. Vercel te entregará una URL como:
   `https://onpe-live-bridge-tu-usuario.vercel.app`

---

### Método 2: Despliegue mediante GitHub
1. Crea un repositorio en tu GitHub (ej. `onpe-bridge`).
2. Sube el contenido de la carpeta `onpe-bridge-vercel` a ese repositorio.
3. Entra a [https://vercel.com](https://vercel.com) (inicia sesión con GitHub).
4. Haz clic en **"Add New..."** ➔ **"Project"** ➔ Selecciona tu repositorio y haz clic en **"Deploy"**.
5. ¡Listo! Vercel te dará tu URL pública permanente.

---

## ⚙️ Conectar con tu Laravel en cPanel

Una vez que tengas tu URL de Vercel, abre el archivo `.env` en tu cPanel y coloca:

```env
ONPE_ENABLED=true
ONPE_DRIVER=api
ONPE_API_URL=https://tu-proyecto.vercel.app/api/onpe/{dni}
```

Luego ejecuta en la terminal de cPanel:
```bash
php artisan config:clear
```
¡Tu formulario en cPanel consultará directamente a la ONPE sin costo alguno!
