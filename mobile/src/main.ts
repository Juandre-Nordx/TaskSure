import './styles.css';
import { Capacitor } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { Calendar, type EventInput } from '@fullcalendar/core';
import { DateTime } from 'luxon';
import { calendarOptions, BUSINESS_TIMEZONE, plannedOccurrenceText } from '../../resources/shared/calendar.js';
import { api, ApiError, API_URL } from './api';
import { restoreSession, saveSession, clearSession } from './session';
import { capturePhoto, photoFile, pendingCameraTask, clearCameraTask, setUpPush, enablePush, disablePush, pushEnabled, stopPush } from './native';
import type { MediaResult } from '@capacitor/camera';
import { clearEvidenceCache, viewNativeDocument } from './evidence';
import { policyScreen } from './policy';
import { escape as e, date, today, label, canWork, missingEvidence } from './utils';
import type { Profile, LoginResponse, Task, TaskDetails, Page, Alert, Attachment, TeamOptions, CompanyPolicy, PolicyState } from './types';

const root = document.querySelector<HTMLDivElement>('#app')!;
let profile: Profile | null = null;
let calendar: Calendar | undefined;
let pageVersion = 0;
let taskFilter = 'open';
let taskSearch = '';
let notes = new Map<number, string>();
let objectUrls = new Set<string>();
let toastTimer: ReturnType<typeof setTimeout>;
let loggingOut = false;
let teamFilters = new URLSearchParams();
let reviewFilters = new URLSearchParams();
let policyLocked = false;
let pushReady = false;
let restoredPhoto: { taskId: number; userId: number; storeId: number | null; data: MediaResult } | null = null;
let retainedPhoto: { taskId: number; file: File } | null = null;

const icons: Record<string, string> = {
  tasks: '<path d="M9 5h11v15H4V5h2M9 3h6v4H9zM8 12l2 2 5-5M8 18h8"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 11h18M8 15h2M14 15h2"/>',
  submissions: '<path d="M4 3h12l4 4v14H4zM15 3v5h5M8 14l3 3 5-6"/>',
  notifications: '<path d="M6 9a6 6 0 0112 0v6l2 3H4l2-3zM10 21h4"/>',
  settings: '<path d="M12 8a4 4 0 100 8 4 4 0 000-8zM9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z"/>',
};
const icon = (name: string) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.tasks}</svg>`;
const message = (error: unknown) => error instanceof Error ? error.message : 'Something went wrong. Please try again.';
const navigate = (path: string) => { if (location.hash === '#' + path) void render(); else location.hash = path; };
function toast(text: string) {
  const el = document.querySelector<HTMLDivElement>('#toast')!;
  el.textContent = text; el.hidden = false; clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, 6000);
}
function releasePage() {
  calendar?.destroy(); calendar = undefined;
  objectUrls.forEach(url => URL.revokeObjectURL(url)); objectUrls.clear();
}
const blobUrl = (blob: Blob) => { const url = URL.createObjectURL(blob); objectUrls.add(url); return url; };
const pagehead = (title: string, subtitle: string) => `<div class="pagehead"><div><p class="eyebrow">YOUR WORK · SAST</p><h1>${e(title)}</h1><p class="muted">${e(subtitle)}</p></div><button class="btn small" id="refresh" aria-label="Refresh page">Refresh</button></div>`;
const empty = (text: string) => `<div class="card empty">${e(text)}</div>`;

function shell(active: string) {
  const tabs = profile!.user.role === 'manager' ? ['tasks', 'team', 'calendar', 'notifications', 'settings'] : ['tasks', 'calendar', 'submissions', 'notifications', 'settings'];
  const initials = profile!.user.name.split(' ').map(v => v[0]).slice(0, 2).join('');
  root.innerHTML = `<div class="app-shell"><header class="topbar"><a href="#/tasks" class="brand"><img class="brandmark" src="./tasksure-icon.svg" alt="" width="35" height="35">TaskSure</a><a href="#/settings" aria-label="Account settings" class="avatar">${e(initials)}</a></header><div class="workspace-banner">${e(profile!.store?.company_name)} · ${e(profile!.store?.name)}</div><main class="content" id="content" tabindex="-1"><p class="muted" role="status">Loading your work…</p></main><nav class="bottom-nav" aria-label="Main navigation">${tabs.map(tab => `<a href="#/${tab}" class="${active === tab ? 'current' : ''}" ${active === tab ? 'aria-current="page"' : ''}>${icon(tab)}${({ tasks: 'My tasks', team: 'Team tasks', submissions: 'History', notifications: 'Updates' } as Record<string, string>)[tab] || tab[0].toUpperCase() + tab.slice(1)}</a>`).join('')}</nav></div>`;
}

function login() {
  releasePage(); pageVersion++;
  root.innerHTML = `<main class="auth"><div class="auth-intro"><div class="brand"><img class="brandmark" src="./tasksure-icon.svg" alt="" width="35" height="35">TaskSure</div><h1>A clear plan.<br>A job well done.</h1><p>Your tasks, deadlines and proof of work.<br>All together, wherever your shift takes you.</p></div><section class="auth-form"><p class="eyebrow">MANAGER & EMPLOYEE SIGN IN</p><h2>Welcome back</h2><p class="muted mt-2">Use the account provided by your store owner.</p><form id="login"><div><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" required></div><div><label for="password">Password</label><div class="password-row"><input id="password" name="password" type="password" autocomplete="current-password" required><button class="btn small" type="button" id="show-password" aria-label="Show password">Show</button></div></div><p id="login-error" class="error" role="alert"></p><button class="btn primary full" type="submit">Sign in →</button></form><button class="btn full mt-5" id="forgot">Forgot password?</button><p class="field-help mt-4">Managers and employees can sign in here. Store owners use the web dashboard.</p></section></main>`;
  document.querySelector<HTMLButtonElement>('#show-password')!.onclick = event => {
    const input = document.querySelector<HTMLInputElement>('#password')!;
    input.type = input.type === 'password' ? 'text' : 'password';
    const button = event.currentTarget as HTMLButtonElement; button.textContent = input.type === 'password' ? 'Show' : 'Hide';
    button.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
  };
  document.querySelector<HTMLButtonElement>('#forgot')!.onclick = () => {
    const base = new URL(API_URL, location.origin); base.pathname = '/forgot-password';
    if (Capacitor.isNativePlatform()) void Browser.open({ url: base.href });
    else window.open(base.href, '_blank', 'noopener,noreferrer');
  };
  document.querySelector<HTMLFormElement>('#login')!.onsubmit = async event => {
    event.preventDefault(); const form = event.currentTarget as HTMLFormElement;
    const button = form.querySelector<HTMLButtonElement>('[type=submit]')!; button.disabled = true;
    const error = form.querySelector<HTMLElement>('#login-error')!; error.textContent = '';
    try {
      const data = await api.post<LoginResponse>('/login', { email: (form.elements.namedItem('email') as HTMLInputElement).value.trim(), password: (form.elements.namedItem('password') as HTMLInputElement).value, device_name: `TaskSure ${Capacitor.getPlatform()}` });
      // If secure storage fails, revoke the newly issued token rather than leaving a stray session.
      api.setToken(data.token);
      try { await saveSession(data.token); } catch { await api.post('/logout'); throw new Error('Could not save a secure sign-in on this phone.'); }
      profile = data; api.setStore(data.store?.id ?? null); form.reset();
      location.hash = '/tasks'; await render();
    } catch (err) { error.textContent = message(err); }
    finally { button.disabled = false; }
  };
}

