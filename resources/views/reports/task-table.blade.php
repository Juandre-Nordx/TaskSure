<div class="table-wrap"><table>
    <thead><tr><th>Assignee</th><th>Task</th><th>Deadline · SAST</th><th>Status</th><th>Completion time · SAST</th><th>Overdue reporting</th><th>Time overdue / completed late</th></tr></thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            <td>{{ $row['employee'] }}<br><span class="subline">{{ ucfirst($row['role']) }}</span></td>
            <td>@if($export ?? false)#{{ $row['id'] }} · {{ $row['title'] }}@else<a href="/tasks/{{ $row['id'] }}">#{{ $row['id'] }} · {{ $row['title'] }}</a>@endif</td>
            <td>{{ $row['deadline'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['completed'] }}</td>
            <td>{{ $row['overdue_state'] }}</td><td>{{ $row['time_overdue_or_late'] }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="empty">No tasks match these filters.</td></tr>
    @endforelse
    </tbody>
</table></div>
<p class="field-help">Durations use days (d), hours (h), minutes (m) and seconds (s). Still-overdue time is measured as of {{ $report['generated_at'] }} SAST. Completed-late time stops at completion. Review time is excluded.</p>
