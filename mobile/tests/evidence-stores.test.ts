import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const native = vi.hoisted(() => ({ writeFile: vi.fn(), deleteFile: vi.fn(), rmdir: vi.fn(), open: vi.fn() }));
vi.mock('@capacitor/core', () => ({ Capacitor: { isNativePlatform: () => true } }));
vi.mock('@capacitor/filesystem', () => ({ Directory: { Cache: 'CACHE' }, Filesystem: native }));
vi.mock('@capacitor/file-viewer', () => ({ FileViewer: { openDocumentFromLocalPath: native.open } }));
import { clearEvidenceCache, viewNativeDocument } from '../src/evidence';

beforeEach(() => {
  vi.clearAllMocks();
  native.deleteFile.mockResolvedValue(undefined); native.rmdir.mockResolvedValue(undefined);
  vi.stubGlobal('FileReader', class {
    result = 'data:application/pdf;base64,cGRm';
    onload?: () => void;
    readAsDataURL() { this.onload?.(); }
  });
});
afterEach(() => vi.unstubAllGlobals());

it('opens an authorised document in the current workspace', async () => {
  native.writeFile.mockResolvedValue({ uri: 'private-cache/proof.pdf' });
  await viewNativeDocument(new Blob(['pdf']), 1);
  expect(native.open).toHaveBeenCalledWith({ path: 'private-cache/proof.pdf' });
});

it('removes a late private file and does not open it after switching stores or signing out', async () => {
  let finish!: (file: { uri: string }) => void;
  native.writeFile.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
  const pending = viewNativeDocument(new Blob(['old store proof']), 2);
  await vi.waitFor(() => expect(native.writeFile).toHaveBeenCalledOnce());
  await clearEvidenceCache();
  finish({ uri: 'private-cache/old-proof.pdf' }); await pending;
  expect(native.open).not.toHaveBeenCalled();
  expect(native.deleteFile).toHaveBeenCalledWith(expect.objectContaining({ path: expect.stringMatching(/evidence-\d+-2\.pdf$/) }));
});
