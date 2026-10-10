import { Capacitor, type PluginListenerHandle } from '@capacitor/core';
import { Camera, type MediaResult } from '@capacitor/camera';
import { PushNotifications } from '@capacitor/push-notifications';
import { Preferences } from '@capacitor/preferences';
import { api } from './api';

const PUSH_KEY = 'tasksure-push-enabled';
const CAMERA_KEY = 'tasksure-camera-task';
let listeners: PluginListenerHandle[] = [];
let permissionResolve: ((message: string) => void) | undefined;

export async function photoFile(photo: MediaResult): Promise<File> {
  if (! photo.webPath) throw new Error('The camera did not return a photo.');
  const blob = await (await fetch(photo.webPath)).blob();
  return new File([blob], `task-proof-${Date.now()}.jpg`, { type: blob.type || 'image/jpeg' });
}

export async function capturePhoto(taskId: number, userId: number): Promise<File> {
  await Preferences.set({ key: CAMERA_KEY, value: JSON.stringify({ taskId, userId }) });
  try { return await photoFile(await Camera.takePhoto({ quality: 80, targetWidth: 1920, targetHeight: 1920, correctOrientation: true, saveToGallery: false })); }
  finally { await Preferences.remove({ key: CAMERA_KEY }); }
}

export async function pendingCameraTask(): Promise<{ taskId: number; userId: number } | null> {
  const value = (await Preferences.get({ key: CAMERA_KEY })).value;
  try { return value ? JSON.parse(value) : null; } catch { return null; }
}

export async function clearCameraTask(): Promise<void> {
  await Preferences.remove({ key: CAMERA_KEY });
}

export async function pushEnabled(): Promise<boolean> {
  return (await Preferences.get({ key: PUSH_KEY })).value === 'true';
}

export async function setUpPush(onUpdate: () => void, onOpen: (taskId?: string) => void, onError: (message: string) => void): Promise<void> {
  if (! Capacitor.isNativePlatform() || listeners.length) return;
  const platform = Capacitor.getPlatform();
  listeners = await Promise.all([
    PushNotifications.addListener('registration', async token => {
      try {
        await api.post('/devices', { platform, token: token.value });
        await Preferences.set({ key: PUSH_KEY, value: 'true' });
        permissionResolve?.('Notifications enabled.');
      } catch (error) {
        await Preferences.set({ key: PUSH_KEY, value: 'false' });
        const message = error instanceof Error ? error.message : 'Push registration failed.';
        permissionResolve?.(message);
        onError(message);
      } finally { permissionResolve = undefined; }
    }),
    PushNotifications.addListener('registrationError', () => {
      const message = 'Push registration failed. Check notification permissions and native configuration.';
      permissionResolve?.(message); permissionResolve = undefined;
      void Preferences.set({ key: PUSH_KEY, value: 'false' });
      onError(message);
    }),
    PushNotifications.addListener('pushNotificationReceived', () => onUpdate()),
    PushNotifications.addListener('pushNotificationActionPerformed', event => {
      const id = String(event.notification.data?.task_id || '');
      onOpen(/^\d+$/.test(id) ? id : undefined);
    }),
  ]);
}

export async function enablePush(requestPermission = true): Promise<string> {
  if (! Capacitor.isNativePlatform()) return 'Push notifications are available in the Android and iOS app.';
  let permission = await PushNotifications.checkPermissions();
  if (requestPermission && (permission.receive === 'prompt' || permission.receive === 'prompt-with-rationale')) permission = await PushNotifications.requestPermissions();
  if (permission.receive !== 'granted') return 'Notifications are blocked. Enable them in your phone settings.';
  const result = new Promise<string>(resolve => {
    permissionResolve = resolve;
    setTimeout(() => { if (permissionResolve === resolve) { permissionResolve = undefined; resolve('Registration is taking longer than expected. Try again shortly.'); } }, 15000);
  });
  await PushNotifications.register();
  return result;
}

export async function disablePush(): Promise<void> {
  await api.request('/devices', { method: 'DELETE' });
  await Preferences.set({ key: PUSH_KEY, value: 'false' });
}

export async function stopPush(): Promise<void> {
  const current = listeners; listeners = [];
  permissionResolve?.('Signed out.'); permissionResolve = undefined;
  await Promise.all(current.map(listener => listener.remove()));
}
