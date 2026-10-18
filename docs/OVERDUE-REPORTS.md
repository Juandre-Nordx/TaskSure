# Overdue-task reporting

The existing Reports page keeps its comparison, first-completion punctuality, individual histories, search/category/priority/status filters, date-basis options and PDF/Excel downloads. New totals and a task deadline table use the same filtered cohort as those existing sections.

## Using the report

1. Select the authorised store using the existing store switcher.
2. Select an assignee, or leave **All permitted people** selected.
3. Keep the existing **Date basis / From / To** options for assigned, due, completed or reviewed tasks. Blank dates mean unrestricted dates.
4. Optionally narrow that cohort with **Deadline from / Deadline to**. These are inclusive Africa/Johannesburg dates, independent of the existing date-basis period. For a report based solely on deadlines, leave the original From/To blank.
5. Choose **All**, **Still overdue** or **Completed late**, then **Apply filters**.
6. Download PDF or Excel. All current filters apply to the comparison, task rows, histories and totals. Totals describe the filtered result; selecting Completed late therefore gives zero Still overdue in that report.

**Still overdue** means currently Assigned or In progress with a deadline strictly before the server's report-generation time. **Completed late** means currently Completed with a known completion time strictly after its deadline. Equal completion/deadline times are on time. Cancelled tasks are excluded from both overdue totals. Missing historical completion times stay unknown and do not count as Completed late.

The report deadline is the current completion attempt's immutable deadline, where available; legacy tasks without a current attempt use their task deadline. The new deadline-range filter uses that same report deadline. The existing **Tasks due in period** date basis continues to use the current task deadline. Normal completion workflows keep those two deadlines equal; historical differences remain visible in individual records.

An earlier late attempt does not count as a currently completed-late task after reopening or an on-time recompletion. Earlier attempts, first-completion punctuality, deadline changes and optional reviews remain visible. Review time never changes completed-late duration. Each saved recurring occurrence is counted individually; calendar projections do not create report records.

The task table shows assignee/role, task, deadline, current status, completion time, overdue classification and elapsed overdue/late time. Deadlines and completion times include seconds, so a one-second late completion is distinguishable from an on-time one. Durations use days, hours, minutes and seconds; they are not hours worked. Still-overdue duration uses one generation timestamp throughout the report, shown in SAST. A later download is a fresh report using the same filters and its own displayed generation time.

The PDF includes company/store, date-basis reporting period, deadline period, staff selection, other active filters, totals, comparison and task details. A separate printable page has discussion-note lines and follow-up actions with responsible person, target date and outcome columns. These are writing spaces in the exported document; this feature does not save meeting notes in TaskSure.

## Permissions

Reports continue through the existing authenticated, active-user, selected-store and company-policy middleware. TaskQuery applies store membership and the user's permitted assignees before reporting filters. Owners see their selected store's records; managers see their scoped staff and their own work, with existing My tasks/Team task scopes preserved. Employees retain their existing personal report access. A forged store or assignee query parameter cannot expand access. Evidence links still require permission on the underlying task.

## Release

- This change adds **no migration**, data conversion, dependency or environment-variable requirement. Do not seed or reset a database.
- Review and merge the report branch before an operator deploys it. No production deploy or production migration was performed during development.
- In Railway, review `php artisan migrate:status` before release. If earlier release migrations remain pending, follow their existing controlled release guides and backups; they are not new report migrations.
- Build with `npm ci` and `npm run build` (the normal Docker build does this). Deploy the reviewed web release through the existing Railway deployment process. Keep its PostgreSQL connection, APP_KEY, private `/data` volume and other variables unchanged. Let the existing startup rebuild Laravel caches; manually managed deployments may run `php artisan view:clear` before rebuilding views.
- Worker and scheduler behaviour is unchanged. Keep the scheduler running so recurring task occurrences are generated and become reportable.
- No mobile source, Capacitor plugin, application version or API contract changed. **No Android rebuild/reinstallation is required** for this web report update. Existing employee mobile task workflows are unchanged.
- Smoke-test owner and manager reports in the correct store, narrow to one staff member and deadline day, select each overdue state, and download the matching PDF. Check known late/on-time/open/cancelled examples and a recurring occurrence. Production data and physical-phone behaviour require operator access; local checks do not establish deployment success.

Database schema is unchanged by this feature, so reverting this report-only commit restores the previous web report without changing task history.
