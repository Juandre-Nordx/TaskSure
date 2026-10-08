# Backup and restore

Back up **both PostgreSQL and evidence**. File metadata, submissions and history are in PostgreSQL; files are on web's private volume. Keep the original production `APP_KEY` securely in your recovery plan. Encrypt backups, restrict access, and keep an off-platform copy with a retention policy.

## Consistent backup

1. Stop the worker and scheduler. Put web in maintenance mode (`php artisan down --retry=60`) and wait for in-flight requests/jobs to finish. This freezes file/row relationships. Record the release SHA and backup time without logging secrets.

2. Use a PostgreSQL client of the same or newer major version than the server. From a protected backup machine, configure `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER` and `PGPASSWORD` securely for the database's external endpoint, or run through a secure tunnel. Railway's private host works only within its private network. Use a protected password file or environment, not a password in command arguments. Apply the provider's TLS requirements. Create a custom-format dump:

   ```sh
   umask 077
   pg_dump --format=custom --no-owner --no-acl --file=tasksure.dump
   pg_restore --list tasksure.dump > tasksure-dump-contents.txt
   ```

   Confirm a successful exit, a nonempty artifact, and expected tables. Do not capture a binary dump through an interactive SSH terminal. A database-service job can instead create a dump file and transfer it securely with your backup tooling.

3. Archive evidence on web into the parent volume directory:

   ```sh
   railway ssh --service web -- sh -c 'tar -C "$UPLOAD_STORAGE_PATH" -czf /data/evidence-backup.tar.gz .'
   railway volume files download /evidence-backup.tar.gz ./evidence-backup.tar.gz
   ```

   Link/select web's volume when prompted. Do not create the archive inside the evidence directory itself. Inspect the archive listing and expected file counts.

4. Calculate SHA-256 checksums for both artifacts. Encrypt and transfer the dump, archive and manifest off-platform. Enable Railway scheduled backups for PostgreSQL and evidence volumes as an additional layer; independent volume snapshots are not coordinated database-and-file backups.

5. Run `php artisan up`, restart worker/scheduler, and check `/health`, sign-in and a private evidence download. Remove temporary artifacts from live volumes after verifying the protected copy.

## Restore rehearsal / recovery

1. Use a separate project or disposable database/volume for rehearsals. Stop all app services during production recovery. Restore the original release and `APP_KEY`; configure target `PG*` and app variables securely.

2. Restore into an **empty** target PostgreSQL database:

   ```sh
   pg_restore --exit-on-error --no-owner --no-acl --dbname="$PGDATABASE" tasksure.dump
   ```

   Connection credentials come from the protected environment/password file. Do not run demo seeders. Inspect users, tasks, submissions, attachments, deadline changes and alerts.

3. Validate the evidence archive checksum and paths. Extract your own verified archive into the private upload directory, outside `public/`. Set ownership to `www-data` and ensure parent directories are traversable.

4. Run `php artisan migrate --force` if the restored release needs newer migrations. Start web, check `/health`, authenticate and open representative old image/PDF evidence. Compare metadata, byte sizes and stored files. Check another employee is denied access.

5. Start worker/scheduler after confirming recovery. Overdue reminders and recurring catch-up may run immediately. Recheck failed jobs and recurrence deduplication. Record recovery duration, data loss window and discrepancies.

The earlier MySQL cloud fixture must be backed up with MySQL tooling if needed; PostgreSQL tools do not convert MySQL data. Existing data migration is a separate operation. Evidence persistence was verified locally, but a live Railway backup/restore rehearsal remains to be performed.
