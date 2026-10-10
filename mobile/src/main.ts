import './styles.css';
import { Capacitor } from '@capacitor/core';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { Calendar, type EventInput } from '@fullcalendar/core';
import { DateTime } from 'luxon';
import { calendarOptions, BUSINESS_TIMEZONE } from '../../resources/shared/calendar.js';
import { api, ApiError, API_URL } from './api';
import { restoreSession, saveSession, clearSession } from './session';
import { capturePhoto, photoFile, pendingCameraTask, clearCameraTask, setUpPush, enablePush, disablePush, pushEnabled, stopPush } from './native';
import type { MediaResult } from '@capacitor/camera';
import { clearEvidenceCache, viewNativeDocument } from './evidence';
import { escape as e, date, today, label, canWork, missingEvidence } from './utils';
import type { Profile, LoginResponse, Task, TaskDetails, Page, Alert, Attachment } from './types';

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
let restoredPhoto: { taskId: number; userId: number; data: MediaResult } | null = null;
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
  const tabs = ['tasks', 'calendar', 'submissions', 'notifications', 'settings'];
  const initials = profile!.user.name.split(' ').map(v => v[0]).slice(0, 2).join('');
  root.innerHTML = `<div class="app-shell"><header class="topbar"><a href="#/tasks" class="brand"><span class="brandmark">✓</span>TaskSure</a><a href="#/settings" aria-label="Account settings" class="avatar">${e(initials)}</a></header><main class="content" id="content" tabindex="-1"><p class="muted" role="status">Loading your work…</p></main><nav class="bottom-nav" aria-label="Main navigation">${tabs.map(tab => `<a href="#/${tab}" class="${active === tab ? 'current' : ''}" ${active === tab ? 'aria-current="page"' : ''}>${icon(tab)}${tab === 'notifications' ? 'Updates' : tab[0].toUpperCase() + tab.slice(1)}</a>`).join('')}</nav></div>`;
}

function login() {
  releasePage(); pageVersion++;
  root.innerHTML = `<main class="auth"><div class="auth-intro"><div class="brand"><span class="brandmark">✓</span>TaskSure</div><h1>A clear plan.<br>A job well done.</h1><p>Your tasks, deadlines and proof of work.<br>All together, wherever your shift takes you.</p></div><section class="auth-form"><p class="eyebrow">EMPLOYEE SIGN IN</p><h2>Welcome back</h2><p class="muted mt-2">Use the account provided by your store owner.</p><form id="login"><div><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" required></div><div><label for="password">Password</label><div class="password-row"><input id="password" name="password" type="password" autocomplete="current-password" required><button class="btn small" type="button" id="show-password" aria-label="Show password">Show</button></div></div><p id="login-error" class="error" role="alert"></p><button class="btn primary full" type="submit">Sign in →</button></form><button class="btn full mt-5" id="forgot">Forgot password?</button><p class="field-help mt-4">Managers and store owners use the web dashboard.</p></section></main>`;
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
      profile = data; form.reset();
      await initializePush();
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
  profile = null; notes.clear(); retainedPhoto = null; restoredPhoto = null; releasePage();
  await stopPush(); await clearEvidenceCache(); await clearCameraTask(); await clearSession(); login();
}
api.onUnauthorized = () => { if (profile) { void localSignOut(); toast('Your sign-in expired. Please sign in again.'); } };

