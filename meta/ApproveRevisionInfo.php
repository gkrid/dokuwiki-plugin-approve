<?php

namespace dokuwiki\plugin\approve\meta;

class ApproveRevisionInfo
{
    /* @var array */
    protected $info;

    /**
     * Constructor
     *
     * @param array $info Approve Revision Information structure with entries:
     *      - status: "approved" or "ready_for_approval"
     *      - date:  unix timestamp
     *      - id:    page id
     *      - user:  username
     *      additionally,
     *      - version:   (optional) version for approved revisions
     */
    public function __construct($info = null)
    {
        $this->info = $info;
    }

    /**
     * Return or set a value of associated key of revision information
     * but does not allow to change values of existing keys
     *
     * @param string $key
     * @param mixed $value
     * @return string|null
     */
    public function val($key, $value = null)
    {
        if (isset($value) && !array_key_exists($key, $this->info)) {
            // setter, only for new keys
            $this->info[$key] = $value;
        }
        if (array_key_exists($key, $this->info)) {
            // getter
            return $this->info[$key];
        }
        return null;
    }

    /**
     * edit date and time of the page
     *
     * @return string
     */
    public function showEditDate()
    {
        $formatted = dformat($this->val('date'));
        return '<span class="date">'. $formatted .'</span>';
    }

    /**
     * person who changed the status of the page
     *
     * @return string
     */
    public function showEditor()
    {
        $html = '<bdi>'. editorinfo($this->val('user')) .'</bdi>';
        return '<span class="user">'. $html. '</span>';
    }

    /**
     * name of the page or media file
     *
     * @return string
     */
    public function showFileName()
    {
        $id = $this->val('id');
        $at = $this->val('date');

        $params = ['at' => $at];
        $href = wl($id, $params, false, '&');
        $display_name = useHeading('navigation') ? hsc(p_get_first_heading($id)) : $id;
        if (!$display_name) $display_name = $id;
        $exists = page_exists($id);

        if($exists) {
            $class = 'wikilink1';
        } else {
            //revision is not in attic
            return $display_name;
        }
        return '<a href="'.$href.'" class="'.$class.'">'.$display_name.'</a>';
    }
}