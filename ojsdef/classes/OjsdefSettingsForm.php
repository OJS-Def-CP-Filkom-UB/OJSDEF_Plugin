<?php

import('lib.pkp.classes.form.Form');

class OjsdefSettingsForm extends Form
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new \FormValidatorUrl($this, 'backend_url', 'required',
            'plugins.generic.ojsdef.settings.backendUrl'));
        $this->addCheck(new \FormValidator($this, 'api_key', 'required',
            'plugins.generic.ojsdef.settings.apiKey'));
        $this->addCheck(new \FormValidator($this, 'target_id', 'required',
            'plugins.generic.ojsdef.settings.targetId'));
        $this->addCheck(new \FormValidatorPost($this));
        $this->addCheck(new \FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $this->setData('backend_url',      $this->plugin->getSetting(0, 'backend_url')     ?? 'https://api.ojsdef.id');
        $this->setData('api_key',          $this->plugin->getSetting(0, 'api_key')         ?? '');
        $this->setData('target_id',        $this->plugin->getSetting(0, 'target_id')       ?? '');
        $this->setData('connection_mode',  $this->plugin->getSetting(0, 'connection_mode') ?? 'unknown');
        $last = $this->plugin->getSetting(0, 'last_heartbeat_at');
        $this->setData('last_heartbeat_at', $last ? date('Y-m-d H:i:s', (int) $last) : null);
    }

    public function readInputData(): void
    {
        $this->readUserVars(['backend_url', 'api_key', 'target_id']);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(0, 'backend_url',       $this->getData('backend_url'));
        $this->plugin->updateSetting(0, 'api_key',           $this->getData('api_key'));
        $this->plugin->updateSetting(0, 'target_id',         $this->getData('target_id'));
        // Reset agar probe diulangi dengan settings baru
        $this->plugin->updateSetting(0, 'connection_mode',   'unknown');
        $this->plugin->updateSetting(0, 'last_heartbeat_at', 0);
        return parent::execute(...$functionArgs);
    }
}
