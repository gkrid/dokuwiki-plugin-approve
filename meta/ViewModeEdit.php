<?php

namespace dokuwiki\plugin\approve\meta;

use dokuwiki\Menu\Item\AbstractItem;

class ViewModeEdit extends AbstractItem {

    /** @inheritdoc */
    public function __construct() {
        parent::__construct();
        $helper = plugin_load('helper', 'approve');

        $this->svg = DOKU_INC . 'lib/plugins/approve/circle-edit-outline.svg';
        $this->label = $helper->getLang('btn_edit_mode');
        $this->accesskey = 'e'; // it replaces the edit button, so the same access key.
    }
}