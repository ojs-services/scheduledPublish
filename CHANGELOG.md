# Changelog

Requires OJS 3.4.0.x or 3.5.0.x; PHP 8.1–8.2. OJS 3.3 has its own package (1.x); a package does not work on the other line.

## 2.0.0.1 — 2026-11-08

Fixes from the first-release review; no new features.

- **Reader notification follows the publish hook.** The dialog's notification tick is set before the `IssueGridHandler::publishIssue` hook and read after it: a plugin that removes it there (Issue Contributor Notify) suppresses OJS's reader notification, as on a manual publish. Before, the notification was always queued.
- **Escaping.** Every value the plugin prints in the issue form and the settings page (scheduled time, note, time-zone label) is HTML-escaped.
- **Runner warning.** The issue form and the settings page warn when the plugin's tasks have not run for an hour (OJS 3.4: or the acron plugin is disabled). The warning may show briefly after installation, until the first run.
- **Interrupted issue publication.** An article left in the Scheduled state without a date now takes the issue's date in the article task instead of waiting forever.
- **Multi-journal sites.** Public identifier plugins are always loaded for the issue's own journal, also when the task runs during a request of another journal.
- **Scheduler lock (OJS 3.5).** The lock that keeps two runs of a task apart expires after 10 minutes, so a crashed run does not block the next ones.
- The "publishing" flag is shared by all plugin instances; the `HasTaskScheduler` shim checks the core interface through the autoloader.
- Documentation: check scheduled articles before disabling the plugin.

## 2.0.0.0 — 2026-11-08

First release for OJS 3.4 and 3.5. Same features as 1.2.0.0 on OJS 3.3.

- **Issues can be scheduled.** The form of an unpublished issue (Issues → Future Issues → Edit) has a publication date and time. When the time comes the issue is published with its articles, exactly as with "Publish Issue": public identifiers and DOI, open-access date, the publish hook for other plugins, current issue, reader notification under OJS's own rule (queued jobs). The issue's publication date is the scheduled time.
- **"Scheduled" column** in the Future Issues grid shows the scheduled time, "Publishing…" or "Not published!".
- **Articles with a future date stay scheduled** until that date, also in a published issue; OJS 3.4 and 3.5 alone would publish them at once with the future date written on them. Each is published on its own date by the article task.
- **Article task waits for the issue.** An article whose date has arrived is published only when its issue is published (or it has no issue). The core task that OJS ships but never registers would publish regardless.
- **Journal context for tasks.** Publishing runs with the article's or issue's journal in the request, so publication hooks and listeners of other plugins work as on a manual publish.
- **Tolerance window** (site setting, default 24 hours): a late run still publishes within the window; later than that the issue is not published and its form shows a warning.
- **One issue, one publish:** a claim on the issue prevents two overlapping runs from publishing it twice; an abandoned claim is retried after 30 minutes.
- **Tasks run once a minute:** on OJS 3.4 through the acron plugin, on OJS 3.5 through the built-in scheduler (web task runner or `scheduler.php`).
- Locales: English, Turkish, Spanish.
