# Changelog

Requires OJS 3.3.0.x; PHP 7.4–8.2. OJS 3.4 and 3.5 have their own package; a package does not work on the other line.

## 1.2.0.1 — 2026-11-08

Fixes from the first-release review; no new features.

- **Escaping.** Every value the plugin prints in the issue form and the settings page (scheduled time, note, time-zone label) is HTML-escaped.
- **Runner warning.** The issue form and the settings page warn when the plugin's tasks have not run for an hour or the acron plugin is disabled, so a schedule is not made on a site where nothing would publish it. The warning may show briefly after installation, until the first run.
- **Interrupted issue publication.** An article left in the Scheduled state without a date (the issue was published, the article step failed) now takes the issue's date in the article task instead of waiting forever.
- **Multi-journal sites.** Public identifier plugins are always loaded for the issue's own journal, also when the task runs during a request of another journal.
- **Wrong line.** The package no longer fails on OJS 3.4 or 3.5: `index.php` returns nothing there, and OJS reports the plugin as not loadable.
- The "publishing" flag is shared by all plugin instances, so the manual-publish hook cannot clear a schedule during a run.
- Documentation: upgrade through the plugin's *Upgrade* action; check scheduled articles before disabling the plugin.

## 1.2.0.0 — 2026-11-08

Issue scheduling, and a safer article task.

- **Issues can be scheduled.** The form of an unpublished issue (Issues → Future Issues → Edit) has a publication date and time. When the time comes the issue is published with its articles, exactly as with "Publish Issue": public identifiers, open-access date, the publish hook for other plugins, current issue, reader notification under OJS's own rule. The issue's publication date is the scheduled time.
- **"Scheduled" column** in the Future Issues grid shows the scheduled time, "Publishing…" or "Not published!".
- **Articles with a date later than the issue** are not published with the issue; each is published on its own date (OJS 3.3 already behaves so; the plugin's article task now completes it).
- **Article task waits for the issue.** An article whose date has arrived is published only when its issue is published (or it has no issue). The core task that version 1.1.0.0 registered published regardless and emptied the article's date when the issue was unpublished.
- **Journal context for tasks.** Publishing runs with the article's or issue's journal in the request, so publication hooks of other plugins (ORCID, DOI, search index, Issue Contributor Notify) work as on a manual publish.
- **Tolerance window** (site setting, default 24 hours): a late run still publishes within the window; later than that the issue is not published and its form shows a warning.
- **One issue, one publish:** a claim on the issue prevents two overlapping runs from publishing it twice; an abandoned claim is retried after 30 minutes.
- **Tasks run at most once a minute** through OJS's task runner. Version 1.1.0.0 used `hour="*"`, which the task runner on PHP 7 reads as "once a day".
- Locales: English, Turkish, Spanish.

## 1.1.0.0 — 2026-09-15

- Registers OJS's own PublishSubmissions task with the task runner so that an article scheduled with a future date is published when the date arrives. OJS ships the task but never registers it.
