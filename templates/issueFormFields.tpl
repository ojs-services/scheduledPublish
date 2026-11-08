{**
 * plugins/generic/scheduledPublish/templates/issueFormFields.tpl
 *
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * Scheduling fields appended to the issue form of an unpublished issue
 * (hook Templates::Editor::Issues::IssueData::AdditionalMetadata).
 *}
{fbvFormArea id="scheduledPublishArea" title="plugins.generic.scheduledPublish.form.title" class="border"}
	{if $scheduledPublishRunnerWarning}
		<p class="scheduledPublishWarning" style="color:#d00000;font-weight:bold;">{translate key="plugins.generic.scheduledPublish.form.runnerWarning"}</p>
	{/if}
	{if $scheduledPublishState == 'expired'}
		<p class="scheduledPublishWarning" style="color:#d00000;font-weight:bold;">{translate key="plugins.generic.scheduledPublish.form.expired" at=$scheduledPublishAtFormatted|escape}</p>
	{elseif $scheduledPublishState == 'processing'}
		<p>{translate key="plugins.generic.scheduledPublish.form.processing" at=$scheduledPublishAtFormatted|escape}</p>
	{elseif $scheduledPublishState == 'scheduled'}
		<p><strong>{translate key="plugins.generic.scheduledPublish.form.scheduledFor" at=$scheduledPublishAtFormatted|escape by=$scheduledPublishByName|escape}</strong></p>
	{/if}

	{fbvFormSection}
		<div class="scheduledPublishInputs" style="display:flex;flex-wrap:wrap;gap:1em;align-items:flex-end;">
			<div>
				<label for="scheduledPublishDate" class="label">{translate key="plugins.generic.scheduledPublish.form.date"}</label><br/>
				<input type="date" name="scheduledPublishDate" id="scheduledPublishDate" value="{$scheduledPublishDate|escape}" autocomplete="off" />
			</div>
			<div>
				<label for="scheduledPublishTime" class="label">{translate key="plugins.generic.scheduledPublish.form.time"}</label><br/>
				<input type="time" name="scheduledPublishTime" id="scheduledPublishTime" value="{$scheduledPublishTime|escape}" autocomplete="off" />
			</div>
		</div>
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.description" timezone=$scheduledPublishTimeZone|escape}</p>
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.emptyToCancel"}</p>
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.delayNote" hours=$scheduledPublishToleranceHours|escape}</p>
	{/fbvFormSection}

	{if $scheduledPublishFutureArticles > 0}
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.futureArticles" count=$scheduledPublishFutureArticles|escape}</p>
	{/if}
	{if $scheduledPublishCurrentIssue}
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.currentIssue" issue=$scheduledPublishCurrentIssue|escape}</p>
	{/if}
	{if $scheduledPublishIcn == 'auto'}
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.icnAuto"}</p>
	{elseif $scheduledPublishIcn == 'manual'}
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.icnManual"}</p>
	{else}
		<p class="description">{translate key="plugins.generic.scheduledPublish.form.notificationNote"}</p>
	{/if}
{/fbvFormArea}
