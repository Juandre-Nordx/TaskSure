import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { FileViewer } from '@capacitor/file-viewer';

const CACHE_DIR = 'tasksure-evidence';

export async function viewNativeDocument(blob: Blob, id: number): Promise<void> {
  if (! Capacitor.isNativePlatform()) return;
  const base64 = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1]); reader.onerror = reject; reader.readAsDataURL(blob);
  });
  const file = await Filesystem.writeFile({ path: `${CACHE_DIR}/evidence-${id}.pdf`, data: base64, directory: Directory.Cache, recursive: true });
  await FileViewer.openDocumentFromLocalPath({ path: file.uri });
}

export async function clearEvidenceCache(): Promise<void> {
  if (! Capacitor.isNativePlatform()) return;
  try { await Filesystem.rmdir({ path: CACHE_DIR, directory: Directory.Cache, recursive: true }); }
  catch { /* The private cache directory may not exist yet. */ }
}