async function initializePush() {
  await setUpPush(() => toast('A new task update arrived. Refresh to view it.'), taskId => navigate(taskId ? `/tasks/${taskId}` : '/notifications'), toast);
  if (profile?.config.push_enabled && await pushEnabled()) void enablePush(false).catch(error => toast(message(error)));
}

async function signOut() {
  if (loggingOut) return;
  loggingOut = true;
  try {
    await api.post('/logout');
    await localSignOut();
  } catch (error) {
    // A network failure must not pretend that the server session and push subscription were revoked.
    if (error instanceof ApiError && error.status === 401) await localSignOut();
    else toast('Sign-out could not reach the server. Connect to the internet and try again.');
  } finally { loggingOut = false; }
}

async function localSignOut() {
  profile = null; api.setStore(null); policyLocked = false; pushReady = false; notes.clear(); teamFilters = new URLSearchParams(); reviewFilters = new URLSearchParams(); retainedPhoto = null; restoredPhoto = null; releasePage();
  await stopPush(); await clearEvidenceCache(); await clearCameraTask(); await clearSession(); login();
}
api.onUnauthorized = () => { if (profile) { void localSignOut(); toast('Your sign-in expired. Please sign in again.'); } };
api.onPolicyRequired = policy => { if (profile) showPolicy(policy, true); };

function storeChoices(data: Profile): string {
  return `<form id="store-choice" class="field"><label for="chosen-store">Store</label><select id="chosen-store" name="store_id" required>${(data.stores || []).map(store => `<option value="${store.id}" ${store.id === data.store?.id ? 'selected' : ''}>${e(store.company_name)} · ${e(store.name)}${store.active ? '' : ' · Inactive'}</option>`).join('')}</select><p id="store-error" class="error" role="alert"></p><button class="btn primary mt-3" ${data.stores?.length ? '' : 'disabled'}>Switch store</button></form>`;
}
function bindStoreChoices(content: HTMLElement) {
  content.querySelector<HTMLFormElement>('#store-choice')?.addEventListener('submit', event => {
    event.preventDefault(); void selectStore(Number(content.querySelector<HTMLSelectElement>('#chosen-store')!.value));
  });
}
function showStoreChoices(data: Profile, error = '') {
  releasePage(); pageVersion++; policyLocked = true;
  root.innerHTML = `<main class="auth-form"><section class="card"><h1>${data.store_available ? 'Choose your store' : 'Store unavailable'}</h1><p class="muted mt-4">${e(error || (data.store?.active === false ? 'This store is inactive. Select another authorised store or contact your administrator.' : 'Select an authorised store to continue.'))}</p>${storeChoices(data)}<button class="btn full mt-4" id="store-logout">Sign out</button></section></main>`;
  bindStoreChoices(root); root.querySelector<HTMLButtonElement>('#store-logout')!.onclick = () => void signOut();
}
async function clearWorkspaceData() {
  releasePage(); notes.clear(); retainedPhoto = null; restoredPhoto = null; teamFilters = new URLSearchParams(); reviewFilters = new URLSearchParams(); taskFilter = 'open'; taskSearch = ''; pushReady = false;
  await Promise.all([stopPush(), clearEvidenceCache(), clearCameraTask()]);
}
async function selectStore(id: number) {
  if (! profile) return;
  const before = profile; const operation = ++pageVersion; policyLocked = true; api.setStore(null);
  root.innerHTML = '<main class="auth-form"><p role="status">Changing store…</p></main>';
  try {
    await clearWorkspaceData();
    const latest = await api.post<Profile>('/stores/select', { store_id: id });
    if (! profile || operation !== pageVersion) return;
    profile = latest; api.setStore(latest.store?.id ?? null); policyLocked = false; navigate('/tasks');
  } catch (error) { if (profile && operation === pageVersion) showStoreChoices(before, message(error)); }
}
api.onStoreUnavailable = error => {
  if (! profile) return;
  const before = profile; const operation = ++pageVersion; policyLocked = true; api.setStore(null);
  root.innerHTML = '<main class="auth-form"><p role="status">Refreshing store access…</p></main>';
  void clearWorkspaceData().then(async () => {
    const latest = await api.request<Profile>('/me'); if (! profile || operation !== pageVersion) return; profile = latest; api.setStore(latest.store?.id ?? null);
    showStoreChoices(latest, error);
  }).catch(() => { if (profile && operation === pageVersion) showStoreChoices(before, error); });
};

function taskCard(task: Task, submissions = false) {
  return `<a href="#/tasks/${task.id}" class="card task-card"><div class="card-head"><span class="eyebrow mb-0">${e(task.category.name)}</span><span class="priority ${e(task.priority)}">${e(task.priority)}</span></div><h2>${e(task.title)}</h2><p class="muted">${e(task.area)} · ${e(task.assignee.name)} (${e(task.assignee.role)})</p><div class="badges"><span class="badge ${e(task.status)}">${e(task.status_label)}</span>${task.overdue ? '<span class="badge overdue">Overdue</span>' : ''}${task.status === 'completed' ? `<span class="badge">${task.review_status === 'reviewed' ? 'Reviewed' : 'Not reviewed'}</span>` : ''}</div>${submissions ? `<p class="field-help">${task.submission_count || 0} completion attempt(s) · View evidence and feedback</p>` : ''}<div class="deadline"><span>Due ${e(date(task.due_at))}</span><strong>View →</strong></div></a>`;
}

