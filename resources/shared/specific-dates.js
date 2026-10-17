import { Calendar } from '@fullcalendar/core';
import { DateTime } from 'luxon';
import { calendarOptions, BUSINESS_TIMEZONE } from './calendar.js';

export const MAX_SPECIFIC_DATES = 366;
const iso = value => DateTime.fromISO(value, { zone: BUSINESS_TIMEZONE });
export function selectedDates(values) {
  return [...new Set(values.filter(value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && iso(value).isValid))].sort();
}
export function addDateRange(values, start, end) {
  if (!iso(start).isValid || !iso(end).isValid || start > end) throw new Error('Choose a valid start and end date.');
  if (iso(start).diff(iso(end), 'days').days < -365) throw new Error('Choose no more than 366 dates.');
  const dates = [...values];
  for (let day = iso(start); day.toISODate() <= end; day = day.plus({ days: 1 })) dates.push(day.toISODate());
  const result = selectedDates(dates);
  if (result.length > MAX_SPECIFIC_DATES) throw new Error('Choose no more than 366 dates.');
  return result;
}

/** @param {HTMLElement} root */
export function dateSelector(root) {
  const initial = JSON.parse(root.dataset.initial || '[]');
  let dates = selectedDates(Array.isArray(initial) ? initial : []);
  const minimum = root.dataset.min || DateTime.now().setZone(BUSINESS_TIMEZONE).toISODate();
  root.innerHTML = '<fieldset class="specific-dates"><legend>Specific dates · SAST</legend><p class="field-help">Tap calendar dates to add or remove them. Add a whole date range below, then adjust individual dates. Maximum 366 dates.</p><div data-date-calendar></div><div class="date-range"><label>Range start<input type="date" data-range-start></label><label>Range end<input type="date" data-range-end></label><button class="btn" type="button" data-add-range>Add date range</button></div><label>Deadline time for each selected date · SAST<input type="time" name="due_time" data-date-time required></label><p class="field-help" data-date-count role="status" aria-live="polite"></p><div class="date-preview" data-date-preview></div><div data-date-inputs></div><p class="error" data-date-error role="alert"></p></fieldset>';
  const time = root.querySelector('[data-date-time]'); time.value = root.dataset.time || '16:00';
  const start = root.querySelector('[data-range-start]'), end = root.querySelector('[data-range-end]');
  start.min = minimum; end.min = minimum;
  const error = root.querySelector('[data-date-error]');
  let calendar;
  const paint = () => root.querySelectorAll('.fc-daygrid-day[data-date]').forEach(cell => {
    const date = cell.getAttribute('data-date');cell.classList.toggle('date-selected', dates.includes(date));cell.setAttribute('aria-pressed', String(dates.includes(date)));
  });
  const refresh = () => {
    time.setCustomValidity(dates.length ? '' : 'Select at least one date.');
    root.querySelector('[data-date-count]').textContent = `${dates.length} task occurrence${dates.length === 1 ? '' : 's'} selected · Due ${time.value || '—'} SAST on each date. Past and already-worked occurrences retain their history.`;
    const inputs = root.querySelector('[data-date-inputs]'); inputs.replaceChildren();
    const preview = root.querySelector('[data-date-preview]');preview.replaceChildren();
    for (const date of dates) {
      const input = document.createElement('input');input.type = 'hidden';input.name = 'specific_dates[]';input.value = date;inputs.append(input);
      const button = document.createElement('button');button.type = 'button';button.className = 'btn small';
      const text = `${iso(date).toFormat('d MMM yyyy')} · ${time.value || '—'}`;button.textContent = text + (date < minimum ? ' · Past date' : ' ×');button.setAttribute('aria-label', `Remove ${iso(date).toFormat('d MMM yyyy')}`);button.disabled = date < minimum;
      button.onclick = () => toggle(date);preview.append(button);
    }
    paint();
  };
  const toggle = date => {
    error.textContent = '';
    if (date < minimum) { error.textContent = 'Past dates are retained. Choose a future date.';return; }
    if (dates.includes(date)) dates = dates.filter(value => value !== date);
    else if (dates.length < MAX_SPECIFIC_DATES) dates = selectedDates([...dates, date]);
    else {error.textContent = 'Choose no more than 366 dates.';return;}
    refresh();
  };
  root.querySelector('[data-add-range]').onclick = () => {
    try {
      if (start.value < minimum) throw new Error('Range start must be today or later.');
      dates = addDateRange(dates, start.value, end.value);error.textContent = '';refresh();
    } catch (e) { error.textContent = e.message; }
  };
  time.addEventListener('input', refresh);
  const keydown = event => {
    const cell = event.target.closest?.('.fc-daygrid-day[data-date]');
    if (cell && [' ', 'Enter'].includes(event.key)) {event.preventDefault();toggle(cell.getAttribute('data-date'));}
  };
  root.addEventListener('keydown', keydown);
  const resize = () => calendar?.updateSize(); const details = root.closest('details');details?.addEventListener('toggle', resize);
  refresh();
  return {
    setActive(active) {
      root.hidden = !active;root.querySelector('fieldset').disabled = !active;
      if (active && !calendar) {
        calendar = new Calendar(root.querySelector('[data-date-calendar]'), { ...calendarOptions, initialView: 'dayGridMonth', initialDate: dates.find(date => date >= minimum) || minimum, headerToolbar: {left: 'prev,next', center: 'title', right: 'today'}, dayMaxEvents: false, dateClick: info => toggle(info.dateStr), datesSet: paint, dayCellDidMount: info => {info.el.tabIndex = 0;info.el.setAttribute('role','button');info.el.setAttribute('aria-label', DateTime.fromJSDate(info.date).setZone(BUSINESS_TIMEZONE).toFormat('d MMM yyyy'));} });
        calendar.render();paint();
      }
      if (active) resize();
    },
    destroy() {calendar?.destroy();root.removeEventListener('keydown', keydown);details?.removeEventListener('toggle', resize);},
  };
}

/** @param {HTMLFormElement} form */
export function bindRecurrenceForm(form) {
  const select = form.querySelector('[name="recurrence"], [name="frequency"]');
  const selector = dateSelector(form.querySelector('[data-specific-picker]'));
  const intervals = [...form.querySelectorAll('[data-interval] input')];
  const required = new Set(intervals.filter(input => input.required));
  const end = form.querySelector('[data-recurrence-end]');
  const key = form.querySelector('[name="creation_key"]');
  const update = () => {
    const specific = !select || select.value === 'specific_dates';selector.setActive(specific);
    form.querySelectorAll('[data-interval]').forEach(section => {section.hidden = specific;});
    intervals.forEach(input => {input.disabled = specific;input.required = !specific && required.has(input);});
    if (end) {end.hidden = !select?.value || specific;end.querySelectorAll('input').forEach(input => {input.disabled = !select?.value || specific;});}
    if (key) key.disabled = !specific;
  };
  select?.addEventListener('change', update);update();
  return () => {selector.destroy();select?.removeEventListener('change', update);};
}

/** @param {FormData} data */
export function recurrencePayload(data) {
  const body = Object.fromEntries(data);
  delete body['specific_dates[]'];
  if (body.recurrence === 'specific_dates' || body.frequency === 'specific_dates' || data.has('specific_dates[]')) body.specific_dates = data.getAll('specific_dates[]');
  return body;
}
