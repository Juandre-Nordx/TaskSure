import { escape as e, date } from './utils';
import type { CompanyPolicy, PolicyState } from './types';

function policyBody(policy: CompanyPolicy, required: boolean, acknowledgedAt: string | null = null, continueAllowed = true): string {
  const content = policy.document
    ? `<div class="policy-pdf"><p class="field-help">PDF · ${e(policy.document.name)} · ${Math.ceil(policy.document.size / 1024)} KB</p><div class="actions mt-4"><button class="btn primary" type="button" id="policy-read">Read policy</button><button class="btn" type="button" id="policy-download">Download PDF</button></div><p class="field-help mt-3">If viewing is unavailable, download the PDF and open it in a PDF reader. Reading or downloading does not record an acknowledgement.</p><p id="policy-document-status" class="field-help" role="status"></p></div>`
    : `<div class="policy-content note" tabindex="0" role="region" aria-label="Full company policy">${e(policy.content)}</div>`;
  return `<p class="eyebrow mt-5">COMPANY POLICY</p><h2 id="policy-title">${e(policy.title)}</h2><p class="field-help">Version ${policy.version} · Published ${e(date(policy.published_at))} · SAST</p>${required ? '<p class="muted mt-4">Read the full policy and acknowledge this version before continuing.</p>' : ''}${content}${required ? `<form id="policy-ack-form"><label class="check"><input id="policy-acknowledged" name="acknowledged" type="checkbox" required>I have read and understand this company policy.</label><button class="btn primary full mt-4" id="policy-confirm" type="submit" disabled>Confirm and continue</button></form>` : `<p class="field-help">${acknowledgedAt ? 'Acknowledged '+e(date(acknowledgedAt))+' · SAST' : ''}</p>${continueAllowed ? '<button class="btn primary full mt-4" id="policy-continue">Continue to TaskSure</button>' : ''}`}`;
}

function shell(body: string, labelId: string): string {
  return `<main class="policy-gate"><section class="card policy-dialog" role="dialog" aria-modal="true" aria-labelledby="${labelId}"><div class="card-head"><div class="brand"><img class="brandmark" src="./tasksure-icon.svg" alt="" width="35" height="35">TaskSure</div><button class="btn small" id="policy-logout">Sign out</button></div>${body}<p id="policy-error" class="error" role="alert"></p><button class="btn full mt-3" id="policy-reload">Check current policies</button></section></main>`;
}

export function policyScreen(policy: CompanyPolicy, required: boolean, acknowledgedAt: string | null = null): string {
  return shell(policyBody(policy, required, acknowledgedAt), 'policy-title');
}

export function policyLibraryScreen(state: PolicyState): string {
  const policies = state.policies ?? (state.policy ? [{ ...state.policy, required: state.required, acknowledged_at: state.acknowledged_at }] : []);
  const outstanding = state.outstanding_count ?? policies.filter(policy => policy.required).length;
  const list = policies.map(policy => `<a class="policy-list-item ${policy.required ? 'outstanding' : ''}" href="#/company-policy/${policy.policy_id ?? policy.id}" ${policy.id === state.policy?.id ? 'aria-current="page"' : ''}><strong>${e(policy.title)}</strong><span class="field-help">Version ${policy.version} · ${policy.required ? 'Outstanding' : policy.acknowledged_at ? 'Acknowledged' : 'Read policy'}</span><span class="field-help">Published ${e(date(policy.published_at))} · SAST</span></a>`).join('');
  return shell(`<h1 class="mt-5" id="policy-library-title">Company policies</h1>${state.required ? `<p class="notice mt-4">${outstanding} ${outstanding === 1 ? 'policy requires' : 'policies require'} your acknowledgement. Confirm every outstanding policy to continue.</p>` : '<p class="field-help mt-3">Read your company policies and review your acknowledgements.</p>'}<nav class="policy-list mt-4" aria-label="Company policies">${list || '<p class="field-help">No company policy has been published.</p>'}</nav>${state.policy ? policyBody(state.policy, state.selected_required ?? state.required, state.acknowledged_at, !state.required) : '<button class="btn primary full mt-4" id="policy-continue">Continue to TaskSure</button>'}`, 'policy-library-title');
}