async function taskList(content: HTMLElement, version: number, submissions = false, page = 1) {
  const params = new URLSearchParams({ page: String(page) });
  if (submissions) params.set('has_submissions', '1');
  else if (taskFilter === 'open') params.set('open', '1');
  else if (taskFilter === 'today') { params.set('from', today()); params.set('to', today()); }
  else if (taskFilter === 'overdue') params.set('overdue', '1');
  else if (taskFilter !== 'all') params.set('status', taskFilter);
  if (taskSearch && ! submissions) params.set('q', taskSearch);
  const result = await api.request<Page<Task>>('/tasks?' + params);
  if (version !== pageVersion) return;
  content.innerHTML = pagehead(submissions ? 'Completion history' : 'My tasks', submissions ? 'View your completion attempts, evidence and correction notes.' : `Hello ${profile!.user.name.split(' ')[0]}. Here’s what needs your attention.`) +
    (submissions ? '' : `<section class="card hero"><h2>One task at a time.</h2><p class="muted">Start your work, add proof and complete the task.</p></section><form id="filters" class="filters"><div class="wide"><label for="search">Search tasks or store area</label><input id="search" name="q" type="search" value="${e(taskSearch)}" placeholder="What are you working on?"></div><div><label for="filter">Show</label><select id="filter" name="filter">${[['open', 'Open tasks'], ['today', 'Due today'], ['overdue', 'Overdue'], ['all', 'All tasks'], ['assigned', 'Assigned'], ['in_progress', 'In progress'], ['completed', 'Completed'], ['cancelled', 'Cancelled']].map(([value, text]) => `<option value="${value}" ${taskFilter === value ? 'selected' : ''}>${text}</option>`).join('')}</select></div><div class="flex items-end"><button class="btn primary full">Apply filters</button></div></form>`) +
    `<p class="muted mb-4">${result.meta.total} ${submissions ? 'task(s) with submissions' : 'task(s)'}</p><div class="stack">${result.data.length ? result.data.map(task => taskCard(task, submissions)).join('') : empty(submissions ? 'No completions yet. Completed work will appear here with its evidence.' : 'No tasks match this view. Try another filter or check back after your next assignment.')}</div>` +
    `<div class="actions justify-between"><button class="btn" id="previous" ${page <= 1 ? 'disabled' : ''}>← Previous</button><span class="muted self-center">Page ${result.meta.page} of ${result.meta.last_page}</span><button class="btn" id="next" ${page >= result.meta.last_page ? 'disabled' : ''}>Next →</button></div>`;
  content.querySelector<HTMLFormElement>('#filters')?.addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget as HTMLFormElement;
    taskFilter = (form.elements.namedItem('filter') as HTMLSelectElement).value;
    taskSearch = (form.elements.namedItem('q') as HTMLInputElement).value;
    void render();
  });
  content.querySelector<HTMLButtonElement>('#previous')!.onclick = () => void runPage(() => taskList(content, version, submissions, page - 1), content);
  content.querySelector<HTMLButtonElement>('#next')!.onclick = () => void runPage(() => taskList(content, version, submissions, page + 1), content);
  bindRefresh(content);
}

function evidence(files: Attachment[]) {
  return `<div class="evidence">${files.map(file => `<button type="button" data-attachment="${file.id}" aria-label="View ${e(file.original_name)}">${file.kind === 'photo' ? `<img data-preview="${file.id}" alt="${e(file.original_name)}">` : '<span class="text-2xl text-center">PDF</span>'}<span>${e(file.original_name)}</span></button>`).join('')}</div>`;
}

