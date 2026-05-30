<?php

import('lib.pkp.classes.plugins.GenericPlugin');

class OjsdefPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        return $success;
    }

    public function isSitePlugin()
    {
        return true;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ojsdef.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ojsdef.description');
    }
}
