import { Capacitor } from '@capacitor/core';
import { KeychainAccess, SecureStorage } from '@aparajita/capacitor-secure-storage';
import { api } from './api';

const KEY = 'tasksure-access-token';
async function prepareStorage(): Promise<void> {
  await SecureStorage.setKeyPrefix('tasksure_');
  await SecureStorage.setSynchronize(false);
  await SecureStorage.setDefaultKeychainAccess(KeychainAccess.whenUnlockedThisDeviceOnly);
}
// Native tokens use Keychain / Android encrypted storage. Browser previews keep tokens in memory.
export async function restoreSession(): Promise<boolean> {
  if (! Capacitor.isNativePlatform()) return false;
  await prepareStorage();
  const token = await SecureStorage.get(KEY);
  if (typeof token !== 'string' || ! token) return false;
  api.setToken(token);
  return true;
}

export async function saveSession(token: string): Promise<void> {
  if (Capacitor.isNativePlatform()) { await prepareStorage(); await SecureStorage.set(KEY, token); }
  api.setToken(token);
}

export async function clearSession(): Promise<void> {
  api.setToken(null);
  if (Capacitor.isNativePlatform()) await SecureStorage.remove(KEY);
}