async function taskDetails(id: number, content: HTMLElement, version: number) {
  const { data: task } = await api.request<{ data: TaskDetails }>(`/tasks/${id}`);
  const options = task.permissions.manage && canWork(task) ? await api.request<TeamOptions>('/team/options') : null;
  if (version !== pageVersion) return;
  const work = task.permissions.work && canWork(task);
  content.innerHTML = `<a class="btn small mb-5" href="${task.assignee.id === profile!.user.id ? '#/tasks' : '#/team'}">← ${task.assignee.id === profile!.user.id ? 'My tasks' : 'Team tasks'}</a><div class="pagehead"><div><p class="eyebrow">TASK #${String(task.id).padStart(4, '0')} · ${e(task.category.name)}</p><h1>${e(task.title)}</h1><div class="badges"><span class="badge ${e(task.status)}">${e(task.status_label)}</span>${task.overdue ? '<span class="badge overdue">Overdue</span>' : ''}</div></div><button class="btn small" id="refresh">Refresh</button></div><div class="stack"><section class="card"><h2>The assignment</h2><p class="note mt-4">${e(task.instructions)}</p><dl class="detail-grid"><div><dt>Assignee</dt><dd>${e(task.assignee.name)} · ${e(task.assignee.role)}</dd></div><div><dt>Store area</dt><dd>${e(task.area)}</dd></div><div><dt>Priority</dt><dd>${e(task.priority)}</dd></div><div><dt>Deadline · SAST</dt><dd>${e(date(task.due_at))}</dd></div><div><dt>Scheduled start</dt><dd>${e(date(task.scheduled_at))}</dd></div><div><dt>Assigned by</dt><dd>${e(task.creator.name)}</dd></div><div><dt>Proof required</dt><dd>${e(task.required_evidence.join(' + '))}</dd></div></dl>${task.cancellation_reason ? `<p class="notice">Cancelled: ${e(task.cancellation_reason)}</p>` : ''}</section>` +
    recurrencePanel(task) + (work ? `<section class="card"><h2>Your next step</h2>${task.reviews.some(review => review.action === 'reopened') ? '<p class="notice">Check the correction note in Review history and upload fresh proof for this completion.</p>' : ''}${task.status !== 'in_progress' ? '<button class="btn mt-4" id="start">Start work →</button>' : ''}<form id="upload" class="field"><label for="file">Add a photo or PDF</label><input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"><p class="field-help">JPEG, PNG, WebP or PDF · up to ${(profile!.config.upload_max_kb / 1024).toFixed(1)} MB. Each attempt needs fresh proof.</p><div class="actions"><button type="button" class="btn" id="camera">Take photo</button><button class="btn" type="submit">Upload selected file ↑</button></div><progress id="upload-progress" hidden max="100" value="0"></progress><p id="upload-status" class="field-help" role="status"></p><p id="upload-error" class="error" role="alert"></p></form>${evidence(task.draft_evidence)}<form id="submit" class="field"><label for="note">Completion note ${task.required_evidence.includes('note') ? '(required)' : '(optional)'}</label><textarea id="note" name="note" maxlength="10000" ${task.required_evidence.includes('note') ? 'required' : ''} placeholder="Describe what you completed and what the reviewer should know.">${e(notes.get(id) || '')}</textarea><p id="submit-error" class="error" role="alert"></p><button type="submit" class="btn primary full mt-4">Complete task ✓</button></form></section>` : '') +
    `<section class="card"><h2>Completion history</h2><p class="field-help">Every attempt retains its evidence and deadline.</p>${task.submissions.length ? task.submissions.map((submission, index) => `<article class="attempt"><div class="card-head"><h3>Attempt ${index + 1}</h3><span class="badge ${e(submission.review_status)}">${e(submission.history_label)}</span></div>${submission.started_at ? `<p class="field-help">Started ${e(date(submission.started_at))}</p>` : ''}<p class="field-help">Completed ${e(date(submission.submitted_at))} · <strong>${submission.on_time ? 'On time' : 'Late'}</strong></p><p class="field-help">Deadline for this attempt: ${e(date(submission.due_at))}</p><p class="note mt-3">${e(submission.note)}</p>${evidence(submission.attachments)}${submission.reviewed_at ? `<p class="field-help mt-3">Historical review: ${e(submission.reviewer || 'Unknown reviewer')} · ${e(date(submission.reviewed_at))}</p><p class="note mt-2">${e(submission.review_reason)}</p>` : ''}</article>`).join('') : '<p class="muted mt-4">No completions yet.</p>'}</section>` + reviewPanel(task) + managementPanel(task, options) +
    `<section class="card"><h2>Comments & blockers</h2><form id="comment" class="field"><label for="body">Share an update</label><textarea id="body" name="body" maxlength="5000" required placeholder="Ask a question or explain what’s holding you up."></textarea><p id="comment-error" class="error" role="alert"></p><div class="actions"><button class="btn" name="action" value="comment">Add comment</button>${work ? '<button class="btn danger" name="action" value="blocker">Report blocker</button>' : ''}</div><p class="field-help">A blocker alerts your reviewers. Your deadline stays in place.</p></form></section><section class="card"><h2>Activity record</h2><div class="timeline">${task.activities.map(activity => `<div class="timeline-item"><h3>${e(label(activity.type))}</h3><p class="note">${e(activity.body)}</p><p class="field-help">${e(activity.user)} · ${e(date(activity.created_at))}</p></div>`).join('') || '<p class="muted">No activity yet.</p>'}</div></section><section class="card"><h2>Deadline history</h2>${task.deadline_changes.map(change => `<div class="attempt"><p class="muted">${e(date(change.old_due_at))} →</p><strong>${e(date(change.new_due_at))}</strong><p class="note mt-2">${e(change.reason)}</p><p class="field-help">${e(change.user)} · ${e(date(change.created_at))}</p></div>`).join('') || '<p class="muted mt-3">The original deadline is unchanged.</p>'}</section></div>`;
  bindRefresh(content);
  bindTaskRecurrence(content, task);
  const mutate = async (action: string, body: Record<string, unknown>, errorId: string) => {
    const error = content.querySelector<HTMLElement>(errorId)!; error.textContent = '';
    const buttons = content.querySelectorAll<HTMLButtonElement>('button'); buttons.forEach(button => { button.disabled = true; });
    try { await api.post(`/tasks/${id}/actions`, { action, ...body }); if (action === 'complete') notes.delete(id); toast('Task updated.'); await render(); }
    catch (err) { error.textContent = message(err); }
    finally { buttons.forEach(button => { button.disabled = false; }); }
  };
  content.querySelector<HTMLButtonElement>('#start')?.addEventListener('click', () => void mutate('start', {}, '#submit-error'));
  const note = content.querySelector<HTMLTextAreaElement>('#note');
  note?.addEventListener('input', () => notes.set(id, note.value));
  content.querySelector<HTMLFormElement>('#submit')?.addEventListener('submit', event => {
    event.preventDefault(); const missing = missingEvidence(task, note!.value);
    if (missing.length) { content.querySelector('#submit-error')!.textContent = `Add the required ${missing.join(' and ')} before completing the task.`; return; }
    void mutate('complete', { note: note!.value }, '#submit-error');
  });
  content.querySelector<HTMLFormElement>('#comment')!.onsubmit = event => {
    event.preventDefault(); const action = (event.submitter as HTMLButtonElement).value;
    void mutate(action, { body: content.querySelector<HTMLTextAreaElement>('#body')!.value }, '#comment-error');
  };
  content.querySelectorAll<HTMLFormElement>('[data-task-action]').forEach(form => {
    form.onsubmit = event => {
      event.preventDefault();
      void mutate(form.dataset.taskAction!, Object.fromEntries(new FormData(form)), '#management-error');
    };
  });
  const upload = async (file?: File) => {
    const error = content.querySelector('#upload-error')!; error.textContent = '';
    if (! file) { error.textContent = 'Choose a photo or PDF first.'; return; }
    if (file.size > profile!.config.upload_max_kb * 1024) { error.textContent = 'This file is too large. Choose a smaller file.'; return; }
    if (! ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) { error.textContent = 'Choose a JPEG, PNG, WebP or PDF.'; return; }
    const buttons = content.querySelectorAll<HTMLButtonElement>('button'); buttons.forEach(button => { button.disabled = true; });
    const progress = content.querySelector<HTMLProgressElement>('#upload-progress')!; progress.hidden = false; progress.removeAttribute('value');
    content.querySelector('#upload-status')!.textContent = 'Uploading evidence…';
    try { const body = new FormData(); body.set('file', file); await api.post(`/tasks/${id}/uploads`, body); retainedPhoto = null; toast('Evidence uploaded.'); await render(); }
    catch (err) { error.textContent = message(err); content.querySelector('#upload-status')!.textContent = 'Upload did not complete. Please try again.'; }
    finally { buttons.forEach(button => { button.disabled = false; }); progress.hidden = true; }
  };
  content.querySelector<HTMLFormElement>('#upload')?.addEventListener('submit', event => { event.preventDefault(); void upload(content.querySelector<HTMLInputElement>('#file')!.files?.[0] || (retainedPhoto?.taskId === id ? retainedPhoto.file : undefined)); });
  content.querySelector<HTMLButtonElement>('#camera')?.addEventListener('click', async () => {
    if (! Capacitor.isNativePlatform()) {
      const file = document.createElement('input'); file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp'; file.setAttribute('capture', 'environment'); file.hidden = true;
      file.addEventListener('change', () => { void upload(file.files?.[0]); file.remove(); });
      file.addEventListener('cancel', () => file.remove()); content.append(file); file.click(); return;
    }
    try { const storeId = api.getStore(); const userId = profile!.user.id; const file = await capturePhoto(id, userId); if (storeId !== api.getStore() || userId !== profile?.user.id) return; retainedPhoto = { taskId: id, file }; await upload(file); } catch (err) { content.querySelector('#upload-error')!.textContent = message(err); }
  });
  if (retainedPhoto?.taskId === id) content.querySelector('#upload-status')!.textContent = 'Your camera photo is ready. Tap Upload selected file to send it.';
  await bindEvidence(content, [...task.draft_evidence, ...task.submissions.flatMap(s => s.attachments)], version);
}

async function bindEvidence(content: HTMLElement, files: Attachment[], version: number) {
  content.querySelectorAll<HTMLButtonElement>('[data-attachment]').forEach(button => {
    button.onclick = async () => {
      button.disabled = true;
      try {
        const file = files.find(file => file.id === Number(button.dataset.attachment))!;
        const blob = await api.blob(file.url);
        if (version !== pageVersion) return;
        if (file.kind === 'document' && Capacitor.isNativePlatform()) { await viewNativeDocument(blob, file.id); return; }
        const url = blobUrl(blob); const dialog = document.createElement('dialog');
        dialog.innerHTML = `<div class="card-head"><h2>${e(file.original_name)}</h2><button class="btn small">Close</button></div>${file.kind === 'photo' ? `<img src="${url}" alt="${e(file.original_name)}">` : `<iframe src="${url}" title="${e(file.original_name)}" sandbox></iframe>`}<p class="field-help">Private evidence · ${(file.size / 1024).toFixed(0)} KB</p>`;
        content.append(dialog); dialog.querySelector('button')!.onclick = () => dialog.close();
        dialog.addEventListener('close', () => { URL.revokeObjectURL(url); objectUrls.delete(url); dialog.remove(); }); dialog.showModal();
      } catch (err) { toast(message(err)); } finally { button.disabled = false; }
    };
  });
  // Limit concurrent authenticated preview requests on slower mobile connections.
  const previews = [...content.querySelectorAll<HTMLImageElement>('[data-preview]')];
  const worker = async () => {
    while (previews.length && version === pageVersion) {
      const image = previews.shift()!;
      try { const blob = await api.blob(`/attachments/${image.dataset.preview}?preview=1`); if (version === pageVersion) image.src = blobUrl(blob); }
      catch { image.alt = 'Preview unavailable. Tap to retry.'; }
    }
  };
  await Promise.all([worker(), worker()]);
}

