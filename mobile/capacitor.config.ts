import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'za.co.tasksure.employee',
  appName: 'TaskSure',
  webDir: 'dist',
  // Both native shells load local Vite assets. Railway serves API requests only.
  server: { androidScheme: 'https', iosScheme: 'capacitor' },
  plugins: { PushNotifications: { presentationOptions: ['badge', 'sound', 'alert'] } },
};

export default config;
