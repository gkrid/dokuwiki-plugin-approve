<?php

/**
 * Approve Plugin: change default behaviour of replacing $DATE_AT global variable
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Michael Kirchner
 * @author     Blake Martin
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Anika Henke <anika@selfthinker.org>
 */

class action_plugin_approve_dateat extends DokuWiki_Action_Plugin
{
    /** @inheritdoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'modifyDateat');
    }

    public function modifyDateat(Doku_Event $event)
    {
        global $INPUT, $DATE_AT, $lang;
        // we must parse it again since it was already nulled by doku.php
        $DATE_AT = $INPUT->str('at');
        if($DATE_AT) {
            // check for UNIX Timestamp
            if ((string) (int) $DATE_AT === $DATE_AT) {
                $DATE_AT = (int) $DATE_AT;
            } else {
                $date_parse = strtotime($DATE_AT);
                if($date_parse) {
                    $DATE_AT = $date_parse;
                } else {
                    msg(sprintf($lang['unable_to_parse_date'], hsc($DATE_AT)));
                    $DATE_AT = null;
                }
            }
        }
    }
}