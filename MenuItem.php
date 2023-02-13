<?php

namespace dokuwiki\plugin\approve;

use dokuwiki\Menu\Item\AbstractItem;

/** @inheritdoc */
class MenuItem extends AbstractItem
{
    protected $type = 'approvemodechange';

    /** @inheritdoc */
    public function __construct()
    {
        GLOBAL $INFO;
        parent::__construct();

        if ($_SESSION["approve_mode"] !== 'edit') {
            $title = 'Viewmode';
        } ELSE {
            $title = 'Editmode';
        }

        $this->id = $INFO['id'];
        $this->label = $title;
        $this->svg = __DIR__ . '/circle-edit-outline.svg';
    }


}

