<?php
/**
 * DokuWiki Plugin bez (Action Component)
 *
 */

// must be run within Dokuwiki

if (!defined('DOKU_INC')) die();

/**
 * Class action_plugin_bez_migration
 *
 * Handle migrations that need more than just SQL
 */
class action_plugin_ireadit_migration extends DokuWiki_Action_Plugin
{
    /**
     * @inheritDoc
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_SQLITE_DATABASE_UPGRADE', 'AFTER', $this, 'handle_migrations');
    }

    /**
     * Call our custom migrations when defined
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_migrations(Doku_Event $event, $param)
    {
        if ($event->data['sqlite']->getAdapter()->getDbname() !== 'ireadit') {
            return;
        }
        $to = $event->data['to'];

        if (is_callable([$this, "migration$to"])) {
            $event->result = call_user_func([$this, "migration$to"], $event->data);
        }
    }

    /**
     * Convenience function to run an INSERT ... ON CONFLICT IGNORE operation
     *
     * The function takes a key-value array with the column names in the key and the actual value in the value,
     * build the appropriate query and executes it.
     *
     * @param string $table the table the entry should be saved to (will not be escaped)
     * @param array $entry A simple key-value pair array (only values will be escaped)
     * @return bool|SQLiteResult
     */
    protected function insertOrIgnore(helper_plugin_sqlite $sqlite, $table, $entry) {
        $keys = join(',', array_keys($entry));
        $vals = join(',', array_fill(0,count($entry),'?'));

        $sql = "INSERT OR IGNORE INTO $table ($keys) VALUES ($vals)";
        return $sqlite->query($sql, array_values($entry));
    }

    protected function migration5($data)
    {
        /** @var DokuWiki_Auth_Plugin */
        global $auth;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();

        /** @var helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');

        //remove old rows
        $sqlite->query('DELETE FROM ireadit WHERE timestamp IS NULL');

        $res = $sqlite->query('SELECT page,meta FROM meta');
        while ($row = $sqlite->res_fetch_assoc($res)) {
            $page = $row['page'];
            $meta = json_decode($row['meta'], true);
            $last_change_date = p_get_metadata($page, 'last_change date');

            $users = $auth->retrieveUsers();
            foreach ($users as $user => $info) {
                $res2 = $sqlite->query('SELECT user FROM ireadit WHERE page=? AND rev=? AND user=?', $page, $last_change_date, $user);
                $existsAlready = $sqlite->res2single($res2);
                if (!$existsAlready && $helper->in_users_set($user, $meta)) {
                    $sqlite->storeEntry('ireadit', [
                        'page' => $page,
                        'rev' => $last_change_date,
                        'user' => $user
                    ]);
                }
            }
        }
    }

    protected function migration3($data)
    {
        global $conf;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();

        $res = $sqlite->query('SELECT page,meta FROM meta');
        while ($row = $sqlite->res_fetch_assoc($res)) {
            $last_change_date = p_get_metadata($row['page'], 'last_change date');
            $sqlite->storeEntry('meta2', [
                'page' => $row['page'],
                'meta' => $row['meta'],
                'last_change_date' => $last_change_date
            ]);
        }
        $sqlite->query('DROP TABLE meta');
        $sqlite->query('ALTER TABLE meta2 RENAME TO meta');
    }

    protected function migration2($data)
    {
        global $conf;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();

        /* @var \helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');


        $datadir = $conf['datadir'];
        if (substr($datadir, -1) != '/') {
            $datadir .= '/';
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($datadir));
        $pages = [];
        foreach ($rii as $file) {
            if ($file->isDir()){
                continue;
            }

            //remove start path and extension
            $page = substr($file->getPathname(), strlen($datadir), -4);
            $pages[] = str_replace('/', ':', $page);
        }

        $db->beginTransaction();

        foreach ($pages as $page) {
            //import historic data
            $meta = p_get_metadata($page, 'plugin ireadit');
            if (!$meta) continue;

            $sqlite->storeEntry('meta', [
                'page' => $page,
                'meta' => json_encode($meta)
            ]);
        }
        $db->commit();


    }

    protected function migration1($data)
    {
        global $conf;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();

        /* @var \helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');


        $datadir = $conf['datadir'];
        if (substr($datadir, -1) != '/') {
            $datadir .= '/';
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($datadir));
        $pages = [];
        foreach ($rii as $file) {
            if ($file->isDir()){
                continue;
            }

            //remove start path and extension
            $page = substr($file->getPathname(), strlen($datadir), -4);
            $pages[] = str_replace('/', ':', $page);
        }
        $db->beginTransaction();

        foreach ($pages as $page) {
            //import historic data
            $meta = p_get_metadata($page, 'plugin_ireadit');
            if (!$meta) continue;

            foreach ($meta as $rev => $data) {
                if ($rev === '' || count($data) == 0) continue;
                foreach ($data as $user_read) {
                    $sqlite->storeEntry('ireadit', [
                        'page' => $page,
                        'rev' => $rev,
                        'user' => $user_read['client'],
                        'timestamp' => date('c', $user_read['time'])
                    ]);
                }
            }

            //import current data
            $content = file_get_contents($datadir . str_replace(':', '/', $page) . '.txt');
            $status = preg_match('/~~IREADIT.*~~/', $content, $matches);
            //no ireadit on page
            if ($status !== 1) continue;

            $match = trim(substr($matches[0], strlen('~~IREADIT'), -2));
            $splits = preg_split('/\s+/', $match, -1, PREG_SPLIT_NO_EMPTY);

            $users = [];
            $groups = [];
            foreach ($splits as $split) {
                if ($split[0] == '@') {
                    $group = substr($split, 1);
                    $groups[] = $group;
                } else {
                    $users[] = $split;
                }
            }

            $usersToInsert = $helper->users_set($users, $groups);

            if ($usersToInsert) {
                $last_change_date = p_get_metadata($page, 'last_change date');
                foreach ($usersToInsert as $user => $info) {
                    $this->insertOrIgnore($sqlite,'ireadit', [
                        'page' => $page,
                        'rev' => $last_change_date,
                        'user' => $user
                    ]);
                }
            }

        }
        $db->commit();

        return true;
    }
}
