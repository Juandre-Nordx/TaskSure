import { responseError } from '../../resources/shared/errors.js';
import type { CompanyPolicy } from './types';

export class ApiError extends Error {
  constructor(message: string, public status: number, public code?: string) { super(message); }
}

export const API_URL = (import.meta.env.VITE_API_URL || (import.meta.env.DEV ? '/api/mobile/v1' : 'https://tasksure-production.up.railway.app/api/mobile/v1')).replace(/\/$/, '');
if (import.meta.env.PROD && ! API_URL.startsWith('https://')) throw new Error('The production API must use HTTPS.');

export class ApiClient {
  private token: string | null = null;
  private storeId: number | null = null;
  private revision = 0;
  onUnauthorized: (() => void) | undefined;
  onPolicyRequired: ((policy: CompanyPolicy) => void) | undefined;
  onStoreUnavailable: ((message: string) => void) | undefined;
  setToken(token: string | null) { if (token !== this.token) this.revision++; this.token = token; }
  setStore(id: number | null) { if (id !== this.storeId) this.revision++; this.storeId = id; }
  getStore() { return this.storeId; }
  private checkRevision(revision: number) { if (revision !== this.revision) throw new ApiError('Your workspace changed. Refresh to continue.', 409, 'STORE_CONTEXT_CHANGED'); }

  async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const revision = this.revision;
    const response = await this.fetch(path, options);
    const result = await response.json() as T;
    this.checkRevision(revision);
    return result;
  }

  async blob(path: string): Promise<Blob> {
    const revision = this.revision;
    const result = await (await this.fetch(path)).blob();
    this.checkRevision(revision);
    return result;
  }

  private async fetch(path: string, options: RequestInit = {}): Promise<Response> {
    // Callers supply API-relative paths; authorization must never go to another origin.
    if (! path.startsWith('/') || path.startsWith('//')) throw new Error('Invalid API path.');
    const headers = new Headers(options.headers);
    const revision = this.revision;
    headers.set('Accept', 'application/json');
    if (this.token) headers.set('Authorization', `Bearer ${this.token}`);
    if (this.storeId !== null) headers.set('X-TaskSure-Store', String(this.storeId));
    if (options.body && ! (options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response: Response;
    try {
      response = await fetch(API_URL + path, { ...options, headers, credentials: 'omit', cache: 'no-store', signal: options.signal || AbortSignal.timeout(60000) });
    } catch {
      throw new ApiError('Could not connect. Check your internet connection and try again.', 0);
    }
    this.checkRevision(revision);
    if (! response.ok) {
      const data = await response.json().catch(() => null);
      this.checkRevision(revision);
      if (response.status === 401) this.onUnauthorized?.();
      if (data?.code === 'POLICY_ACKNOWLEDGEMENT_REQUIRED' && data.policy) this.onPolicyRequired?.(data.policy);
      if (['STORE_CONTEXT_CHANGED', 'STORE_ACCESS_REQUIRED', 'STORE_INACTIVE'].includes(data?.code)) this.onStoreUnavailable?.(data.message);
      throw new ApiError(responseError(data, `Request failed (${response.status}).`), response.status, data?.code);
    }
    return response;
  }

  post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.request<T>(path, { method: 'POST', body: body instanceof FormData ? body : JSON.stringify(body) });
  }
}

export const api = new ApiClient();
