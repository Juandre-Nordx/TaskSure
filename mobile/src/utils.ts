import { DateTime } from 'luxon';
import { BUSINESS_TIMEZONE } from '../../resources/shared/calendar.js';
import type { Task, TaskDetails } from './types';

export const date = (value: string | null) => value ? DateTime.fromISO(value).setZone(BUSINESS_TIMEZONE).toFormat('d MMM yyyy, HH:mm') : 'Not scheduled';
export const today = () => DateTime.now().setZone(BUSINESS_TIMEZONE).toISODate()!;
export const escape = (value: unknown) => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]!));
export const label = (value: string) => value.replaceAll('_', ' ');
export const canWork = (task: Task) => ['assigned', 'in_progress', 'changes_requested'].includes(task.status);
export const missingEvidence = (task: TaskDetails, note: string): string[] => task.required_evidence.filter(kind => kind === 'note' ? ! note.trim() : ! task.draft_evidence.some(file => file.kind === kind));
