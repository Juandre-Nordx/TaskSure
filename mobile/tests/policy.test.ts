import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError } from '../src/api';
import { policyScreen } from '../src/policy';
import type { CompanyPolicy } from '../src/types';

const policy: CompanyPolicy = { id: 3, version: 2, title: 'Store policy', content: 'First paragraph.\n\nFull policy content.', published_at: '2026-10-14T12:00:00Z' };
afterEach(() => vi.unstubAllGlobals());

describe('mandatory company policy', () => {
  it('opens the latest policy for a blocked request, including stale acknowledgements', async () => {
    const callback = vi.fn(); const client = new ApiClient(); client.onPolicyRequired = callback;
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ code: 'POLICY_ACKNOWLEDGEMENT_REQUIRED', policy, message: 'Acknowledge the latest version.' }), { status: 423 })));
    await expect(client.post('/company-policy/acknowledge', { policy_version_id: 1, acknowledged: true })).rejects.toMatchObject({ status: 423, code: 'POLICY_ACKNOWLEDGEMENT_REQUIRED' });
    expect(callback).toHaveBeenCalledWith(policy);
  });
  it('does not mistake a role denial for a policy requirement', async () => {
    const callback = vi.fn(); const client = new ApiClient(); client.onPolicyRequired = callback;
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"message":"Forbidden"}', { status: 403 })));
    await expect(client.request('/team/tasks')).rejects.toBeInstanceOf(ApiError);
    expect(callback).not.toHaveBeenCalled();
  });
  it('renders the whole policy with an unchecked checkbox and disabled confirmation', () => {
    const html = policyScreen(policy, true);
    expect(html).toContain('I have read and understand this company policy.');
    expect(html).toContain('type="checkbox" required');
    expect(html).not.toContain(' checked');
    expect(html).toContain('id="policy-confirm" type="submit" disabled');
    expect(html).toContain(policy.content);
    expect(html).toContain('Version 2');
    expect(html).toContain('14 Oct 2026, 14:00');
    expect(html).toContain('Sign out');
    expect(html).not.toContain('bottom-nav');
  });
  it('escapes policy text and starts every new version with fresh confirmation', () => {
    const html = policyScreen({ ...policy, id: 4, version: 3, content: '<img src=x onerror=alert(1)>' }, true);
    expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
    expect(html).not.toContain('<img src=x');
    expect(html).not.toContain(' checked');
    expect(html).toContain('Version 3');
  });
});
