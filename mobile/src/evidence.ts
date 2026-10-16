import { Capacitor } from '@capacitor/core';
import { Directory, Filesystem } from '@capacitor/filesystem';
import { FileViewer } from '@capacitor/file-viewer';

const CACHE_DIR = 'tasksure-evidence';
let cacheRevision = 0;

export async function viewNativeDocument(blob: Blob, id: number): Promise<void> {
  if (! Capacitor.isNativePlatform()) return;
  const revision = cacheRevision;
  const base64 = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1]); reader.onerror = reject; reader.readAsDataURL(blob);
  });
  if (revision !== cacheRevision) return;
  const path = `${CACHE_DIR}/evidence-${revision}-${id}.pdf`;
  const file = await Filesystem.writeFile({ path, data: base64, directory: Directory.Cache, recursive: true });
  if (revision !== cacheRevision) {
    await Filesystem.deleteFile({ path, directory: Directory.Cache }).catch(() => undefined);
    return;
  }
  await FileViewer.openDocumentFromLocalPath({ path: file.uri });
}

export async function clearEvidenceCache(): Promise<void> {
  cacheRevision++;
  if (! Capacitor.isNativePlatform()) return;
  try { await Filesystem.rmdir({ path: CACHE_DIR, directory: Directory.Cache, recursive: true }); }
  catch { /* The private cache directory may not exist yet. */ }
}
