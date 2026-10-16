import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiClient } from '../src/api';

afterEach(() => vi.unstubAllGlobals());
describe('store isolation in mobile transport', () => {
  it('sends the selected store with authenticated operations', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 200 })); vi.stubGlobal('fetch', fetch);
    const api = new ApiClient(); api.setToken('phone'); api.setStore(3);
    await api.post('/tasks/1/actions', { action: 'start' });
    expect(fetch.mock.calls[0][1].headers.get('X-TaskSure-Store')).toBe('3');
    expect(fetch.mock.calls[0][1].cache).toBe('no-store');
  });
  it('discards an old-store response after a switch', async () => {
    let resolve!: (response: Response) => void;
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise<Response>(done => { resolve = done; })));
    const api = new ApiClient(); api.setStore(1);
    const pending = api.request('/tasks'); api.setStore(2);
    resolve(new Response('{"data":[{"title":"Old store private task"}]}', { status: 200 }));
    await expect(pending).rejects.toMatchObject({ code: 'STORE_CONTEXT_CHANGED' });
  });
  it('ignores an old policy or expired sign-in response after a workspace changes', async () => {
    let resolve!: (response: Response) => void;
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise<Response>(done => { resolve = done; })));
    const api = new ApiClient(); api.setStore(1); const policy = vi.fn(); api.onPolicyRequired = policy;
    const pending = api.request('/tasks'); api.setStore(2);
    resolve(new Response('{"code":"POLICY_ACKNOWLEDGEMENT_REQUIRED","policy":{"title":"Old company"}}', { status: 423 }));
    await expect(pending).rejects.toMatchObject({ code: 'STORE_CONTEXT_CHANGED' }); expect(policy).not.toHaveBeenCalled();
  });
  it('opens workspace recovery for deactivation, revocation and stale store headers', async () => {
    const api = new ApiClient(); const recovery = vi.fn(); api.onStoreUnavailable = recovery;
    for (const code of ['STORE_INACTIVE', 'STORE_ACCESS_REQUIRED', 'STORE_CONTEXT_CHANGED']) {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ code, message: 'Choose a store' }), { status: 403 })));
      await expect(api.request('/tasks')).rejects.toMatchObject({ code });
    }
    expect(recovery).toHaveBeenCalledTimes(3);
  });
});
