import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CompanyPolicy } from '../src/types';

const mocks = vi.hoisted(() => ({ blob: vi.fn(), view: vi.fn(), cache: vi.fn(), save: vi.fn() }));
vi.mock('@capacitor/core', () => ({ Capacitor: { isNativePlatform: () => true, getPlatform: () => 'android' }, registerPlugin: () => ({ savePdf: mocks.save }) }));
vi.mock('../src/api', () => ({ api: { blob: mocks.blob } }));
vi.mock('../src/evidence', () => ({ viewNativeDocument: mocks.view, cacheNativeDocument: mocks.cache }));
import { bindPolicyDocuments } from '../src/policy-documents';

const policy: CompanyPolicy = { id: 12, version: 3, title: 'PDF policy', content: '', published_at: '2026-10-16T12:00:00Z', document: { name: 'Policy.pdf', size: 32, mime: 'application/pdf', url: '/company-policy/versions/12/pdf' } };
const pdf = new Blob(['%PDF-1.4\n%%EOF'], { type: 'application/pdf' });

function screen(current = () => true) {
  const status = { textContent: '' };
  const buttons = [{ id: 'policy-read', disabled: false, onclick: undefined as undefined | (() => Promise<void>) }, { id: 'policy-download', disabled: false, onclick: undefined as undefined | (() => Promise<void>) }];
  const root = { querySelector: () => status, querySelectorAll: () => buttons } as unknown as HTMLElement;
  bindPolicyDocuments(root, policy, current);
  return { read: buttons[0], download: buttons[1], status };
}
beforeEach(() => {
  vi.resetAllMocks();
  mocks.blob.mockResolvedValue(pdf); mocks.view.mockResolvedValue(undefined);
  mocks.cache.mockResolvedValue('file:///private/cache/tasksure-evidence/policy.pdf'); mocks.save.mockResolvedValue({ saved: true });
});

describe('authenticated policy PDF actions', () => {
  it('fetches privately for each read and uses the existing native viewer', async () => {
    const ui = screen();
    await ui.read.onclick!(); await ui.read.onclick!();
    expect(mocks.blob).toHaveBeenCalledTimes(2);
    expect(mocks.blob).toHaveBeenCalledWith('/company-policy/versions/12/pdf');
    expect(mocks.view).toHaveBeenCalledWith(pdf, -12);
    expect(mocks.save).not.toHaveBeenCalled();
    expect(ui.read.disabled).toBe(false);
  });
  it('keeps download available when no PDF viewer can open the document', async () => {
    mocks.view.mockRejectedValue(new Error('No reader'));
    const ui = screen(); await ui.read.onclick!();
    expect(ui.status.textContent).toContain('Choose Download PDF');
    expect(ui.download.disabled).toBe(false);
    await ui.download.onclick!();
    expect(mocks.save).toHaveBeenCalledWith({ path: 'file:///private/cache/tasksure-evidence/policy.pdf', name: 'company-policy-v3.pdf' });
    expect(ui.status.textContent).toContain('PDF saved');
  });
  it('reports cancellation without acknowledging or opening a document', async () => {
    mocks.save.mockResolvedValue({ saved: false });
    const ui = screen(); await ui.download.onclick!();
    expect(ui.status.textContent).toContain('Download cancelled');
    expect(mocks.view).not.toHaveBeenCalled();
    expect(ui.download.disabled).toBe(false);
  });
  it('does not open or export a response for a workspace or policy screen that changed', async () => {
    const ui = screen(() => false); await ui.read.onclick!(); await ui.download.onclick!();
    expect(mocks.view).not.toHaveBeenCalled(); expect(mocks.cache).not.toHaveBeenCalled(); expect(mocks.save).not.toHaveBeenCalled();
  });
  it('does not export a login/error response as a PDF or use a cached offline copy', async () => {
    mocks.blob.mockResolvedValue(new Blob(['Forbidden'], { type: 'application/json' }));
    const ui = screen(); await ui.download.onclick!();
    expect(ui.status.textContent).toContain('policy PDF is unavailable');
    expect(mocks.save).not.toHaveBeenCalled();
    mocks.blob.mockRejectedValue(new Error('Could not connect'));
    await ui.download.onclick!();
    expect(ui.status.textContent).toContain('Could not connect');
    expect(mocks.cache).not.toHaveBeenCalled();
  });
});
