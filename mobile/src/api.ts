import { responseError } from '../../resources/shared/errors.js';

export class ApiError extends Error {
  constructor(message: string, public status: number) { super(message); }
}

export const API_URL = (import.meta.env.VITE_API_URL || (import.meta.env.DEV ? '/api/mobile/v1' : 'https://tasksure-production.up.railway.app/api/mobile/v1')).replace(/\/$/, '');
if (import.meta.env.PROD && ! API_URL.startsWith('https://')) throw new Error('The production API must use HTTPS.');

export class ApiClient {
  private token: string | null = null;
  onUnauthorized: (() => void) | undefined;
  setToken(token: string | null) { this.token = token; }

  async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const response = await this.fetch(path, options);
    return response.json() as Promise<T>;
  }

  async blob(path: string): Promise<Blob> {
    return (await this.fetch(path)).blob();
  }

  private async fetch(path: string, options: RequestInit = {}): Promise<Response> {
    // Callers supply API-relative paths; authorization must never go to another origin.
    if (! path.startsWith('/') || path.startsWith('//')) throw new Error('Invalid API path.');
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (this.token) headers.set('Authorization', `Bearer ${this.token}`);
    if (options.body && ! (options.body instanceof FormData)) headers.set('Content-Type', 'application/json');
    let response: Response;
    try {
      response = await fetch(API_URL + path, { ...options, headers, credentials: 'omit', cache: 'no-store', signal: options.signal || AbortSignal.timeout(60000) });
    } catch {
      throw new ApiError('Could not connect. Check your internet connection and try again.', 0);
    }
    if (! response.ok) {
      const data = await response.json().catch(() => null);
      if (response.status === 401) this.onUnauthorized?.();
      throw new ApiError(responseError(data, `Request failed (${response.status}).`), response.status);
    }
    return response;
  }

  post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.request<T>(path, { method: 'POST', body: body instanceof FormData ? body : JSON.stringify(body) });
  }
}

export const api = new ApiClient();
