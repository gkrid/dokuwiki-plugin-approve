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
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) continue;

            $path = $fileinfo->getPath();
            $ns = str_replace('/', ':', substr($path, strlen($datadir)));

            if (!isset($pages[$ns])) {
                $pages[$ns] = [];
            }

            //remove .txt
            $pages[$ns][] = substr($fileinfo->getFilename(), 0, -4);
        }


        return $pages;
    }

    protected function updatePage()
    {
        $res = $this->sqlite()->query('SELECT * FROM maintainer');
        $assignments = $this->sqlite()->res2arr($res);

        $weighted_assigments = [];
        foreach ($assignments as $assignment) {
            $ns = $assignment['namespace'];
            //more general namespaces are overridden by more specific ones.
            if (substr($ns, -1) == '*') {
                $weight = substr_count($ns, ':');
            } else {
                $weight = PHP_INT_MAX;
            }

            $assignment['weight'] = $weight;
            $weighted_assigments[] = $assignment;
        }
        array_multisort(array_column($weighted_assigments, 'weight'), $weighted_assigments);

        $pages = [];
        $wikiPages = $this->getPages();
        foreach ($weighted_assigments as $assignment) {
            $ns = $assignment['namespace'];
            $maintainer = $assignment['maintainer'];
            if (substr($ns, -2) == '**') {
                //remove '**'
                $ns = substr($ns, 0, -2);
                $ns = trim($ns, ':');
                foreach ($wikiPages as $page) {
//                    if (substr($page, 0, strlen($ns)) === $ns) {
//
//                    }
                }

            } elseif (substr($ns, -2) == '*') {
                //remove '*'
                $ns = substr($ns, 0, -2);
                $ns = trim($ns, ':');
            } else {
                $ns = trim($ns, ':');
                $pages[$ns] = $maintainer;
            }
        }

        return true;
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
                $ok = $this->sqlite()->query('DELETE FROM maintainer WHERE id=?', $assignment['id']);
                if (!$ok) msg('failed to remove pattern', -1);

                $this->updatePage();
            } else if ($INPUT->str('action') === 'add' && !blank($assignment['assign'])) {
                if (blank($assignment['maintainer'])) {
                    $q = 'INSERT INTO maintainer(namespace) VALUES (?)';
                    $ok = $this->sqlite()->query($q, $assignment['assign']);
                } else {
                    $q = 'INSERT INTO maintainer(namespace,maintainer) VALUES (?,?)';
                    $ok = $this->sqlite()->query($q, $assignment['assign'], $assignment['maintainer']);
                }

                if (!$ok) msg('failed to add pattern', -1);

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
        global $lang;

        global $ID;
        /* @var DokuWiki_Auth_Plugin */
        global $auth;

        $res = $this->sqlite()->query('SELECT * FROM maintainer');
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
        echo '<th>'.$this->getLang('admin h_assignment_maintainer').'</th>';
        echo '<th></th>';
        echo '</tr>';

        // existing assignments
        foreach($assignments as $assignment) {
            $id = $assignment['id'];
            $namespace = $assignment['namespace'];
            $maintainer = $assignment['maintainer'] ? $assignment['maintainer'] : '---';

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
            echo '<td>' . hsc($maintainer) . '</td>';
            echo '<td><a href="' . $link . '">'.$this->getLang('admin btn_delete').'</a></td>';
            echo '</tr>';
        }

        // new assignment form
        echo '<tr>';
        echo '<td><input type="text" name="assignment[assign]" /></td>';
        echo '<td>';
        echo '<select name="assignment[maintainer]">';
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