function calendarScreen(content: HTMLElement) {
  content.innerHTML = pagehead('Your calendar', 'Your deadlines and planned recurring dates. Tap an entry for details. Pausing a series removes its planned dates.') + '<section class="card"><div id="calendar"></div><p id="calendar-error" class="error" role="alert"></p></section>';
  calendar = new Calendar(content.querySelector<HTMLElement>('#calendar')!, {
    ...calendarOptions, initialView: window.innerWidth < 600 ? 'timeGridDay' : 'dayGridMonth', editable: false,
    height: window.innerWidth < 600 ? Math.max(360, window.innerHeight - 300) : 'auto',
    scrollTime: DateTime.now().setZone(BUSINESS_TIMEZONE).minus({ hours: 1 }).toFormat('HH:mm:ss'),
    events: async (info, success, failure) => {
      const error = content.querySelector('#calendar-error')!; error.textContent = '';
      try { const result = await api.request<EventInput[]>('/calendar?' + new URLSearchParams({ from: info.startStr.slice(0, 10), to: DateTime.fromISO(info.endStr).setZone(BUSINESS_TIMEZONE).minus({ days: 1 }).toISODate()!, include_recurring: '1' })); success(result); }
      catch (err) { error.textContent = message(err); failure(err instanceof Error ? err : new Error(message(err))); }
    },
    eventClick: info => {
      info.jsEvent.preventDefault();
      if (info.event.extendedProps.planned) {
        const dialog = document.createElement('dialog'); dialog.setAttribute('aria-labelledby', 'planned-title');
        dialog.innerHTML = '<h2 id="planned-title">Planned recurring task</h2><p class="note mt-4"></p><button class="btn primary mt-4">Close</button>';
        dialog.querySelector('p')!.textContent = plannedOccurrenceText(info.event);
        content.append(dialog); dialog.querySelector('button')!.onclick = () => dialog.close();
        dialog.addEventListener('close', () => dialog.remove()); dialog.showModal(); return;
      }
      navigate(`/tasks/${info.event.id}`);
    },
  });
  calendar.render(); bindRefresh(content);
}

async function notifications(content: HTMLElement, version: number, page = 1) {
  const result = await api.request<Page<Alert>>('/notifications?page=' + page);
  if (version !== pageVersion) return;
  content.innerHTML = pagehead('Your updates', `${result.meta.unread || 0} unread · Assignments, deadlines and review feedback.`) + `<div class="stack">${result.data.length ? result.data.map(alert => `<article class="card notification ${alert.read_at ? 'read' : ''}"><p class="eyebrow">${e(label(alert.type))}</p><h3 class="note">${e(alert.message)}</h3><p class="field-help">${e(date(alert.created_at))}</p><div class="actions">${alert.task_id ? `<a class="btn small" href="#/tasks/${alert.task_id}">View task →</a>` : ''}${! alert.read_at ? `<button class="btn small" data-read="${alert.id}">Mark read</button>` : '<span class="muted self-center">Read ✓</span>'}</div></article>`).join('') : empty('You’re all caught up. New task updates will appear here.')}</div><div class="actions justify-between"><button class="btn" id="previous" ${page <= 1 ? 'disabled' : ''}>← Previous</button><button class="btn" id="next" ${page >= result.meta.last_page ? 'disabled' : ''}>Next →</button></div>`;
  content.querySelectorAll<HTMLButtonElement>('[data-read]').forEach(button => { button.onclick = async () => { button.disabled = true; try { await api.post(`/notifications/${button.dataset.read}/read`); await render(); } catch (err) { toast(message(err)); button.disabled = false; } }; });
  content.querySelector<HTMLButtonElement>('#previous')!.onclick = () => void runPage(() => notifications(content, version, page - 1), content);
  content.querySelector<HTMLButtonElement>('#next')!.onclick = () => void runPage(() => notifications(content, version, page + 1), content);
  bindRefresh(content);
}

async function settings(content: HTMLElement, version: number) {
  const enabled = await pushEnabled(); if (version !== pageVersion) return;
  content.innerHTML = pagehead('Settings', 'Your account and phone preferences.') + `<div class="stack"><section class="card"><p class="eyebrow">YOUR ACCOUNT</p><h2>${e(profile!.user.name)}</h2><p class="muted mt-2">${e(profile!.user.email)}</p><p class="field-help">${e(label(profile!.user.role))} · Account details are managed by your store owner.</p></section><section class="card"><h2>Your store</h2><p class="muted mt-2">${e(profile!.store?.company_name)} · ${e(profile!.store?.name)}</p>${storeChoices(profile!)}</section><section class="card"><h2>Notifications</h2><p class="muted mt-2">In-app updates are always available. Enable push to receive task updates on this phone.</p><p class="field-help">Phone notifications: ${enabled ? 'Enabled' : 'Disabled'}</p>${! profile!.config.push_enabled ? '<p class="notice">Your store has not enabled native push delivery yet. Updates remain available in the app.</p>' : ''}<button class="btn mt-4" id="push" ${! profile!.config.push_enabled || ! Capacitor.isNativePlatform() ? 'disabled' : ''}>${enabled ? 'Disable' : 'Enable'} push notifications</button>${! Capacitor.isNativePlatform() ? '<p class="field-help">Install the Android or iOS app to use push notifications.</p>' : ''}</section><section class="card"><h2>Company policy</h2><p class="field-help">Read the current published policy and your acknowledgement.</p><a class="btn mt-4" href="#/company-policy">Read company policy</a></section><section class="card"><h2>Time & evidence</h2><p class="field-help">All deadlines display in South African time (${e(profile!.config.timezone)}).</p><p class="field-help">Photos and PDFs: up to ${(profile!.config.upload_max_kb / 1024).toFixed(1)} MB each. Uploads require an internet connection.</p></section><section class="card"><h2>Sign out</h2><p class="muted mt-2">Ends this phone’s sign-in and removes its push subscription. An internet connection is required.</p><button class="btn danger full mt-4" id="logout">Sign out</button></section></div>`;
  bindStoreChoices(content);
  content.querySelector<HTMLButtonElement>('#push')!.onclick = async event => {
    const button = event.currentTarget as HTMLButtonElement; button.disabled = true;
    try { if (enabled) { await disablePush(); toast('Push notifications disabled.'); } else toast(await enablePush()); await render(); } catch (err) { toast(message(err)); } finally { button.disabled = false; }
  };
  content.querySelector<HTMLButtonElement>('#logout')!.onclick = () => void signOut(); bindRefresh(content);
}