function taskCard(task: Task, submissions = false) {
  return `<a href="#/tasks/${task.id}" class="card task-card"><div class="card-head"><span class="eyebrow mb-0">${e(task.category.name)}</span><span class="priority ${e(task.priority)}">${e(task.priority)}</span></div><h2>${e(task.title)}</h2><p class="muted">${e(task.area)}</p><div class="badges"><span class="badge ${e(task.status)}">${e(task.status_label)}</span>${task.overdue ? '<span class="badge overdue">Overdue</span>' : ''}</div>${submissions ? `<p class="field-help">${task.submission_count || 0} submission attempt(s) · View evidence and feedback</p>` : ''}<div class="deadline"><span>Due ${e(date(task.due_at))}</span><strong>View →</strong></div></a>`;
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
  content.innerHTML = pagehead(submissions ? 'Your submissions' : 'Assigned tasks', submissions ? 'Review each attempt, its evidence and manager feedback.' : `Hello ${profile!.user.name.split(' ')[0]}. Here’s what needs your attention.`) +
    (submissions ? '' : `<section class="card hero"><h2>One task at a time.</h2><p class="muted">Start your work, add proof and send it for review.</p></section><form id="filters" class="filters"><div class="wide"><label for="search">Search tasks or store area</label><input id="search" name="q" type="search" value="${e(taskSearch)}" placeholder="What are you working on?"></div><div><label for="filter">Show</label><select id="filter" name="filter">${[['open', 'Open tasks'], ['today', 'Due today'], ['overdue', 'Overdue'], ['all', 'All tasks'], ['assigned', 'Assigned'], ['in_progress', 'In progress'], ['changes_requested', 'Changes requested'], ['submitted', 'Awaiting review'], ['approved', 'Approved'], ['cancelled', 'Cancelled']].map(([value, text]) => `<option value="${value}" ${taskFilter === value ? 'selected' : ''}>${text}</option>`).join('')}</select></div><div class="flex items-end"><button class="btn primary full">Apply filters</button></div></form>`) +
    `<p class="muted mb-4">${result.meta.total} ${submissions ? 'task(s) with submissions' : 'task(s)'}</p><div class="stack">${result.data.length ? result.data.map(task => taskCard(task, submissions)).join('') : empty(submissions ? 'No submissions yet. Completed work will appear here after you submit it.' : 'No tasks match this view. Try another filter or check back after your next assignment.')}</div>` +
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
  if (version !== pageVersion) return;
  const work = canWork(task);
  content.innerHTML = `<a class="btn small mb-5" href="#/tasks">← Assigned tasks</a><div class="pagehead"><div><p class="eyebrow">TASK #${String(task.id).padStart(4, '0')} · ${e(task.category.name)}</p><h1>${e(task.title)}</h1><div class="badges"><span class="badge ${e(task.status)}">${e(task.status_label)}</span>${task.overdue ? '<span class="badge overdue">Overdue</span>' : ''}</div></div><button class="btn small" id="refresh">Refresh</button></div><div class="stack"><section class="card"><h2>The assignment</h2><p class="note mt-4">${e(task.instructions)}</p><dl class="detail-grid"><div><dt>Store area</dt><dd>${e(task.area)}</dd></div><div><dt>Priority</dt><dd>${e(task.priority)}</dd></div><div><dt>Deadline · SAST</dt><dd>${e(date(task.due_at))}</dd></div><div><dt>Scheduled start</dt><dd>${e(date(task.scheduled_at))}</dd></div><div><dt>Assigned by</dt><dd>${e(task.creator.name)}</dd></div><div><dt>Proof required</dt><dd>${e(task.required_evidence.join(' + '))}</dd></div></dl>${task.cancellation_reason ? `<p class="notice">Cancelled: ${e(task.cancellation_reason)}</p>` : ''}</section>` +
    (work ? `<section class="card"><h2>Your next step</h2>${task.status === 'changes_requested' ? '<p class="notice">Review the manager’s feedback below and upload fresh proof for this attempt.</p>' : ''}${task.status !== 'in_progress' ? '<button class="btn mt-4" id="start">Start work →</button>' : ''}<form id="upload" class="field"><label for="file">Add a photo or PDF</label><input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"><p class="field-help">JPEG, PNG, WebP or PDF · up to ${(profile!.config.upload_max_kb / 1024).toFixed(1)} MB. Each attempt needs fresh proof.</p><div class="actions"><button type="button" class="btn" id="camera">Take photo</button><button class="btn" type="submit">Upload selected file ↑</button></div><progress id="upload-progress" hidden max="100" value="0"></progress><p id="upload-status" class="field-help" role="status"></p><p id="upload-error" class="error" role="alert"></p></form>${evidence(task.draft_evidence)}<form id="submit" class="field"><label for="note">Completion note ${task.required_evidence.includes('note') ? '(required)' : '(optional)'}</label><textarea id="note" name="note" maxlength="10000" ${task.required_evidence.includes('note') ? 'required' : ''} placeholder="Describe what you completed and what the reviewer should know.">${e(notes.get(id) || '')}</textarea><p id="submit-error" class="error" role="alert"></p><button type="submit" class="btn primary full mt-4">Submit for review →</button></form></section>` : task.status === 'submitted' ? '<section class="card"><h2>Awaiting review</h2><p class="muted mt-2">Your proof has been submitted. Your manager will approve it or request corrections.</p></section>' : '') +
    `<section class="card"><h2>Submission & review history</h2><p class="field-help">Every attempt retains its evidence and deadline.</p>${task.submissions.length ? task.submissions.map((submission, index) => `<article class="attempt"><div class="card-head"><h3>Attempt ${index + 1}</h3><span class="badge ${e(submission.review_status)}">${e(label(submission.review_status))}</span></div><p class="field-help">Submitted ${e(date(submission.submitted_at))} · <strong>${submission.on_time ? 'On time' : 'Late'}</strong></p><p class="field-help">Deadline for this attempt: ${e(date(submission.due_at))}</p><p class="note mt-3">${e(submission.note)}</p>${evidence(submission.attachments)}${submission.reviewed_at ? `<p class="field-help mt-3">${e(submission.reviewer)} · ${e(date(submission.reviewed_at))}</p><p class="note mt-2">${e(submission.review_reason)}</p>` : ''}</article>`).join('') : '<p class="muted mt-4">No submissions yet.</p>'}</section>` +
    `<section class="card"><h2>Comments & blockers</h2><form id="comment" class="field"><label for="body">Share an update</label><textarea id="body" name="body" maxlength="5000" required placeholder="Ask a question or explain what’s holding you up."></textarea><p id="comment-error" class="error" role="alert"></p><div class="actions"><button class="btn" name="action" value="comment">Add comment</button>${! ['approved', 'cancelled'].includes(task.status) ? '<button class="btn danger" name="action" value="blocker">Report blocker</button>' : ''}</div><p class="field-help">A blocker alerts your reviewers. Your deadline stays in place.</p></form></section><section class="card"><h2>Activity record</h2><div class="timeline">${task.activities.map(activity => `<div class="timeline-item"><h3>${e(label(activity.type))}</h3><p class="note">${e(activity.body)}</p><p class="field-help">${e(activity.user)} · ${e(date(activity.created_at))}</p></div>`).join('') || '<p class="muted">No activity yet.</p>'}</div></section><section class="card"><h2>Deadline history</h2>${task.deadline_changes.map(change => `<div class="attempt"><p class="muted">${e(date(change.old_due_at))} →</p><strong>${e(date(change.new_due_at))}</strong><p class="note mt-2">${e(change.reason)}</p><p class="field-help">${e(change.user)} · ${e(date(change.created_at))}</p></div>`).join('') || '<p class="muted mt-3">The original deadline is unchanged.</p>'}</section></div>`;
  bindRefresh(content);
  const mutate = async (action: string, body: Record<string, unknown>, errorId: string) => {
    const error = content.querySelector<HTMLElement>(errorId)!; error.textContent = '';
    const buttons = content.querySelectorAll<HTMLButtonElement>('button'); buttons.forEach(button => { button.disabled = true; });
    try { await api.post(`/tasks/${id}/actions`, { action, ...body }); if (action === 'submit') notes.delete(id); toast('Task updated.'); await render(); }
    catch (err) { error.textContent = message(err); }
    finally { buttons.forEach(button => { button.disabled = false; }); }
  };
  content.querySelector<HTMLButtonElement>('#start')?.addEventListener('click', () => void mutate('start', {}, '#submit-error'));
  const note = content.querySelector<HTMLTextAreaElement>('#note');
  note?.addEventListener('input', () => notes.set(id, note.value));
  content.querySelector<HTMLFormElement>('#submit')?.addEventListener('submit', event => {
    event.preventDefault(); const missing = missingEvidence(task, note!.value);
    if (missing.length) { content.querySelector('#submit-error')!.textContent = `Add the required ${missing.join(' and ')} before submitting.`; return; }
    void mutate('submit', { note: note!.value }, '#submit-error');
  });
  content.querySelector<HTMLFormElement>('#comment')!.onsubmit = event => {
    event.preventDefault(); const action = (event.submitter as HTMLButtonElement).value;
    void mutate(action, { body: content.querySelector<HTMLTextAreaElement>('#body')!.value }, '#comment-error');
  };
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
    try { const file = await capturePhoto(id, profile!.user.id); retainedPhoto = { taskId: id, file }; await upload(file); } catch (err) { content.querySelector('#upload-error')!.textContent = message(err); }
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
  content.innerHTML = pagehead('Your calendar', 'Day, week and month views of your deadlines. Tap a task to open it.') + '<section class="card"><div id="calendar"></div><p id="calendar-error" class="error" role="alert"></p></section>';
  calendar = new Calendar(content.querySelector<HTMLElement>('#calendar')!, {
    ...calendarOptions, initialView: window.innerWidth < 600 ? 'timeGridDay' : 'dayGridMonth', editable: false,
    height: window.innerWidth < 600 ? Math.max(360, window.innerHeight - 300) : 'auto',
    scrollTime: DateTime.now().setZone(BUSINESS_TIMEZONE).minus({ hours: 1 }).toFormat('HH:mm:ss'),
    events: async (info, success, failure) => {
      const error = content.querySelector('#calendar-error')!; error.textContent = '';
      try { const result = await api.request<EventInput[]>('/calendar?' + new URLSearchParams({ from: info.startStr.slice(0, 10), to: DateTime.fromISO(info.endStr).setZone(BUSINESS_TIMEZONE).minus({ days: 1 }).toISODate()! })); success(result); }
      catch (err) { error.textContent = message(err); failure(err instanceof Error ? err : new Error(message(err))); }
    },
    eventClick: info => { info.jsEvent.preventDefault(); navigate(`/tasks/${info.event.id}`); },
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
  content.innerHTML = pagehead('Settings', 'Your employee account and phone preferences.') + `<div class="stack"><section class="card"><p class="eyebrow">YOUR ACCOUNT</p><h2>${e(profile!.user.name)}</h2><p class="muted mt-2">${e(profile!.user.email)}</p><p class="field-help">Employee · Account details are managed by your store owner.</p></section><section class="card"><h2>Notifications</h2><p class="muted mt-2">In-app updates are always available. Enable push to receive task updates on this phone.</p><p class="field-help">Phone notifications: ${enabled ? 'Enabled' : 'Disabled'}</p>${! profile!.config.push_enabled ? '<p class="notice">Your store has not enabled native push delivery yet. Updates remain available in the app.</p>' : ''}<button class="btn mt-4" id="push" ${! profile!.config.push_enabled || ! Capacitor.isNativePlatform() ? 'disabled' : ''}>${enabled ? 'Disable' : 'Enable'} push notifications</button>${! Capacitor.isNativePlatform() ? '<p class="field-help">Install the Android or iOS app to use push notifications.</p>' : ''}</section><section class="card"><h2>Time & evidence</h2><p class="field-help">All deadlines display in South African time (${e(profile!.config.timezone)}).</p><p class="field-help">Photos and PDFs: up to ${(profile!.config.upload_max_kb / 1024).toFixed(1)} MB each. Uploads require an internet connection.</p></section><section class="card"><h2>Sign out</h2><p class="muted mt-2">Ends this phone’s sign-in and removes its push subscription. An internet connection is required.</p><button class="btn danger full mt-4" id="logout">Sign out</button></section></div>`;
  content.querySelector<HTMLButtonElement>('#push')!.onclick = async event => {
    const button = event.currentTarget as HTMLButtonElement; button.disabled = true;
    try { if (enabled) { await disablePush(); toast('Push notifications disabled.'); } else toast(await enablePush()); await render(); } catch (err) { toast(message(err)); } finally { button.disabled = false; }
  };
  content.querySelector<HTMLButtonElement>('#logout')!.onclick = () => void signOut(); bindRefresh(content);
}

function bindRefresh(content: HTMLElement) { content.querySelector<HTMLButtonElement>('#refresh')?.addEventListener('click', () => void render()); }
async function runPage(action: () => Promise<void>, content: HTMLElement) { try { await action(); } catch (err) { if (content.isConnected) { content.innerHTML = `<div class="card"><h2>Could not load this page</h2><p class="error" role="alert">${e(message(err))}</p><button class="btn" id="refresh">Try again</button></div>`; bindRefresh(content); } } }
async function render() {
  if (! profile) { login(); return; }
  releasePage(); const version = ++pageVersion;
  const path = location.hash.slice(1) || '/tasks';
  const detail = path.match(/^\/tasks\/(\d+)$/);
  const active = detail ? 'tasks' : path.slice(1); shell(active);
  const content = document.querySelector<HTMLElement>('#content')!;
  await runPage(async () => {
    if (detail) await taskDetails(Number(detail[1]), content, version);
    else if (path === '/calendar') calendarScreen(content);
    else if (path === '/notifications') await notifications(content, version);
    else if (path === '/settings') await settings(content, version);
    else await taskList(content, version, path === '/submissions');
  }, content);
}
window.addEventListener('hashchange', () => void render());
window.addEventListener('online', () => toast('Connection restored. Refresh to load the latest updates.'));
window.addEventListener('offline', () => toast('You’re offline. Reconnect before uploading proof or submitting work.'));

async function boot() {
  root.innerHTML = '<main class="auth-form"><p role="status">Opening TaskSure…</p></main>';
  try {
    if (await restoreSession()) { profile = await api.request<Profile>('/me'); await initializePush(); await recoverCamera(); await render(); }
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
  void App.addListener('backButton', () => { if (location.hash !== '#/tasks' && profile) navigate('/tasks'); else void App.minimizeApp(); });
  void App.addListener('appStateChange', state => { if (state.isActive && profile) void api.request<Profile>('/me').then(data => { profile = data; }).catch(error => { if (! (error instanceof ApiError && error.status === 401)) toast(message(error)); }); });
}
async function recoverCamera() {
  if (! restoredPhoto || ! profile) return;
  const restored = restoredPhoto; restoredPhoto = null; await clearCameraTask();
  if (restored.userId !== profile.user.id) return;
  try {
    retainedPhoto = { taskId: restored.taskId, file: await photoFile(restored.data) };
    location.hash = `/tasks/${restored.taskId}`;
    toast('Your camera photo was recovered. Review the task and tap Upload selected file to send it.');
  } catch (err) { toast(message(err)); }
}
void boot();
