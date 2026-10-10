import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiClient } from '../src/api';

afterEach(() => vi.unstubAllGlobals());
describe('mobile API transport', () => {
  it('sends bearer authorization without browser cookies', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{"data":[]}', { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    const api = new ApiClient(); api.setToken('test-token');
    await api.request('/tasks');
    const options = fetch.mock.calls[0][1];
    expect(options.headers.get('Authorization')).toBe('Bearer test-token');
    expect(options.credentials).toBe('omit');
    expect(options.cache).toBe('no-store');
  });
  it('preserves multipart boundaries for evidence uploads', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    vi.stubGlobal('fetch', fetch);
    const body = new FormData(); body.set('file', new Blob(['proof']), 'proof.jpg');
    await new ApiClient().post('/tasks/1/uploads', body);
    expect(fetch.mock.calls[0][1].body).toBe(body);
    expect(fetch.mock.calls[0][1].headers.has('Content-Type')).toBe(false);
  });
  it('shows server validation errors and clears expired sign-ins', async () => {
    const callback = vi.fn(); const api = new ApiClient(); api.onUnauthorized = callback;
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"message":"Sign in again"}', { status: 401 })));
    await expect(api.request('/me')).rejects.toThrow('Sign in again'); expect(callback).toHaveBeenCalledOnce();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"errors":{"action":["Fresh photo required"]}}', { status: 422 })));
    await expect(api.post('/tasks/1/actions', { action: 'submit' })).rejects.toThrow('Fresh photo required');
  });
  it('rejects requests that could send credentials to another origin', async () => {
    const fetch = vi.fn(); vi.stubGlobal('fetch', fetch);
    await expect(new ApiClient().request('//untrusted.example/tasks')).rejects.toThrow('Invalid API path');
    expect(fetch).not.toHaveBeenCalled();
  });
});
