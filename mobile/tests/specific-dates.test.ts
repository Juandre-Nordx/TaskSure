import { describe, expect, it } from 'vitest';
import { addDateRange, selectedDates, recurrencePayload } from '../../resources/shared/specific-dates.js';

describe('specific-date selection', () => {
  it('keeps sorted unique calendar days and rejects malformed dates', () => {
    expect(selectedDates(['2026-10-30', '2026-10-20', '2026-10-20', '2026-02-30', '<script>'])).toEqual(['2026-10-20','2026-10-30']);
  });
  it('adds inclusive ranges without duplicating overlapping individual dates', () => {
    expect(addDateRange(['2026-10-22','2026-10-30'], '2026-10-20','2026-10-22')).toEqual(['2026-10-20','2026-10-21','2026-10-22','2026-10-30']);
  });
  it('handles leap days and month/year boundaries as SAST dates', () => {
    expect(addDateRange([], '2028-02-28','2028-03-01')).toEqual(['2028-02-28','2028-02-29','2028-03-01']);
    expect(addDateRange([], '2026-12-31','2027-01-01')).toEqual(['2026-12-31','2027-01-01']);
  });
  it('rejects reversed or oversized ranges without partially changing the selection', () => {
    const dates=['2026-10-20'];
    expect(()=>addDateRange(dates,'2026-10-22','2026-10-20')).toThrow('valid start');
    expect(()=>addDateRange(dates,'2026-01-01','2027-01-02')).toThrow('366');
    expect(dates).toEqual(['2026-10-20']);
  });
  it('retains every selected date when converting FormData to a mobile JSON body', () => {
    const form=new FormData();form.set('recurrence','specific_dates');form.set('due_time','16:00');form.append('specific_dates[]','2026-10-20');form.append('specific_dates[]','2026-10-22');
    expect(recurrencePayload(form)).toEqual({recurrence:'specific_dates',due_time:'16:00',specific_dates:['2026-10-20','2026-10-22']});
    const normal=new FormData();normal.set('recurrence','daily');expect(recurrencePayload(normal)).toEqual({recurrence:'daily'});
  });
});
