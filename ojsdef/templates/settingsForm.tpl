{**
 * templates/settingsForm.tpl
 * OJSDef Plugin Settings Form
 *}
<script>
    $(function() {
        $('#ojsdefSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    });
</script>

<form class="pkp_form" id="ojsdefSettingsForm" method="post"
      action="{url router=$smarty.const.ROUTE_PAGE page="management" op="plugin"
               plugin="ojsdef" category="generic"}">
    {csrf}
    {include file="controllers/notification/inlineNotification.tpl"
             notificationId="ojsdefFormNotification"}

    {* Setup Instructions *}
    <div class="pkp_helpers_clear">
        <h3>{translate key="plugins.generic.ojsdef.settings.setup"}</h3>
        <p>{translate key="plugins.generic.ojsdef.settings.setup.description"}</p>
        <ol>
            <li>{translate key="plugins.generic.ojsdef.settings.step1"}</li>
            <li>{translate key="plugins.generic.ojsdef.settings.step2"}</li>
            <li>{translate key="plugins.generic.ojsdef.settings.step3"}</li>
        </ol>
    </div>

    {* Connection Settings *}
    {fbvFormArea id="ojsdefSettings"}
        {fbvFormSection}
            {fbvElement type="text" id="backend_url" required="true"
                label="plugins.generic.ojsdef.settings.backendUrl"
                value=$backend_url size=$fbvStyles.size.MEDIUM}
        {/fbvFormSection}
        {fbvFormSection}
            {fbvElement type="text" id="api_key" required="true"
                label="plugins.generic.ojsdef.settings.apiKey"
                value=$api_key size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
        {fbvFormSection}
            {fbvElement type="text" id="target_id" required="true"
                label="plugins.generic.ojsdef.settings.targetId"
                value=$target_id size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
    {/fbvFormArea}

    {* Connection Status (read-only) *}
    <div class="pkp_helpers_clear">
        <h3>{translate key="plugins.generic.ojsdef.status.connection"}</h3>
        <table class="pkpTable">
            <tbody>
                <tr>
                    <td><strong>{translate key="plugins.generic.ojsdef.status.connection"}</strong></td>
                    <td>
                        {if $last_heartbeat_at}
                            {translate key="plugins.generic.ojsdef.status.connected"}
                        {else}
                            {translate key="plugins.generic.ojsdef.status.disconnected"}
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td><strong>Mode</strong></td>
                    <td>
                        {if $connection_mode == 'direct'}
                            {translate key="plugins.generic.ojsdef.status.directMode"}
                        {elseif $connection_mode == 'heartbeat'}
                            {translate key="plugins.generic.ojsdef.status.heartbeatMode"}
                        {else}
                            {translate key="plugins.generic.ojsdef.status.unknown"}
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td><strong>{translate key="plugins.generic.ojsdef.status.lastHeartbeat"}</strong></td>
                    <td>{$last_heartbeat_at|default:"—"}</td>
                </tr>
            </tbody>
        </table>

        {if $connection_mode == 'heartbeat'}
        <div class="pkp_notification pkp_notification_warning">
            <p>{translate key="plugins.generic.ojsdef.status.firewallWarning"}</p>
        </div>
        {/if}
    </div>

    {fbvFormButtons submitText="plugins.generic.ojsdef.settings.save"}
</form>