function bindRefresh(content: HTMLElement) { content.querySelector<HTMLButtonElement>('#refresh')?.addEventListener('click', () => void render()); }
async function runPage(action: () => Promise<void>, content: HTMLElement) { try { await action(); } catch (err) { if (err instanceof ApiError && err.code === 'POLICY_ACKNOWLEDGEMENT_REQUIRED') return; if (content.isConnected) { content.innerHTML = `<div class="card"><h2>Could not load this page</h2><p class="error" role="alert">${e(message(err))}</p><button class="btn" id="refresh">Try again</button></div>`; bindRefresh(content); } } }
async function render() {
  if (! profile) { login(); return; }
  releasePage(); const version = ++pageVersion;
  // Never trust a previous acknowledgement or cached policy state on navigation.
  try {
    root.innerHTML = '<main class="auth-form"><p role="status">Checking company policy…</p><button class="btn" id="policy-logout">Sign out</button></main>';
    root.querySelector<HTMLButtonElement>('#policy-logout')!.onclick = () => void signOut();
    const latest = await api.request<Profile>('/me');
    if (version !== pageVersion || ! profile) return;
    if (profile.store?.id !== latest.store?.id) await clearWorkspaceData();
    if (version !== pageVersion) return;
    profile = latest; api.setStore(latest.store?.id ?? null);
    if (! latest.store_available) { showStoreChoices(latest); return; }
    const state = await api.request<PolicyState>('/company-policy');
    if (version !== pageVersion || ! profile) return;
    if (state.required && state.policy) { showPolicy(state.policy, true); return; }
    policyLocked = false;
    if (location.hash === '#/company-policy') {
      if (state.policy) showPolicy(state.policy, false, state.acknowledged_at);
      else {
        root.innerHTML = '<main class="policy-gate"><section class="card policy-dialog"><h1>Company policy</h1><p class="muted mt-4">No company policy has been published.</p><button class="btn primary full mt-4" id="policy-continue">Continue to TaskSure</button><button class="btn full mt-3" id="policy-logout">Sign out</button></section></main>';
        root.querySelector<HTMLButtonElement>('#policy-continue')!.onclick = () => navigate('/tasks');
        root.querySelector<HTMLButtonElement>('#policy-logout')!.onclick = () => void signOut();
      }
      return;
    }
    if (! pushReady) { await initializePush(); if (version !== pageVersion) return; pushReady = true; }
    if (version !== pageVersion) return;
  } catch (error) {
    if (! profile || version !== pageVersion) return;
    root.innerHTML = `<main class="auth-form"><h1>Could not check company policy</h1><p class="error">${e(message(error))}</p><button class="btn" id="policy-retry">Try again</button><button class="btn" id="policy-logout">Sign out</button></main>`;
    root.querySelector<HTMLButtonElement>('#policy-retry')!.onclick = () => void render();
    root.querySelector<HTMLButtonElement>('#policy-logout')!.onclick = () => void signOut();
    return;
  }
  const path = location.hash.slice(1) || '/tasks';
  const detail = path.match(/^\/tasks\/(\d+)$/);
  const active = detail ? 'tasks' : path === '/reviews' || path.startsWith('/team') ? 'team' : path.slice(1); shell(active);
  const content = document.querySelector<HTMLElement>('#content')!;
  await runPage(async () => {
    if (detail) await taskDetails(Number(detail[1]), content, version);
    else if (path === '/calendar') calendarScreen(content);
    else if (path === '/notifications') await notifications(content, version);
    else if (path === '/settings') await settings(content, version);
    else if (path === '/team/new') await assignTask(content, version);
    else if (path === '/team' || path === '/reviews') await teamTasks(content, version, path === '/reviews');
    else await taskList(content, version, path === '/submissions');
  }, content);
}
window.addEventListener('hashchange', () => void render());
window.addEventListener('online', () => toast('Connection restored. Refresh to load the latest updates.'));
window.addEventListener('offline', () => toast('You’re offline. Reconnect before uploading proof or completing work.'));

async function boot() {
  root.innerHTML = '<main class="auth-form"><p role="status">Opening TaskSure…</p></main>';
  try {
    if (await restoreSession()) { profile = await api.request<Profile>('/me'); api.setStore(profile.store?.id ?? null); await recoverCamera(); await render(); }
    else login();
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) { await clearSession(); login(); }
    else { root.innerHTML = `<main class="auth-form"><h1>Could not open TaskSure</h1><p class="error">${e(message(err))}</p><button class="btn" id="retry">Try again</button></main>`; document.querySelector<HTMLButtonElement>('#retry')!.onclick = () => void boot(); }
  }
}
if (Capacitor.isNativePlatform()) {
  void App.addListener('appRestoredResult', result => {
    if (result.pluginId === 'Camera' && result.methodName === 'takePhoto' && result.success) {
      void pendingCameraTask().then(async pending => {
        if (pending) { restoredPhoto = { ...pending, data: result.data as MediaResult }; if (profile) { await recoverCamera(); await render(); } }
      });
    }
  });
  void App.addListener('backButton', () => { if (policyLocked) return; const dialog = root.querySelector<HTMLDialogElement>('dialog[open]'); if (dialog) { dialog.close(); return; } if (location.hash !== '#/tasks' && profile) navigate('/tasks'); else void App.minimizeApp(); });
  void App.addListener('appStateChange', state => { if (state.isActive && profile) void render(); });
}
async function recoverCamera() {
  if (! restoredPhoto || ! profile) return;
  const restored = restoredPhoto; restoredPhoto = null; await clearCameraTask();
  if (! profile || restored.userId !== profile.user.id || restored.storeId !== profile.store?.id) return;
  try {
    const file = await photoFile(restored.data);
    if (! profile || restored.userId !== profile.user.id || restored.storeId !== api.getStore()) return;
    retainedPhoto = { taskId: restored.taskId, file };
    location.hash = `/tasks/${restored.taskId}`;
    toast('Your camera photo was recovered. Review the task and tap Upload selected file to send it.');
  } catch (err) { toast(message(err)); }
}
void boot();

function showPolicy(policy: CompanyPolicy, required: boolean, acknowledgedAt: string | null = null) {
  if (! profile) return;
  releasePage(); pageVersion++; policyLocked = required;
  if (required) pushReady = false;
  // Destroy any open evidence dialogs and normal navigation while locked.
  root.innerHTML = policyScreen(policy, required, acknowledgedAt);
  if ((profile.stores || []).length > 1) { const button = document.createElement('button'); button.className = 'btn full mt-3'; button.textContent = 'Switch store'; button.onclick = () => showStoreChoices(profile!); root.querySelector('.policy-dialog')!.append(button); }
  root.querySelector<HTMLButtonElement>('#policy-logout')!.onclick = () => void signOut();
  root.querySelector<HTMLButtonElement>('#policy-reload')!.onclick = () => void render();
  root.querySelector<HTMLButtonElement>('#policy-continue')?.addEventListener('click', () => navigate('/tasks'));
  const checkbox = root.querySelector<HTMLInputElement>('#policy-acknowledged');
  const confirm = root.querySelector<HTMLButtonElement>('#policy-confirm');
  checkbox?.addEventListener('change', () => { confirm!.disabled = ! checkbox.checked; });
  root.querySelector<HTMLFormElement>('#policy-ack-form')?.addEventListener('submit', async event => {
    event.preventDefault(); if (! checkbox?.checked) return;
    confirm!.disabled = true;
    const version = pageVersion;
    try {
      await api.post('/company-policy/acknowledge', { policy_version_id: policy.id, acknowledged: true });
      if (version !== pageVersion || ! profile) return;
      // Recheck the current version before restoring the intended screen.
      await render();
    } catch (error) {
      if (version !== pageVersion) return;
      root.querySelector('#policy-error')!.textContent = message(error);
      confirm!.disabled = ! checkbox.checked;
    }
  });
}
window.addEventListener('pageshow', event => { if (event.persisted && profile) void render(); });

