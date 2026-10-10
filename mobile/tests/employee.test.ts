import { describe, expect, it } from 'vitest';
import { date, escape, canWork, missingEvidence } from '../src/utils';
import type { TaskDetails } from '../src/types';

describe('employee interface', () => {
  it('shows deadlines in South African time regardless of device time zone', () => {
    expect(date('2026-10-10T15:00:00Z')).toBe('10 Oct 2026, 17:00');
  });
  it('renders task text safely', () => {
    expect(escape('<img src=x onerror="alert(1)">')).toBe('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;');
  });
  it('requires fresh evidence and a nonempty completion note on each attempt', () => {
    const task = { required_evidence: ['photo', 'note'], draft_evidence: [], status: 'changes_requested' } as unknown as TaskDetails;
    expect(missingEvidence(task, '   ')).toEqual(['photo', 'note']);
    expect(missingEvidence({ ...task, draft_evidence: [{ kind: 'photo' }] } as TaskDetails, 'Shelf checked')).toEqual([]);
    expect(canWork(task)).toBe(true);
    expect(canWork({ ...task, status: 'submitted' })).toBe(false);
    expect(canWork({ ...task, status: 'approved' })).toBe(false);
  });
});
