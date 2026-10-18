<?php

namespace App\Console\Commands;

use App\Models\AccountAudit;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\TaskActivity;
use App\Services\ExistingActivityRecorder;
use Illuminate\Console\Command;

class ImportActivityHistory extends Command
{
    protected $signature = 'tasksure:import-activity-history';

    protected $description = 'Import supported existing account/task/acknowledgement records into the read-only activity history, without changing source records';

    public function handle(ExistingActivityRecorder $recorder): int
    {
        AccountAudit::orderBy('id')->chunkById(100, function ($rows) use ($recorder) {
            foreach ($rows as $row) {
                $recorder->account($row, true);
            }
        });
        TaskActivity::orderBy('id')->chunkById(100, function ($rows) use ($recorder) {
            foreach ($rows as $row) {
                $recorder->task($row, true);
            }
        });
        CompanyPolicyAcknowledgement::orderBy('id')->chunkById(100, function ($rows) use ($recorder) {
            foreach ($rows as $row) {
                $recorder->acknowledgement($row, true);
            }
        });
        $this->info('Supported existing records imported. Re-running is safe. Missing historical logins/downloads and unknown before/after values were not invented.');

        return self::SUCCESS;
    }
}
