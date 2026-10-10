export type TaskStatus = 'assigned' | 'in_progress' | 'submitted' | 'changes_requested' | 'approved' | 'cancelled';
export type EvidenceKind = 'photo' | 'document' | 'note';
export interface User { id: number; name: string; email: string; role: 'employee' }
export interface Profile {
  user: User;
  config: { timezone: string; upload_max_kb: number; push_enabled: boolean };
}
export interface LoginResponse extends Profile { token: string; expires_at: string }
export interface Page<T> { data: T[]; meta: { page: number; last_page: number; total: number; unread?: number } }
export interface Task {
  id: number; title: string; area: string; priority: string; status: TaskStatus;
  status_label: string; overdue: boolean; category: { id: number; name: string };
  due_at: string; scheduled_at: string | null; required_evidence: EvidenceKind[]; submission_count: number | null;
}
export interface Attachment { id: number; original_name: string; mime: string; size: number; kind: 'photo' | 'document'; url: string }
export interface Submission {
  id: number; note: string | null; submitted_at: string; due_at: string; on_time: boolean;
  review_status: string; review_reason: string | null; reviewed_at: string | null;
  reviewer: string | null; attachments: Attachment[];
}
export interface TaskDetails extends Task {
  instructions: string; creator: { id: number; name: string }; created_at: string;
  started_at: string | null; approved_at: string | null; cancellation_reason: string | null;
  draft_evidence: Attachment[]; submissions: Submission[];
  activities: { id: number; type: string; body: string; user: string; created_at: string }[];
  deadline_changes: { old_due_at: string; new_due_at: string; reason: string; user: string; created_at: string }[];
}
export interface Alert { id: number; type: string; message: string; task_id: number | null; created_at: string; read_at: string | null }
