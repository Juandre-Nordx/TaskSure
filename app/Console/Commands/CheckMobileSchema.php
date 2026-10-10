<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckMobileSchema extends Command
{
    protected $signature = 'tasksure:mobile-schema {--json : Print a credential-free JSON report}';

    protected $description = 'Check the current database connection and mobile API schema without changing data';

    public function handle(): int
    {
        try {
            $connection = DB::connection();
            $schema = $connection->getSchemaBuilder();
            $repository = app('migration.repository');
            $migration = '2026_10_10_000001_create_mobile_access_tables';
            $recorded = $repository->repositoryExists() && in_array($migration, $repository->getRan(), true);
            $tables = [];
            foreach ([
                'personal_access_tokens' => ['id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at'],
                'push_devices' => ['id', 'user_id', 'personal_access_token_id', 'platform', 'token_hash', 'token', 'created_at', 'updated_at'],
                'push_deliveries' => ['id', 'alert_id', 'push_device_id', 'status', 'sent_at', 'error', 'created_at', 'updated_at'],
            ] as $table => $columns) {
                $tables[$table] = $schema->hasTable($table) && $schema->hasColumns($table, $columns);
            }
            $identity = $connection->getDriverName() === 'pgsql'
                ? $connection->selectOne('select current_database() as database_name, current_schema() as schema_name')
                : null;
            $ready = $recorded && ! in_array(false, $tables, true);
            $report = [
                'connection' => $connection->getName(), 'driver' => $connection->getDriverName(),
                'database' => $identity?->database_name ?? $connection->getDatabaseName(), 'schema' => $identity?->schema_name,
                'database_url_override' => (bool) $connection->getConfig('url'),
                'migration' => $migration, 'migration_recorded' => $recorded, 'tables' => $tables, 'ready' => $ready,
            ];
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR));
            } else {
                $this->line('Connection: '.$report['connection'].' ('.$report['driver'].')');
                $this->line('Database: '.$report['database'].($report['schema'] ? '; schema: '.$report['schema'] : ''));
                $this->line('Database URL override: '.($report['database_url_override'] ? 'present' : 'absent'));
                $this->table(['Check', 'Result'], [
                    [$migration, $recorded ? 'Recorded' : 'Not recorded'],
                    ...array_map(fn ($table, $valid) => [$table.' columns', $valid ? 'OK' : 'Missing/incomplete'], array_keys($tables), $tables),
                ]);
                if (! $ready) {
                    $this->error('Mobile schema is not ready. Review migration status and database/schema settings before applying the existing migration.');
                }
            }

            return $ready ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            // Database errors can contain connection details; do not print credentials.
            $this->error('Could not inspect the database. Check this service\'s database variables and migration logs.');

            return self::FAILURE;
        }
    }
}
