import { describe, expect, it } from 'vitest';
import { plannedOccurrenceText } from '../../resources/shared/calendar.js';

describe('planned recurring calendar details', () => {
  it('shows SAST dates, overnight start and the separate-assignment explanation', () => {
    const text = plannedOccurrenceText({ extendedProps: { assignment_title: 'Fridge check', frequency: 'daily', due_at: '2026-10-15T04:00:00Z', scheduled_at: '2026-10-14T16:00:00Z', assignee: 'Employee', area: 'Cold room', instructions: 'Check shelves.' } });
    expect(text).toContain('15 Oct 2026, 06:00 SAST');
    expect(text).toContain('14 Oct 2026, 18:00 SAST');
    expect(text).toContain('Daily recurrence');
    expect(text).toContain('A separate task will become available for this date.');
    expect(text).toContain('Each occurrence has its own deadline, proof and history.');
  });
});
