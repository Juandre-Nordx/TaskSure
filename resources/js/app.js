import { Calendar } from '@fullcalendar/core';
import { calendarOptions, plannedOccurrenceText } from '../shared/calendar.js';
import { responseError } from '../shared/errors.js';
import { DateTime } from 'luxon';
const workspaceStore = document.querySelector('meta[name="tasksure-store"]')?.content;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const el = document.querySelector('#calendar');
if(el){
 const dialog=document.querySelector('#reschedule-dialog'),form=document.querySelector('#reschedule-form');let pending;
 const close=()=>{if(pending)pending.revert();pending=null;dialog.close();};
 const calendar=new Calendar(el,{...calendarOptions,initialView:window.innerWidth<600?'timeGridDay':'dayGridMonth',editable:el.dataset.supervisor==='1',eventDurationEditable:false,eventStartEditable:true,eventSources:[{url:'/calendar/events',extraParams:()=>({...Object.fromEntries(new FormData(document.querySelector('#calendar-filters'))),...(workspaceStore?{_workspace_store_id:workspaceStore}:{})}),failure:error=>{if(error.response?.status===423){location.assign('/company-policy');return;}document.querySelector('#calendar-message').textContent='Calendar could not load. Please refresh or sign in again.';}}],dateClick:info=>{if(el.dataset.supervisor==='1')location.href='/tasks/create?date='+info.dateStr.slice(0,10);},eventDrop:info=>{pending=info;form.reset();const previous=DateTime.fromISO(info.oldEvent.startStr);const shift=DateTime.fromISO(info.event.startStr).toMillis()-previous.toMillis();const due=DateTime.fromISO(info.oldEvent.extendedProps.due_at).plus({milliseconds:shift}).setZone('Africa/Johannesburg');form.elements.due_at.value=due.toFormat("yyyy-MM-dd'T'HH:mm");document.querySelector('#reschedule-title').textContent=info.event.title;document.querySelector('#reschedule-error').textContent='';dialog.showModal();},eventClick:info=>{info.jsEvent.preventDefault();if(info.event.extendedProps.planned){const planned=document.querySelector('#planned-occurrence');planned.querySelector('[data-planned-content]').textContent=plannedOccurrenceText(info.event);planned.showModal();return;}location.href=info.event.url;}});
 calendar.render();document.querySelector('#calendar-filters').addEventListener('submit',e=>{e.preventDefault();calendar.refetchEvents();});document.querySelector('#reschedule-cancel').onclick=close;dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 form.onsubmit=async e=>{e.preventDefault();if(!pending)return;const button=form.querySelector('button');button.disabled=true;try{const body=new FormData(form);body.append('action','reschedule');const r=await fetch('/tasks/'+pending.event.id+'/action',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json',...(workspaceStore?{'X-TaskSure-Store':workspaceStore}:{})},body});if(!r.ok){const data=await r.json();if(data.code==='POLICY_ACKNOWLEDGEMENT_REQUIRED'){location.assign('/company-policy');return;}throw new Error(responseError(data,'Unable to reschedule.'));}pending.revert();pending=null;dialog.close();calendar.refetchEvents();}catch(err){document.querySelector('#reschedule-error').textContent=err.message;}finally{button.disabled=false;}};
}
document.querySelectorAll('[data-upload]').forEach(form=>form.addEventListener('submit',e=>{e.preventDefault();const xhr=new XMLHttpRequest(),progress=form.querySelector('progress'),status=form.querySelector('[data-upload-status]'),button=form.querySelector('button');progress.hidden=false;button.disabled=true;status.textContent='Uploading…';xhr.open('POST',form.action);xhr.setRequestHeader('X-CSRF-TOKEN',csrf);xhr.setRequestHeader('Accept','application/json');if(workspaceStore)xhr.setRequestHeader('X-TaskSure-Store',workspaceStore);xhr.upload.onprogress=e=>{if(e.lengthComputable)progress.value=100*e.loaded/e.total;};xhr.onload=()=>{button.disabled=false;if(xhr.status>=200&&xhr.status<300){status.textContent='Uploaded. Refreshing evidence…';location.reload();}else{try{const data=JSON.parse(xhr.responseText);if(data.code==='POLICY_ACKNOWLEDGEMENT_REQUIRED'){location.assign('/company-policy');return;}status.textContent=responseError(data,'Upload failed.');}catch{status.textContent='Upload failed. Check the file size and try again.';}}};xhr.onerror=()=>{button.disabled=false;status.textContent='Network error. Please try again.';};xhr.send(new FormData(form));}));

const policyForm=document.querySelector('[data-policy-ack]');
document.querySelectorAll('[data-policy-document]').forEach(section=>{
 const button=section.querySelector('[data-policy-read]'),viewer=section.querySelector('[data-policy-viewer]'),frame=viewer.querySelector('iframe');
 button.addEventListener('click',()=>{if(!frame.src)frame.src=frame.dataset.pdfSrc;viewer.hidden=!viewer.hidden;button.setAttribute('aria-expanded',String(!viewer.hidden));button.textContent=viewer.hidden?'Read policy':'Hide PDF';});
});
document.querySelectorAll('[data-policy-draft]').forEach(form=>{
 const format=form.querySelector('[name="format"]');
 const update=()=>form.querySelectorAll('[data-policy-format]').forEach(section=>{const active=section.dataset.policyFormat===format.value;section.hidden=!active;section.querySelectorAll('input,textarea').forEach(input=>{input.disabled=!active;});});
 format.addEventListener('change',update);update();
});
if(policyForm){const checkbox=policyForm.querySelector('[name="acknowledged"]'),button=policyForm.querySelector('[type="submit"]');const reset=()=>{checkbox.checked=false;button.disabled=true;};reset();window.addEventListener('pageshow',reset);checkbox.addEventListener('change',()=>{button.disabled=!checkbox.checked;});}
// Revalidate on history restoration: cached content never bypasses the server guard.
window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});

// Owner assignment preserves existing credentials; new-owner fields apply only to creation.
document.querySelectorAll('[data-owner-setup]').forEach(form=>{
 const owner=form.querySelector('[name="owner_id"]'),fields=form.querySelector('[data-new-owner-fields]');
 const clear=()=>form.querySelectorAll('[type="password"]').forEach(input=>{input.value='';});
 const update=()=>{const creating=!owner.value;fields.hidden=!creating;fields.disabled=!creating;fields.querySelectorAll('[data-owner-required]').forEach(input=>{input.required=creating;});clear();};
 owner.addEventListener('change',update);update();window.addEventListener('pageshow',clear);
});
document.querySelectorAll('[data-owner-password-reset]').forEach(form=>{
 window.addEventListener('pageshow',()=>form.querySelectorAll('[type="password"]').forEach(input=>{input.value='';}));
});

// Recurrence is optional; hidden end dates never change a one-off assignment.
document.querySelectorAll('[data-recurrence-options]').forEach(container=>{
 const repeat=container.querySelector('[name="recurrence"]'),end=container.querySelector('[name="recurrence_until"]'),section=container.querySelector('[data-recurrence-end]'),help=container.querySelector('[data-recurrence-help]'),due=container.closest('form').querySelector('[name="due_at"]');
 const update=()=>{section.hidden=!repeat.value;help.hidden=!repeat.value;end.disabled=!repeat.value;end.min=due?.value.slice(0,10)||'';};
 repeat.addEventListener('change',update);due?.addEventListener('change',update);update();
});
