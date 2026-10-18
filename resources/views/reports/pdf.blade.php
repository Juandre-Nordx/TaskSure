<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#183c30}h1{font-size:23px}h2{font-size:15px}table{width:100%;border-collapse:collapse}th,td{padding:7px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;overflow-wrap:break-word}th{background:#e8eee3}thead{display:table-header-group}tr{page-break-inside:avoid}p{line-height:1.6}.task{margin:18px 0;border:1px solid #ddd;padding:10px}.label{font-weight:bold;width:145px}a{color:#286249}footer,.field-help{font-size:8px}.notes{page-break-before:always}.writing-line{border-bottom:1px solid #aaa;height:28px}
</style></head><body>
<h1>TaskSure · team work report</h1>
<p><strong>Company:</strong> {{ $workspace?->company->name }}<br><strong>Store:</strong> {{ $workspace?->name }}</p>
<p><strong>Reporting period:</strong> {{ $report['period'] }} · {{ $report['date_basis'] }}<br>
<strong>Deadline period:</strong> {{ $report['deadline_period'] }}<br>
<strong>Staff member:</strong> {{ $report['staff'] }} · <strong>Overdue reporting:</strong> {{ $report['overdue_status'] }}<br>
<strong>Generated / overdue as of:</strong> {{ $report['generated_at'] }} · Africa/Johannesburg (SAST)</p>
@if($report['additional_filters'])
<p>Additional filters: @foreach($report['additional_filters'] as $label=>$value)<strong>{{ $label }}:</strong> {{ $value }}@unless($loop->last) · @endunless @endforeach</p>
@endif
<p><strong>Selected tasks: {{ $totals['selected'] }}</strong> · <strong>Still overdue: {{ $totals['still_overdue'] }}</strong> · <strong>Completed late: {{ $totals['completed_late'] }}</strong></p>
@include('reports.definitions')
<h2>Comparison</h2>
<table><thead><tr><th>Person</th><th>Role</th><th>Selected</th><th>Completed</th><th>Reviewed</th><th>Not reviewed</th><th>Open</th><th>First on time</th><th>First late</th><th>Denominator</th><th>Still overdue</th><th>Completed late</th><th>Corrections</th></tr></thead><tbody>
@foreach($summary as $s)<tr>@foreach($s as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach
</tbody></table>
<h2>Task deadlines and completion</h2>
@include('reports.task-table', ['export'=>true])
<h2>Task records</h2>
@forelse($rows as $row)
    <div class="task"><h2>#{{ $row['id'] }} · {{ $row['title'] }} · {{ $row['employee'] }}</h2><table>
    @foreach($row as $key=>$value)<tr><td class="label">{{ ucfirst(str_replace('_',' ',$key)) }}</td><td>{{ $value?:'—' }}</td></tr>@endforeach
    </table></div>
@empty
    <p>No tasks match these filters.</p>
@endforelse
<footer>Evidence references require an authenticated account with permission to view the task.</footer>
<section class="notes">
    <h2>Discussion notes</h2>
    <p>{{ $workspace?->company->name }} · {{ $workspace?->name }} · Reporting period: {{ $report['period'] }} · Deadline period: {{ $report['deadline_period'] }}</p>
    @for($i=0; $i<6; $i++)<div class="writing-line"></div>@endfor
    <h2>Follow-up actions</h2>
    <table><thead><tr><th>Action</th><th>Responsible person</th><th>Target date</th><th>Follow-up / outcome</th></tr></thead><tbody>
        @for($i=0; $i<5; $i++)<tr><td style="height:32px;width:40%"></td><td></td><td></td><td></td></tr>@endfor
    </tbody></table>
</section>
</body></html>
