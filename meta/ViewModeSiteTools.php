<?php

namespace dokuwiki\plugin\approve\meta;

use dokuwiki\Menu\Item\AbstractItem;

class ViewModeSiteTools extends AbstractItem {

    /** @inheritdoc */
    public function __construct() {
        parent::__construct();

        $helper = plugin_load('helper', 'approve');
        $viewmode = get_doku_pref('approve_viewmode', false);
        if ($viewmode) {
            $this->svg = DOKU_INC . 'lib/plugins/approve/toggle-on-solid.svg';
            $this->label = $helper->getLang('btn_view_mode');
        } else {
            $this->svg = DOKU_INC . 'lib/plugins/approve/toggle-off-solid.svg';
            $this->label = $helper->getLang('btn_edit_mode');
        }
    }
}