<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#183c30}h1{font-size:23px}h2{font-size:15px}table{width:100%;border-collapse:collapse}th,td{padding:7px;border-bottom:1px solid #ddd;text-align:left}th{background:#e8eee3}p{line-height:1.6}.task{page-break-inside:avoid;margin:18px 0;border:1px solid #ddd;padding:10px}.label{font-weight:bold;width:145px}a{color:#286249}pre{white-space:pre-wrap}footer{font-size:8px}</style></head><body><h1>TaskSure · employee work report</h1><p>Africa/Johannesburg · Generated {{ \App\Support\BusinessTime::show(now()) }}</p><p>Filters: {{ json_encode($filters) }}</p>
@include('reports.definitions')<h2>Comparison</h2><table><thead><tr><th>Employee</th><th>Selected</th><th>Approved</th><th>Open</th><th>Review</th><th>First on time</th><th>First late</th><th>Denominator</th><th>Overdue</th><th>Corrections</th></tr></thead><tbody>
@foreach($summary as $s)<tr>
@foreach($s as $v)<td>{{ $v }}</td>
@endforeach</tr>
@endforeach</tbody></table><h2>Task records</h2>
@forelse($rows as $row)<div class="task"><h2>#{{ $row['id'] }} · {{ $row['title'] }} · {{ $row['employee'] }}</h2><table>
@foreach($row as $key=>$value)<tr><td class="label">{{ ucfirst(str_replace('_',' ',$key)) }}</td><td style="overflow-wrap:break-word">{{ $value?:'—' }}</td></tr>
@endforeach</table></div>
@empty<p>No tasks match these filters.</p>
@endforelse<footer>Evidence references require an authenticated account with permission to view the task.</footer></body></html>
