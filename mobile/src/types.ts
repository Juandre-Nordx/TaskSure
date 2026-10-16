export type TaskStatus = 'assigned' | 'in_progress' | 'completed' | 'cancelled';
export type EvidenceKind = 'photo' | 'document' | 'note';
export type RepeatFrequency = 'daily' | 'weekly' | 'monthly';
export interface RecurringSchedule { id: number; frequency: RepeatFrequency; active: boolean; next_date: string; ends_on: string | null; due_time: string; state: string }
export interface User { id: number; name: string; email: string; role: 'employee' | 'manager' }
export interface Store { id: number; name: string; company_id: number; company_name: string; active: boolean }
export interface TeamOptions { assignees: Pick<User, 'id' | 'name' | 'role'>[]; categories: { id: number; name: string }[] }
export interface Profile {
  user: User;
  store: Store | null;
  stores: Store[];
  store_available: boolean;
  policy_required: boolean;
  policy: CompanyPolicy | null;
  config: { timezone: string; upload_max_kb: number; push_enabled: boolean };
}
export interface CompanyPolicy { id: number; version: number; title: string; content: string; published_at: string }
export interface PolicyState { policy: CompanyPolicy | null; required: boolean; acknowledged_at: string | null }
export interface LoginResponse extends Profile { token: string; expires_at: string }
export interface Page<T> { data: T[]; meta: { page: number; last_page: number; total: number; unread?: number } }
export interface Task {
  id: number; title: string; area: string; priority: string; status: TaskStatus;
  status_label: string; overdue: boolean; category: { id: number; name: string };
  due_at: string; scheduled_at: string | null; required_evidence: EvidenceKind[]; submission_count: number | null;
  assignee: Pick<User, 'id' | 'name' | 'role'>; completed_at: string | null;
  review_status: 'not_reviewed' | 'reviewed'; reviewed_at: string | null; reviewer: { id: number; name: string } | null;
  recurrence: RecurringSchedule | null;
}
export interface Attachment { id: number; original_name: string; mime: string; size: number; kind: 'photo' | 'document'; url: string }
export interface Submission {
  id: number; note: string | null; submitted_at: string; due_at: string; on_time: boolean;
  review_status: string; review_reason: string | null; reviewed_at: string | null;
  reviewer: string | null; attachments: Attachment[];
  history_label: string; started_at: string | null;
}
export interface TaskDetails extends Task {
  instructions: string; creator: { id: number; name: string }; created_at: string;
  started_at: string | null; completed_by: { id: number; name: string } | null; cancellation_reason: string | null;
  permissions: { work: boolean; manage: boolean; review: boolean; recurrence: boolean };
  reviews: { id: number; submission_id: number | null; action: string; note: string | null; reviewer: string | null; occurred_at: string | null }[];
  draft_evidence: Attachment[]; submissions: Submission[];
  activities: { id: number; type: string; body: string; user: string; created_at: string }[];
  deadline_changes: { old_due_at: string; new_due_at: string; reason: string; user: string; created_at: string }[];
}
export interface Alert { id: number; type: string; message: string; task_id: number | null; created_at: string; read_at: string | null }
