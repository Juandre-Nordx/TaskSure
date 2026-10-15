import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import luxonPlugin from '@fullcalendar/luxon3';
import { DateTime } from 'luxon';

export const BUSINESS_TIMEZONE = 'Africa/Johannesburg';

// Render this as textContent: all titles/instructions remain plain user text.
export function plannedOccurrenceText(event) {
  const p = event.extendedProps;
  const time = value => DateTime.fromISO(value).setZone(BUSINESS_TIMEZONE).toFormat('d MMM yyyy, HH:mm');
  return [p.assignment_title, `${p.frequency[0].toUpperCase() + p.frequency.slice(1)} recurrence`, `Deadline: ${time(p.due_at)} SAST`,
    p.scheduled_at ? `Scheduled start: ${time(p.scheduled_at)} SAST` : '', `Responsible person: ${p.assignee}`, `Store area: ${p.area}`, p.instructions,
    'This is a planned occurrence. A separate task will become available for this date. Each occurrence has its own deadline, proof and history.'].filter(Boolean).join('\n\n');
}

export const calendarOptions = {
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, luxonPlugin],
  timeZone: BUSINESS_TIMEZONE,
  height: 'auto',
  headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
  buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
  eventDurationEditable: false,
};