function reviewPanel(task: TaskDetails): string {
  const recorded = (value: string | null) => value ? date(value) : 'Not recorded';
  return `<section class="card"><h2>Completion and review</h2><p class="field-help">Started: ${e(recorded(task.started_at))}</p><p class="field-help">Completed: ${e(recorded(task.completed_at))} · ${e(task.completed_by?.name || 'Not recorded')}</p>${task.status === 'completed' ? `<p class="notice">Completed · ${task.review_status === 'reviewed' ? 'Reviewed' : 'Not reviewed'}. Review is optional.</p>${task.review_status === 'reviewed' ? `<p class="field-help">${e(task.reviewer?.name || 'Unknown historical reviewer')} · ${e(recorded(task.reviewed_at))}</p>` : ''}${task.permissions.review ? `${task.review_status !== 'reviewed' ? '<form class="field" data-task-action="mark_reviewed"><label for="review-note">Review note (optional)</label><textarea id="review-note" name="reason" maxlength="5000"></textarea><button class="btn primary mt-4">Mark as reviewed</button></form>' : ''}<form class="field" data-task-action="reopen"><label for="reopen-note">Correction note (required)</label><textarea id="reopen-note" name="reason" maxlength="5000" required placeholder="Explain what needs fixing."></textarea><button class="btn danger mt-4">Reopen task</button></form>` : ''}` : ''}<p id="management-error" class="error" role="alert"></p><h3 class="mt-5">Review history</h3>${task.reviews.map(review => `<div class="attempt"><strong>${e(review.action === 'legacy_approval' ? 'Historical approval' : label(review.action))}</strong><p class="field-help">${e(review.reviewer || 'Unknown historical reviewer')} · ${e(recorded(review.occurred_at))} · Completion #${e(review.submission_id ?? 'Not recorded')}</p><p class="note mt-2">${e(review.note)}</p></div>`).join('') || '<p class="field-help">No review events recorded.</p>'}</section>`;
}

function managementPanel(task: TaskDetails, options: TeamOptions | null): string {
  if (! task.permissions.manage || ! canWork(task) || ! options) return '';
  return `<section class="card"><h2>Manage assignment</h2><form class="field" data-task-action="reschedule"><label for="new-due">New deadline · SAST</label><input id="new-due" type="datetime-local" name="due_at" required value="${DateTime.fromISO(task.due_at).setZone(BUSINESS_TIMEZONE).toFormat("yyyy-MM-dd'T'HH:mm")}"><label class="mt-3" for="deadline-reason">Reason</label><textarea id="deadline-reason" name="reason" required maxlength="5000"></textarea><label class="check mt-3"><input type="checkbox" name="confirmed" value="1" required>I confirm this deadline change.</label><button class="btn mt-3">Save deadline</button></form><form class="field" data-task-action="reassign"><label for="new-assignee">Responsible person</label><select id="new-assignee" name="employee_id">${options.assignees.map(person => `<option value="${person.id}" ${person.id === task.assignee.id ? 'selected' : ''}>${e(person.name)} · ${e(person.role)}</option>`).join('')}</select><label class="mt-3" for="reassign-reason">Reason</label><textarea id="reassign-reason" name="reason" required maxlength="5000"></textarea><button class="btn mt-3">Reassign task</button></form><form class="field" data-task-action="cancel"><label for="cancel-reason">Cancellation reason</label><textarea id="cancel-reason" name="reason" required maxlength="5000"></textarea><button class="btn danger mt-3">Cancel task</button></form></section>`;
}

async function teamTasks(content: HTMLElement, version: number, reviews = false, page = 1) {
  if (profile!.user.role !== 'manager') throw new Error('Team access requires a manager account.');
  const filters = reviews ? reviewFilters : teamFilters;
  const params = new URLSearchParams(filters); params.set('page', String(page));
  const [result, options] = await Promise.all([api.request<Page<Task>>(`${reviews ? '/reviews' : '/team/tasks'}?${params}`), api.request<TeamOptions>('/team/options')]);
  if (version !== pageVersion) return;
  const stateKey = reviews ? 'review_state' : 'status';
  const states = reviews ? [['not_reviewed', 'Not reviewed'], ['reviewed', 'Reviewed']] : [['assigned', 'Assigned'], ['in_progress', 'In progress'], ['completed', 'Completed'], ['cancelled', 'Cancelled']];
  content.innerHTML = pagehead(reviews ? 'Review tasks' : 'Team tasks', reviews ? 'Completed work in your permitted team. Review is optional.' : 'Manage your permitted team. Your own assignments are in My tasks.') +
    '<div class="actions mb-5"><a class="btn" href="#/team">Team tasks</a><a class="btn" href="#/reviews">Review tasks</a><a class="btn primary" href="#/team/new">Assign task</a><a class="btn" href="#/submissions">My completion history</a></div>' +
    `<form id="team-filters" class="filters"><div class="wide"><label for="team-search">Search tasks</label><input id="team-search" name="q" value="${e(filters.get('q'))}"></div><div><label for="team-assignee">Assignee</label><select id="team-assignee" name="employee_id"><option value="">All permitted people</option>${options.assignees.filter(person => person.id !== profile!.user.id).map(person => `<option value="${person.id}" ${filters.get('employee_id') === String(person.id) ? 'selected' : ''}>${e(person.name)} · ${e(person.role)}</option>`).join('')}</select></div><div><label for="team-state">${reviews ? 'Review state' : 'Status'}</label><select id="team-state" name="${stateKey}"><option value="">All</option>${states.map(([key, value]) => `<option value="${key}" ${filters.get(stateKey) === key ? 'selected' : ''}>${value}</option>`).join('')}</select></div>${reviews ? `<div><label for="completed-from">Completed from · SAST</label><input id="completed-from" type="date" name="from" value="${e(filters.get('from'))}"></div><div><label for="completed-to">Completed to · SAST</label><input id="completed-to" type="date" name="to" value="${e(filters.get('to'))}"></div>` : ''}<button class="btn primary">Apply filters</button><button type="button" class="btn" id="clear-team-filters">Clear</button></form><p class="muted mb-4">${result.meta.total} task(s)</p><div class="stack">${result.data.map(task => taskCard(task)).join('') || empty('No tasks match this view.')}</div><div class="actions justify-between"><button class="btn" id="previous" ${page <= 1 ? 'disabled' : ''}>← Previous</button><span class="field-help">Page ${result.meta.page} of ${result.meta.last_page}</span><button class="btn" id="next" ${page >= result.meta.last_page ? 'disabled' : ''}>Next →</button></div>`;
  content.querySelector<HTMLFormElement>('#team-filters')!.onsubmit = event => {
    event.preventDefault(); const next = new URLSearchParams();
    new FormData(event.currentTarget as HTMLFormElement).forEach((value, key) => { if (String(value).trim()) next.set(key, String(value)); });
    if (reviews) reviewFilters = next; else teamFilters = next; void render();
  };
  content.querySelector<HTMLButtonElement>('#clear-team-filters')!.onclick = () => { if (reviews) reviewFilters = new URLSearchParams(); else teamFilters = new URLSearchParams(); void render(); };
  content.querySelector<HTMLButtonElement>('#previous')!.onclick = () => void runPage(() => teamTasks(content, version, reviews, page - 1), content);
  content.querySelector<HTMLButtonElement>('#next')!.onclick = () => void runPage(() => teamTasks(content, version, reviews, page + 1), content);
  bindRefresh(content);
}

