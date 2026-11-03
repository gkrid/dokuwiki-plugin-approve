<?php

namespace dokuwiki\Extension {
    class Plugin {}
    class RemotePlugin {}
}
namespace dokuwiki\Remote {
    class RemoteException extends \Exception {}
}
namespace {
    const AUTH_READ = 1;
    $permission = AUTH_READ;
    $exists = true;
    function cleanID($page) { return trim($page, ':'); }
    function auth_quickaclcheck($page) { global $permission; return $permission; }
    function page_exists($page) { global $exists; return $exists; }
    function plugin_load($type, $name) { global $helper; return $helper; }

    require __DIR__ . '/../helper/db.php';
    require __DIR__ . '/../remote.php';

    class TestDb {
        public $pdo;
        public function __construct() {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->exec(file_get_contents(__DIR__ . '/../db/update0003.sql'));
        }
        public function queryRecord($sql, ...$args) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
    }
    class TestHelper extends \helper_plugin_approve_db {
        public function __construct($db) { $this->db = $db; }
    }
    function check($condition, $message) {
        if (!$condition) throw new \RuntimeException($message);
    }

    $db = new TestDb();
    $helper = new TestHelper($db);
    $api = new \remote_plugin_approve();
    $db->pdo->exec("INSERT INTO page (page) VALUES ('test:page')");
    check($api->getLatestApprovedRevision(':test:page') === null, 'No approval');
    $db->pdo->exec("INSERT INTO revision (page, rev, approved, approved_by, version) VALUES
        ('test:page', 100, '2026-01-01T00:00:00+00:00', 'alice', 1),
        ('test:page', 200, '2026-02-01T00:00:00+00:00', 'bob', 2),
        ('test:page', 300, NULL, NULL, NULL)");
    check($api->getLatestApprovedRevision('test:page') === [
        'rev' => 200, 'approved' => 1769904000, 'approved_by' => 'bob', 'version' => 2,
    ], 'Latest approved revision and metadata');
    $permission = 0;
    try {
        $api->getLatestApprovedRevision('test:page');
        throw new \RuntimeException('Denied page was readable');
    } catch (\dokuwiki\Remote\RemoteException $e) {
        check($e->getCode() === 111, 'Permission error');
    }
    $permission = AUTH_READ;
    $exists = false;
    try {
        $api->getLatestApprovedRevision('test:page');
        throw new \RuntimeException('Deleted page was readable');
    } catch (\dokuwiki\Remote\RemoteException $e) {
        check($e->getCode() === 121, 'Missing page error');
    }
    $exists = true;
    $db->pdo->exec("UPDATE page SET hidden=1 WHERE page='test:page'");
    check($api->getLatestApprovedRevision('test:page') === null, 'Hidden namespace');
    echo "Latest approved revision checks passed\n";
}
