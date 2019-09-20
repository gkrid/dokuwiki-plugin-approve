<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once DOKU_PLUGIN . 'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_approve_notification extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_NOTIFICATION_REGISTER_SOURCE', 'AFTER', $this, 'add_notifications_source');
        $controller->register_hook('PLUGIN_NOTIFICATION_GATHER', 'AFTER', $this, 'add_notifications');
        $controller->register_hook('PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES', 'AFTER', $this, 'add_notification_cache_dependencies');


    }

    public function add_notifications_source(Doku_Event $event)
    {
        $event->data[] = 'approve';
    }

    public function add_notification_cache_dependencies(Doku_Event $event)
    {
        if (!in_array('approve', $event->data['plugins'])) return;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'approve_db');
        $event->data['dependencies'][] = $db_helper->getDB()->getAdapter()->getDbFile();
    }

    public function add_notifications(Doku_Event $event)
    {
        if (!in_array('approve', $event->data['plugins'])) return;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'approve_db');
        $sqlite = $db_helper->getDB();

        $user = $event->data['user'];

        $q = 'SELECT page.page, revision.rev
                    FROM page INNER JOIN revision ON page.page = revision.page
                    WHERE page.hidden = 0 AND page.approver=?
                      AND revision.current=1 AND revision.approved IS NULL';
        $res = $sqlite->query($q, $user);

        $notifications = $sqlite->res2arr($res);

        foreach ($notifications as $notification) {
            $page = $notification['page'];
            $rev = $notification['rev'];

            $link = '<a class="wikilink1" href="' . wl($page) . '">';
            if (useHeading('content')) {
                $heading = p_get_first_heading($page);
                if (!blank($heading)) {
                    $link .= $heading;
                } else {
                    $link .= noNSorNS($page);
                }
            } else {
                $link .= noNSorNS($page);
            }
            $link .= '</a>';
            $full = sprintf($this->getLang('notification full'), $link);
            $event->data['notifications'][] = [
                'plugin' => 'approve',
                'full' => $full,
                'brief' => $link,
                'timestamp' => (int)$rev
            ];
        }
    }
}