async function assignTask(content: HTMLElement, version: number) {
  if (profile!.user.role !== 'manager') throw new Error('Only managers can assign tasks in the app.');
  const options = await api.request<TeamOptions>('/team/options'); if (version !== pageVersion) return;
  content.innerHTML = pagehead('Assign task', 'Choose an active manager or employee in your permitted team. Enter times in SAST.') + `<section class="card"><form id="assign-task" class="stack"><div><label for="task-title">Title</label><input id="task-title" name="title" required maxlength="180"></div><div><label for="instructions">Instructions</label><textarea id="instructions" name="instructions" required maxlength="10000"></textarea></div><div><label for="assignee">Responsible person</label><select id="assignee" name="employee_id" required><option value="">Choose a person</option>${options.assignees.map(person => `<option value="${person.id}">${e(person.name)} · ${e(person.role)}</option>`).join('')}</select></div><div><label for="category">Category</label><select id="category" name="category_id" required>${options.categories.map(category => `<option value="${category.id}">${e(category.name)}</option>`).join('')}</select>${! options.categories.length ? '<p class="error">Ask your store owner to add a category in Store settings.</p>' : ''}</div><div><label for="area">Store area</label><input id="area" name="area" required maxlength="100"></div><div><label for="priority">Priority</label><select id="priority" name="priority">${['low', 'normal', 'high', 'urgent'].map(priority => `<option ${priority === 'normal' ? 'selected' : ''}>${priority}</option>`).join('')}</select></div><fieldset><legend>Required proof for each completion</legend>${['photo', 'document', 'note'].map(kind => `<label class="check"><input type="checkbox" name="required_evidence" value="${kind}" ${kind !== 'document' ? 'checked' : ''}>${kind === 'note' ? 'Completion note' : kind === 'document' ? 'PDF document' : 'Photo'}</label>`).join('')}</fieldset><div><label for="scheduled">Scheduled start · SAST (optional)</label><input id="scheduled" name="scheduled_at" type="datetime-local"></div><div><label for="due">Deadline · SAST</label><input id="due" name="due_at" type="datetime-local" required></div>${recurrenceFields()}<p class="error" id="assign-error" role="alert"></p><button class="btn primary" ${! options.categories.length ? 'disabled' : ''}>Assign task</button></form></section>`;
  bindRecurrenceFields(content);
  content.querySelector<HTMLFormElement>('#assign-task')!.onsubmit = async event => {
    event.preventDefault(); const form = event.currentTarget as HTMLFormElement; const fields = new FormData(form);
    const body = { ...Object.fromEntries(fields), required_evidence: fields.getAll('required_evidence') };
    const button = form.querySelector<HTMLButtonElement>('button')!; button.disabled = true;
    try { const response = await api.post<{ data: { id: number } }>('/team/tasks', body); toast('Task assigned.'); navigate(`/tasks/${response.data.id}`); }
    catch (error) { content.querySelector('#assign-error')!.textContent = message(error); }
    finally { button.disabled = false; }
  };
  bindRefresh(content);
}

function recurrenceFields(): string {
  return '<div><label for="recurrence">Repeat</label><select id="recurrence" name="recurrence"><option value="">Does not repeat</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></div><div id="recurrence-end" hidden><label for="recurrence-until">Repeat until · SAST (optional)</label><input id="recurrence-until" type="date" name="recurrence_until" disabled><p class="field-help">Leave empty to repeat until paused. Each occurrence has its own deadline and proof. Monthly tasks keep their day, using month-end in shorter months.</p></div>';
}
function bindRecurrenceFields(content: HTMLElement) {
  const repeat = content.querySelector<HTMLSelectElement>('#recurrence')!;
  const end = content.querySelector<HTMLInputElement>('#recurrence-until')!;
  const due = content.querySelector<HTMLInputElement>('#due')!;
  const update = () => { content.querySelector<HTMLElement>('#recurrence-end')!.hidden = ! repeat.value; end.disabled = ! repeat.value; end.min = due.value.slice(0, 10); };
  repeat.onchange = update; due.onchange = update; update();
}
function recurrencePanel(task: TaskDetails): string {
  const plan = task.recurrence;
  if (plan) return `<section class="card"><h2>Recurring task</h2><p class="mt-3"><strong>${e(label(plan.frequency))}</strong> · ${e(plan.state)}</p><p class="field-help">Next occurrence date: ${e(plan.next_date)} · Due ${e(plan.due_time)} SAST. ${plan.ends_on ? 'Repeats through '+e(plan.ends_on)+'.' : 'Repeats until paused.'}</p><p class="field-help">Each occurrence keeps its own proof and history. Changing this task changes this occurrence only.</p>${task.permissions.recurrence && plan.state !== 'Finished' ? `<button class="btn mt-3" id="series-status">${plan.active ? 'Pause series' : 'Resume series'}</button><p class="field-help">Pausing leaves existing tasks unchanged. Resuming catches up missed occurrences.</p>` : ''}<p class="error" id="recurrence-error" role="alert"></p></section>`;
  if (! task.permissions.recurrence) return '';
  const tomorrow = DateTime.now().setZone(BUSINESS_TIMEZONE).plus({ days: 1 }).toISODate()!;
  const afterDue = DateTime.fromISO(task.due_at).setZone(BUSINESS_TIMEZONE).plus({ days: 1 }).toISODate()!;
  const first = tomorrow > afterDue ? tomorrow : afterDue;
  return `<section class="card"><h2>Make this task recurring</h2><form class="field" id="make-recurring"><label for="repeat-frequency">Repeat</label><select id="repeat-frequency" name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select><label class="mt-3" for="first-repeat">First repeat date · SAST</label><input id="first-repeat" name="next_date" type="date" required value="${first}"><label class="mt-3" for="repeat-end">Repeat until · SAST (optional)</label><input id="repeat-end" name="ends_on" type="date"><p class="field-help">Future tasks use the current instructions, responsible person, proof requirements and deadline time. This task and its history stay unchanged.</p><p class="error" id="recurrence-error" role="alert"></p><button class="btn primary mt-3">Make recurring</button></form></section>`;
}
function bindTaskRecurrence(content: HTMLElement, task: TaskDetails) {
  const send = async (path: string, body: Record<string, unknown>, button: HTMLButtonElement) => {
    button.disabled = true;
    try { await api.post(path, body); await render(); }
    catch (error) { content.querySelector('#recurrence-error')!.textContent = message(error); button.disabled = false; }
  };
  content.querySelector<HTMLFormElement>('#make-recurring')?.addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget as HTMLFormElement;
    void send(`/tasks/${task.id}/recurrence`, Object.fromEntries(new FormData(form)), form.querySelector('button')!);
  });
  content.querySelector<HTMLButtonElement>('#series-status')?.addEventListener('click', event => {
    void send(`/recurring-tasks/${task.recurrence!.id}/status`, { active: ! task.recurrence!.active }, event.currentTarget as HTMLButtonElement);
  });
}
