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

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
        /* @var Input */
        global $INPUT;
        global $lang;

        $action = $INPUT->str('action');
        $updated = [];
        if ($action == 'save_config') {
            $res = $this->sqlite()->query('SELECT key, value FROM config');
            $config_options = $this->sqlite()->res2arr($res);
            foreach ($config_options as $option) {
                $key = $option['key'];
                $value = $option['value'];
                $new_value = $INPUT->str($key);

                if ($value != $new_value) {
                    $updated[$key] = $new_value;
                    $this->sqlite()->query('UPDATE config SET value=? WHERE key=?', $new_value, $key);
                }
            }

            if (array_key_exists('no_apr_namespaces', $updated)) {
                $res = $this->sqlite()->query('SELECT page, hidden FROM page');
                $pages = $this->sqlite()->res2arr($res);
                foreach ($pages as $page) {
                    $id = $page['page'];
                    $hidden = $page['hidden'];
                    $in_hidden_namespace = $this->helper()->in_hidden_namespace($id, $updated['no_apr_namespaces']);
                    $new_hidden = $in_hidden_namespace ? '1' : '0';

                    if ($hidden != $new_hidden) {
                        $this->sqlite()->query('UPDATE page SET hidden=? WHERE page=?', $new_hidden, $id);
                    }
                }
            }
            msg($this->getLang('admin updated'), 1);
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        global $lang;

        global $ID;
        /* @var Input */
        global $INPUT;

        ptln('<h1>' . $this->getLang('menu') . '</h1>');

        ptln('<div id="plugin__approve_admin">');

        $res = $this->sqlite()->query('SELECT key, value FROM config');
        $config_options = $this->sqlite()->res2arr($res);

        $form = new \dokuwiki\Form\Form();
        $form->setHiddenField('action', 'save_config');
        $form->addFieldsetOpen($this->getLang('admin settings'));
        $form->addHTML('<table>');
        foreach ($config_options as $option) {
            $key = $option['key'];
            $value = $option['value'];

            $id = "plugin__approve_config_$key";

            $input = new \dokuwiki\Form\InputElement('text', $key);
            $input->id($id);

            $form->addHTML('<tr>');

            $form->addHTML('<td>');
            $label = $this->getLang("admin config $key");
            $form->addHTML("<label for=\"$id\">$label</label>");
            $form->addHTML('</td>');


            $form->addHTML('<td>');

            $input->val($value);
            $form->addElement($input);
            $form->addHTML('</td>');

            $form->addHTML('</tr>');
        }
        $form->addHTML('</table>');
        $form->addButton('', $lang['btn_save']);

        $form->addFieldsetClose();


        ptln($form->toHTML());

        return;

        $form = new \dokuwiki\Form\Form();
        $filter_input = new \dokuwiki\Form\InputElement('text', 'filter');
        $filter_input->attr('placeholder', $this->getLang('search page'));
        $form->addElement($filter_input);

        $form->addButton('', $this->getLang('btn filter'));

        $form->addHTML('<label class="outdated">');
        $form->addCheckbox('outdated');
        $form->addHTML($this->getLang('show outdated only'));
        $form->addHTML('</label>');


        ptln($form->toHTML());
        ptln('<table>');
        ptln('<tr>');
        $headers = ['page', 'maintainer', 'cycle', 'current', 'uptodate'];
        foreach ($headers as $header) {
            $lang = $this->getLang("h $header");
            $param = [
                'do' => 'admin',
                'page' => 'watchcycle',
                'sortby' => $header,
            ];
            $icon = '';
            if ($INPUT->str('sortby') == $header) {
                if ($INPUT->int('desc') == 0) {
                    $param['desc'] = 1;
                    $icon = '↑';
                } else {
                    $param['desc'] = 0;
                    $icon = '↓';
                }
            }
            $href = wl($ID, $param);

            ptln('<th><a href="' . $href . '">' . $icon . ' ' . $lang . '</a></th>');
        }
        $q = 'SELECT page, maintainer, cycle, DAYS_AGO(last_maintainer_rev) AS current, uptodate FROM watchcycle';
        $where = [];
        $q_args = [];
        if ($INPUT->str('filter') != '') {
            $where[] = 'page LIKE ?';
            $q_args[] = '%' . $INPUT->str('filter') . '%';
        }
        if ($INPUT->has('outdated')) {
            $where[] = 'uptodate=0';
        }

        if (count($where) > 0) {
            $q .= ' WHERE ';
            $q .= implode(' AND ', $where);
        }

        if ($INPUT->has('sortby') && in_array($INPUT->str('sortby'), $headers)) {
            $q .= ' ORDER BY ' . $INPUT->str('sortby');
            if ($INPUT->int('desc') == 1) {
                $q .= ' DESC';
            }
        }

        $res = $sqlite->query($q, $q_args);
        while ($row = $sqlite->res2row($res)) {
            ptln('<tr>');
            ptln('<td><a href="' . wl($row['page']) . '" class="wikilink1">' . $row['page'] . '</a></td>');
            ptln('<td>' . $row['maintainer'] . '</td>');
            ptln('<td>' . $row['cycle'] . '</td>');
            ptln('<td>' . $row['current'] . '</td>');
            $icon = $row['uptodate'] == 1 ? '✓' : '✕';
            ptln('<td>' . $icon . '</td>');
            ptln('</tr>');
        }

        ptln('</tr>');
        ptln('</table>');

        ptln('</div>');
    }
}

// vim:ts=4:sw=4:et:
