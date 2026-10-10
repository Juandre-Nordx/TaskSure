import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { readFileSync } from 'node:fs';
import capacitor from './capacitor.config.ts';

// A build without Firebase can still run tasks/camera. Never call native FCM
// registration without the matching client resources: it throws in Java.
let androidPushConfigured = false;
try {
  const firebase = JSON.parse(readFileSync(new URL('./android/app/google-services.json', import.meta.url), 'utf8'));
  androidPushConfigured = Boolean(firebase.project_info?.project_id) && firebase.client?.some((client: { client_info?: { android_client_info?: { package_name?: string } } }) => client.client_info?.android_client_info?.package_name === capacitor.appId) === true;
} catch { /* Firebase is optional until native push is configured. */ }

export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  define: { __ANDROID_PUSH_CONFIGURED__: JSON.stringify(androidPushConfigured) },
  // Shared modules live outside mobile/; resolve every calendar plugin against the same core.
  resolve: { dedupe: ['@fullcalendar/core', '@fullcalendar/daygrid', '@fullcalendar/timegrid', '@fullcalendar/interaction', '@fullcalendar/luxon3', 'luxon', 'preact'] },
  server: {
    fs: { allow: ['..'] },
    proxy: { '/api': { target: process.env.MOBILE_DEV_API_TARGET || 'http://127.0.0.1:8080', changeOrigin: true } },
  },
  build: { outDir: 'dist', emptyOutDir: true },
});
