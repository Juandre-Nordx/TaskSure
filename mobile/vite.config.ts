import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  // Shared modules live outside mobile/; resolve every calendar plugin against the same core.
  resolve: { dedupe: ['@fullcalendar/core', '@fullcalendar/daygrid', '@fullcalendar/timegrid', '@fullcalendar/interaction', '@fullcalendar/luxon3', 'luxon', 'preact'] },
  server: {
    fs: { allow: ['..'] },
    proxy: { '/api': { target: process.env.MOBILE_DEV_API_TARGET || 'http://127.0.0.1:8080', changeOrigin: true } },
  },
  build: { outDir: 'dist', emptyOutDir: true },
});
