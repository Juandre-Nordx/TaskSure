import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiClient, ApiError } from '../src/api';
import { policyLibraryScreen, policyScreen } from '../src/policy';
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
  it('offers PDF reading and download while keeping confirmation unchecked and locked', () => {
    const html = policyScreen({ ...policy, content: '', document: { name: '<Policy>.pdf', size: 2048, mime: 'application/pdf', url: '/company-policy/versions/3/pdf' } }, true);
    expect(html).toContain('Read policy');
    expect(html).toContain('Download PDF');
    expect(html).toContain('&lt;Policy&gt;.pdf');
    expect(html).toContain('Version 2');
    expect(html).toContain('14 Oct 2026, 14:00');
    expect(html).toContain('id="policy-confirm" type="submit" disabled');
    expect(html).not.toContain(' checked');
    expect(html).not.toContain('bottom-nav');
    expect(html).toContain('Reading or downloading does not record an acknowledgement.');
  });
});

describe('company policy library', () => {
  const first = { ...policy, policy_id: 10, required: false, acknowledged_at: '2026-10-15T12:00:00Z' };
  const second = { ...policy, id: 7, policy_id: 11, title: 'Safety <policy>', version: 1, required: true, acknowledged_at: null };

  it('lists separate policies and locks the selected outstanding version with a fresh checkbox', () => {
    const html = policyLibraryScreen({ policies: [first, second], policy: second, required: true, outstanding_count: 1, selected_required: true, acknowledged_at: null });
    expect(html).toContain('Company policies');
    expect(html).toContain('href="#/company-policy/10"');
    expect(html).toContain('href="#/company-policy/11" aria-current="page"');
    expect(html).toContain('Acknowledged');
    expect(html).toContain('Outstanding');
    expect(html).toContain('Safety &lt;policy&gt;');
    expect(html).not.toContain('Safety <policy>');
    expect(html).toContain('1 policy requires your acknowledgement');
    expect(html).toContain('Version 1');
    expect(html).toContain('type="checkbox" required');
    expect(html).not.toContain(' checked');
    expect(html).toContain('id="policy-confirm" type="submit" disabled');
    expect(html).not.toContain('id="policy-continue"');
  });

  it('lets staff reread an acknowledged policy while another is pending without offering normal access', () => {
    const html = policyLibraryScreen({ policies: [first, second], policy: first, required: true, outstanding_count: 1, selected_required: false, acknowledged_at: first.acknowledged_at });
    expect(html).toContain('Acknowledged 15 Oct 2026, 14:00');
    expect(html).toContain('Sign out');
    expect(html).not.toContain('id="policy-confirm"');
    expect(html).not.toContain('id="policy-continue"');
    expect(html).not.toContain('bottom-nav');
  });

  it('shows multiple pending policies and only allows continuing once all are acknowledged', () => {
    const pending = policyLibraryScreen({ policies: [{ ...first, required: true, acknowledged_at: null }, second], policy: second, required: true, outstanding_count: 2, acknowledged_at: null });
    expect(pending).toContain('2 policies require your acknowledgement');
    const complete = policyLibraryScreen({ policies: [first, { ...second, required: false, acknowledged_at: first.acknowledged_at }], policy: first, required: false, outstanding_count: 0, selected_required: false, acknowledged_at: first.acknowledged_at });
    expect(complete).toContain('id="policy-continue"');
    expect(complete).not.toContain('id="policy-confirm"');
  });

  it('allows a company without published policies and supports the older API response', () => {
    const empty = policyLibraryScreen({ policies: [], policy: null, required: false, acknowledged_at: null });
    expect(empty).toContain('No company policy has been published.');
    expect(empty).toContain('id="policy-continue"');
    const legacy = policyLibraryScreen({ policy, required: true, acknowledged_at: null });
    expect(legacy).toContain(policy.title);
    expect(legacy).toContain('id="policy-confirm" type="submit" disabled');
    expect(legacy).not.toContain(' checked');
  });
});
