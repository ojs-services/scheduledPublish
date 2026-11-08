# Scheduled Publish

[Türkçe](README_TR.md)

Generic plugin for **OJS 3.3.0.x** (reference: 3.3.0-22). OJS 3.4 and 3.5 have their own package.

Scheduled Publish publishes issues and articles automatically when their scheduled date and time arrive. The editor sets the moment; OJS does the rest, with no server cron job.

## What it does

**Issues.** The form of an unpublished issue (*Issues → Future Issues → Edit*) gets a *Scheduled publication* section with a date and a time. When the time comes, the issue is published exactly as if an editor had clicked *Publish Issue* and confirmed the dialog with its default settings:

- public identifiers (DOI, URN) are assigned as the dialog would;
- the open-access date is set under a delayed-open-access policy;
- the `IssueGridHandler::publishIssue` hook runs, so other plugins react as on a manual publish;
- the issue becomes the **current issue**;
- the issue's **scheduled articles are published**, and an article without a date gets the issue's date;
- the reader notification follows **OJS's own rule** (the dialog's default: on). If Issue Contributor Notify is installed and enabled in the journal, its rule applies instead, as on a manual publish.

The issue's publication date is the **scheduled time**, not the moment the task happened to run.

**Articles.** An article scheduled with a future publication date is published when that date arrives, but only once its issue is published. An article in an unpublished issue waits for the issue; an article whose date is later than the issue's publication is not published with the issue but on its own date.

**Grid.** The Future Issues grid gets a *Scheduled* column: the scheduled time, *Publishing…* while the task runs, or *Not published!* when the tolerance window has passed.

## How the time is handled

- Date and time are entered and stored in the **time zone of the OJS installation** (`time_zone` in `config.inc.php`, else PHP's). The form says which one.
- The tasks run through **OJS's own task runner** (the acron plugin), at the end of page requests, at most once a minute. A scheduled issue is published on the first visit to the site after its time, usually within a minute. No cron job is needed and none is recommended.
- **Tolerance window** (site setting, default 24 hours): if the task runs late (no visitors, server down, plugin disabled), the issue is still published within the window, with the scheduled time as its publication date. Later than that it is **not** published; the issue form and the grid show a warning, and the issue can be rescheduled or published by hand.
- **Cancel:** empty the date in the issue form and save. Publishing the issue by hand also cancels the schedule.
- **One publish:** a claim on the issue prevents two overlapping runs from publishing it twice. An interrupted run is retried after 30 minutes.
- **Runner check:** the issue form and the settings page warn when the plugin's tasks have not run for an hour or the acron plugin is disabled. Right after installation the warning may show until the first run.

## Requirements

- OJS 3.3.0.x; PHP 7.4–8.2. Tested on OJS 3.3.0-22 with PHP 7.4, 8.1 and 8.2.
- The **acron** plugin (shipped with OJS) must be enabled: it is OJS's task runner.
- No `config.inc.php` changes, no database tables, no email templates.

## Installation

Sign in as site administrator, open *Website → Plugins → Upload a new plugin*, upload the `.tar.gz` package and enable **Scheduled Publish** in the generic plugins list. The plugin is site-wide: enabled once, it applies to every journal. Its only setting (the tolerance window) is in the plugin's *Settings* link.

Upgrading from 1.1.0.0: in the generic plugins list open the plugin's *Upgrade* action and upload the new package (not *Upload a new plugin*, which refuses an existing plugin). Existing behaviour (publishing articles whose date has arrived) continues; the new tasks join the task runner's list on the first page request after the upgrade.

**Before disabling or removing the plugin,** check the articles left in the *Scheduled* state with a future date and the issues with a schedule: without the plugin OJS 3.3 publishes neither (it runs no such task). Publish them by hand, or leave the plugin enabled until their dates have passed. A schedule is kept while the plugin is disabled and, within the tolerance window, runs when the plugin is enabled again.

## Who schedules

Scheduling is part of the issue form, so it follows OJS's own permission: journal managers (and site administrators) who can edit and publish issues.

## Logs

Every run writes to OJS's scheduled task log (`files_dir/scheduledTaskLogs/ScheduledPublish*.log`): the issue published, the scheduled and actual time, how many articles were published or kept waiting, how many users were notified, and any step that failed.

## License

GNU GPL v3. See [LICENSE](LICENSE).
