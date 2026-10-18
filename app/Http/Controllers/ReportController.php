<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\ReportService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function __invoke(Request $r, ReportService $service, TaskController $tasks)
    {
        $filters = $tasks->filters($r);
        $filters += $r->validate([
            'overdue_status' => 'nullable|in:all,still_overdue,completed_late',
            'deadline_from' => 'nullable|date_format:Y-m-d',
            'deadline_to' => array_filter(['nullable', 'date_format:Y-m-d', $r->filled('deadline_from') ? 'after_or_equal:deadline_from' : null]),
        ]);
        $data = $service->run($r->user(), $filters);
        $format = $r->validate(['format' => 'nullable|in:pdf,xlsx'])['format'] ?? null;
        if ($format === 'pdf') {
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml(view('reports.pdf', $data)->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="tasksure-report.pdf"']);
        }if ($format === 'xlsx') {
            $book = new Spreadsheet;
            foreach (['summary' => 'Comparison', 'rows' => 'Task details'] as $key => $title) {
                $sheet = $key === 'summary' ? $book->getActiveSheet() : $book->createSheet();
                $sheet->setTitle($title);
                if ($data[$key]) {
                    $sheet->fromArray(array_keys($data[$key][0]), null, 'A1');
                    foreach ($data[$key] as $i => $row) {
                        foreach (array_values($row) as $j => $value) {
                            $sheet->setCellValueExplicit([$j + 1, $i + 2], (string) $value, DataType::TYPE_STRING);
                        }
                    }$sheet->freezePane('A2');
                }
            }$meta = $book->createSheet()->setTitle('Metric definitions');
            $meta->fromArray([['Company', ''], ['Store', ''], ['Timezone', 'Africa/Johannesburg'], ['Date basis', $filters['basis'] ?? 'due'], ['From', $filters['from'] ?? 'All time'], ['To', $filters['to'] ?? 'All time'], ['Filters', json_encode($filters)], ['First completion on-time denominator', 'Non-cancelled tasks with a first completion; compared to the deadline retained on that attempt.'], ['Counts', 'Selected cohort, assigned/created, due, completed or reviewed in period according to chosen basis. Not a productivity score.'], ['Latest punctuality', 'Latest completion against its own retained deadline. Optional review does not affect punctuality.'], ['Review', 'Completed task counts and optional Reviewed / Not reviewed counts are separate. Missing historical timestamps remain unknown.'], ['Pending', 'Assigned and in progress; reopened work returns to Assigned.'], ['Elapsed time', 'Not hours worked. No time tracking.']]);
            $meta->setCellValueExplicit('B1', $data['workspace']?->company->name ?? '', DataType::TYPE_STRING);
            $meta->setCellValueExplicit('B2', $data['workspace']?->name ?? '', DataType::TYPE_STRING);
            foreach ([
                'Deadline from' => $filters['deadline_from'] ?? 'All time',
                'Deadline to' => $filters['deadline_to'] ?? 'All time',
                'Overdue reporting' => $data['report']['overdue_status'],
                'Staff member' => $data['report']['staff'],
                'Generated / overdue as of (SAST)' => $data['report']['generated_at'],
                'Selected tasks' => $data['totals']['selected'],
                'Still overdue' => $data['totals']['still_overdue'],
                'Completed late' => $data['totals']['completed_late'],
                'Overdue definitions' => 'Still overdue: Assigned or In progress after the deadline as of generation. Completed late: current completion strictly after its retained deadline (task deadline for legacy records). Cancelled and unknown completion times are excluded. Each recurring occurrence is separate.',
                'Deadline range' => 'Inclusive SAST dates, against the report deadline; intersects all existing filters. The existing due date basis continues to use the current task deadline.',
                'Durations' => 'Days (d), hours (h), minutes (m), seconds (s). Completed-late duration stops at completion, not review.',
            ] as $label => $value) {
                $row = $meta->getHighestRow() + 1;
                $meta->setCellValueExplicit('A'.$row, $label, DataType::TYPE_STRING);
                $meta->setCellValueExplicit('B'.$row, (string) $value, DataType::TYPE_STRING);
            }

            return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'tasksure-report.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return view('reports.index', $data + ['employees' => $r->user()->visibleEmployees()->get(), 'categories' => Category::forStore($r->user())->get()]);
    }
}
