# Backup and restore

Back up **both MySQL and evidence**. File rows, paths, submissions and history are in MySQL; files are on the web volume. Keep the original production APP_KEY securely alongside a separately protected recovery plan (encrypted sessions and future encrypted app data depend on it). Backups contain employee information and evidence: encrypt them, restrict access, and keep an off-platform copy with a retention policy.

## Consistent backup

1. Stop or scale down the worker and scheduler. Put web in maintenance mode (`php artisan down --retry=60`) and wait for in-flight requests/jobs to finish. This freezes file/row relationships. Record the release SHA, time and service variables without logging secrets.
2. Back up MySQL on its database service using `mysqldump --single-transaction --no-tablespaces --routines --triggers --events`. Use a restricted credential file or `MYSQL_PWD` supplied from the service environment, never passwords as command arguments. For Railway's MySQL service, its own container contains the MySQL client. For example, from a protected local terminal (adjust service and database variable names to those provided by your template):

   ```sh
   umask 077
   railway ssh --service mysql -- sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --no-tablespaces --routines --triggers --events "$MYSQL_DATABASE"' > tasksure.sql
   ```

   Verify the dump is nonempty and has the expected tables; confirm the command exited successfully. Railway SSH may allocate a terminal depending on CLI options: use its documented noninteractive mode and ensure diagnostics are not mixed into the SQL stream. Alternatively create the SQL file inside the database service and download it securely with your backup tooling. Never treat a log capture as a verified dump.
3. On web, archive the configured private directory to the **parent** volume directory:

   ```sh
   railway ssh --service web -- sh -c 'tar -C "$UPLOAD_STORAGE_PATH" -czf /data/evidence-backup.tar.gz .'
   railway volume files download /evidence-backup.tar.gz ./evidence-backup.tar.gz
   ```

   Select web's volume when prompted. The archive must not be created inside the evidence directory itself. Inspect the archive listing and expected file counts.
4. Calculate SHA-256 checksums on both downloaded artifacts. Encrypt and transfer the SQL, archive and manifest off-platform. Enable Railway scheduled backups for MySQL/evidence volumes as an additional recovery layer; volume backups alone are not coordinated SQL-and-files backups.
5. Return web with `php artisan up`, restore worker/scheduler replicas, and check `/health`, sign-in and a private evidence download. Remove temporary backup artifacts from live volumes after verifying the protected copy.

## Restore rehearsal / recovery

1. Use a separate Railway project or disposable local database/volume. Stop all app services during production recovery. Restore the original release and APP_KEY; set secure environment variables without copying secrets into source.
2. Restore SQL into the **empty** target database using the MySQL client with credentials from a protected file/environment. Do not run demo seeders. Inspect users, tasks, submissions, attachments, deadline changes and alerts.
3. Validate the archive checksum. Extract only your own verified archive into the configured private upload directory, outside public/. Check its paths before extraction. Set ownership for the web PHP-FPM user (`www-data`) and ensure parent directories can be traversed.
4. Run `php artisan migrate --force` only if the restored release needs newer migrations. Start web, check `/health`, authenticate as an allowed user and open representative old image/PDF evidence. Compare attachment metadata, byte sizes and stored files. Check that a different employee is denied access.
5. Start worker and scheduler only after confirming recovery. Be aware overdue reminders and recurring catch-up may run immediately. Recheck failed jobs and recurrence deduplication. Record actual recovery duration, data loss window and discrepancies.

The app's files were verified across a local web-container restart, and its automated suite checks durable storage access. A live Railway backup/restore rehearsal has not been performed; schedule one before relying on this recovery procedure.
