<?php
/**
 * DokuWiki Plugin watchcycle (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class admin_plugin_approve extends DokuWiki_Admin_Plugin
{

    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /** @var helper_plugin_approve */
    protected $helper;

    /**
     * @return helper_plugin_sqlite
     */
    protected function sqlite() {
        if (!$this->sqlite) {
            /** @var helper_plugin_approve_db $db_helper */
            $db_helper = plugin_load('helper', 'approve_db');
            $this->sqlite = $db_helper->getDB();
        }
        return $this->sqlite;
    }

    /**
     * @return helper_plugin_approve
     */
    protected function helper() {
        if (!$this->helper) {
            $helper = plugin_load('helper', 'approve');
            $this->helper = $helper;
        }
        return $this->helper;
    }

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort()
    {
        return 1;
    }

    protected function getPages() {
        global $conf;
        $datadir = $conf['datadir'];
        if (substr($datadir, -1) != '/') {
            $datadir .= '/';
        }

        $directory = new RecursiveDirectoryIterator($datadir, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory);

        $pages = [];
        /** @var SplFileInfo $fileinfo */
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) continue;

            $path = $fileinfo->getPathname();
            //remove .txt
            $id = str_replace('/', ':', substr($path, strlen($datadir), -4));
            $pages[] = $id;
        }

        return $pages;
    }

    protected function updatePage()
    {
        //clean current settings
        $this->sqlite()->query('DELETE FROM page');

        $wikiPages = $this->getPages();
        $no_apr_namespace = $this->helper()->no_apr_namespace();
        $weighted_assignments = $this->helper()->weighted_assignments();
        foreach ($wikiPages as $id) {
            if ($this->helper()->isPageAssigned($id, $approver, $weighted_assignments)) {
                $data = [
                    'page' => $id,
                    'hidden' => $this->helper()->in_hidden_namespace($id, $no_apr_namespace) ? '1' : '0'
                ];
                if (!blank($approver)) {
                    $data['approver'] = $approver;
                }
                $this->sqlite()->storeEntry('page', $data);
            }
        }
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
        global $ID;

        /* @var Input */
        global $INPUT;

        if($INPUT->str('action') && $INPUT->arr('assignment') && checkSecurityToken()) {
            $assignment = $INPUT->arr('assignment');
            //insert empty string as NULL
            if ($INPUT->str('action') === 'delete') {
                $this->sqlite()->query('DELETE FROM maintainer WHERE id=?', $assignment['id']);
                $this->updatePage();
            } else if ($INPUT->str('action') === 'add' && !blank($assignment['assign'])) {
                $data = [
                    'namespace' => $assignment['assign']
                ];
                if (!blank($assignment['approver'])) {
                    $data['approver'] = $assignment['approver'];
                }
                $this->sqlite()->storeEntry('maintainer', $data);

                $this->updatePage();
            }

            send_redirect(wl($ID, array('do' => 'admin', 'page' => 'approve'), true, '&'));
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        global $ID;
        /* @var DokuWiki_Auth_Plugin $auth */
        global $auth;

        $res = $this->sqlite()->query('SELECT * FROM maintainer ORDER BY namespace');
        $assignments = $this->sqlite()->res2arr($res);

        echo $this->locale_xhtml('assignments_intro');

        echo '<form action="' . wl($ID) . '" action="post">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="approve" />';
        echo '<input type="hidden" name="sectok" value="' . getSecurityToken() . '" />';
        echo '<table class="inline">';

        // header
        echo '<tr>';
        echo '<th>'.$this->getLang('admin h_assignment_namespace').'</th>';
        echo '<th>'.$this->getLang('admin h_assignment_approver').'</th>';
        echo '<th></th>';
        echo '</tr>';

        // existing assignments
        foreach($assignments as $assignment) {
            $id = $assignment['id'];
            $namespace = $assignment['namespace'];
            $approver = $assignment['approver'] ? $assignment['approver'] : '---';

            $link = wl(
                $ID, array(
                    'do' => 'admin',
                    'page' => 'approve',
                    'action' => 'delete',
                    'sectok' => getSecurityToken(),
                    'assignment[id]' => $id
                )
            );

            echo '<tr>';
            echo '<td>' . hsc($namespace) . '</td>';
            $user = $auth->getUserData($approver);
            if ($user) {
                echo '<td>' . hsc($user['name']) . '</td>';
            } else {
                echo '<td>' . hsc($approver) . '</td>';
            }
            echo '<td><a href="' . $link . '">'.$this->getLang('admin btn_delete').'</a></td>';
            echo '</tr>';
        }

        // new assignment form
        echo '<tr>';
        echo '<td><input type="text" name="assignment[assign]" /></td>';
        echo '<td>';
        echo '<select name="assignment[approver]">';
        echo '<option value="">---</option>';
        foreach($auth->retrieveUsers() as $login => $data) {
            echo '<option value="' . hsc($login) . '">' . hsc($data['name']) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '<td><button type="submit" name="action" value="add">'.$this->getLang('admin btn_add').'</button></td>';
        echo '</tr>';

        echo '</table>';
    }
}

// vim:ts=4:sw=4:et:
