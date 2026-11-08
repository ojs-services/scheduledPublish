{**
 * plugins/generic/scheduledPublish/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * Site-wide settings of the Scheduled Publish plugin.
 *}
<script>
	$(function() {ldelim}
		$('#scheduledPublishSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="scheduledPublishSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="scheduledPublishSettingsFormNotification"}

	<p>{translate key="plugins.generic.scheduledPublish.settings.intro"}</p>

	{fbvFormArea id="scheduledPublishSettingsArea"}
		{fbvFormSection description="plugins.generic.scheduledPublish.settings.toleranceHours.description"}
			{fbvElement type="text" id="toleranceHours" value=$toleranceHours label="plugins.generic.scheduledPublish.settings.toleranceHours" required=true size=$fbvStyles.size.SMALL}
		{/fbvFormSection}
	{/fbvFormArea}

	<p class="description">{translate key="plugins.generic.scheduledPublish.settings.timeZone" timezone=$scheduledPublishTimeZone}</p>
	<p class="description">{translate key="plugins.generic.scheduledPublish.settings.runner"}</p>

	{fbvFormButtons submitText="common.save"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
