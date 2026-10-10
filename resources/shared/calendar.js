import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import luxonPlugin from '@fullcalendar/luxon3';

export const BUSINESS_TIMEZONE = 'Africa/Johannesburg';

export const calendarOptions = {
  plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, luxonPlugin],
  timeZone: BUSINESS_TIMEZONE,
  height: 'auto',
  headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
  buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
  eventDurationEditable: false,
};
