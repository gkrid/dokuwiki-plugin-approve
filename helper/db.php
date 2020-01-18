<?php
/**
 * DokuWiki Plugin watchcycle (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki

if (!defined('DOKU_INC')) {
    die();
}

class helper_plugin_approve_db extends DokuWiki_Plugin
{
    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /**
     * helper_plugin_struct_db constructor.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize the database
     *
     * @throws Exception
     */
    protected function init()
    {
        /** @var helper_plugin_sqlite $sqlite */
        $this->sqlite = plugin_load('helper', 'sqlite');
        if (!$this->sqlite) {
            if (defined('DOKU_UNITTEST')) {
                throw new \Exception('Couldn\'t load sqlite.');
            }
            return;
        }

        if ($this->sqlite->getAdapter()->getName() != DOKU_EXT_PDO) {
            if (defined('DOKU_UNITTEST')) {
                throw new \Exception('Couldn\'t load PDO sqlite.');
            }
            $this->sqlite = null;
            return;
        }
        $this->sqlite->getAdapter()->setUseNativeAlter(true);

        // initialize the database connection
        if (!$this->sqlite->init('approve', DOKU_PLUGIN . 'approve/db/')) {
            if (defined('DOKU_UNITTEST')) {
                throw new \Exception('Couldn\'t init sqlite.');
            }
            $this->sqlite = null;
            return;
        }
    }

    /**
     * @param bool $throw throw an Exception when sqlite not available?
     * @return helper_plugin_sqlite|null
     * @throws Exception
     */
    public function getDB($throw=true)
    {
        global $conf;
        $len = strlen($conf['metadir']);
        if ($this->sqlite && $conf['metadir'] != substr($this->sqlite->getAdapter()->getDbFile(), 0, $len)) {
            $this->init();
        }
        if(!$this->sqlite && $throw) {
            throw new \Exception('The approve plugin requires the sqlite plugin. Please install and enable it.');
        }
        return $this->sqlite;
    }

    /**
     * Completely remove the database and reinitialize it
     *
     * You do not want to call this except for testing!
     */
    public function resetDB()
    {
        if (!$this->sqlite) {
            return;
        }
        $file = $this->sqlite->getAdapter()->getDbFile();
        if (!$file) {
            return;
        }
        unlink($file);
        clearstatcache(true, $file);
        $this->init();
    }
}

// vim:ts=4:sw=4:et:
