<?php

    namespace dokuwiki\plugin\approve;

    use dokuwiki\Menu\Item\Edit;

    /**
     * Class Editmodus
     *
     * @package dokuwiki\plugin\approve
     */
    class Editmodus extends Edit {

        /** @var string do action for this plugin */
        protected $type = 'approvemodechange';

        protected $accesskey = 'z';

        /** @var string icon file */
        protected $svg = __DIR__ . '/circle-edit-outline.svg';

        /**
         * MenuPageItem constructor.
         * @param string $Mode (can be passed in from the event handler)
         */
        public function __construct($Mode = "view") {
            global $REV, $INFO;
            if ($Mode !== 'edit') {
                $this->label = 'Zum Editmode';
            } else {
                $this->label = 'Zum Viewmode';
            }
            parent::__construct();

            }
        }




