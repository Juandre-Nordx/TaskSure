<nav class="small-links" aria-label="Super Admin sections" style="margin-bottom:24px">
    <a class="btn {{ request()->is('super-admin/activity-logs*') ? '' : 'primary' }}" href="/super-admin" @if(!request()->is('super-admin/activity-logs*')) aria-current="page" @endif>Stores</a>
    <a class="btn {{ request()->is('super-admin/activity-logs*') ? 'primary' : '' }}" href="/super-admin/activity-logs" @if(request()->is('super-admin/activity-logs*')) aria-current="page" @endif>Activity logs</a>
</nav>
