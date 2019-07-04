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

        if (is_callable(array($this, "migration$to"))) {
            $event->result = call_user_func(array($this, "migration$to"), $event->data);
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

    protected function migration1($data)
    {
        global $conf;
        /** @var $auth DokuWiki_Auth_Plugin */
        global $auth;

        /** @var helper_plugin_sqlite $sqlite */
        $sqlite = $data['sqlite'];
        $db = $sqlite->getAdapter()->getDb();


        $datadir = $conf['datadir'];
        if (substr($datadir, -1) != '/') {
            $datadir .= '/';
        }

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($datadir));
        $pages = array();
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
            $date = p_get_metadata($page, 'date modified');

            if (isset($meta[$date])) {
                $meta[0] = $meta[$date];
                unset($meta[$date]);
            }

            $current = null;
            foreach ($meta as $rev => $data) {
                if ($rev === '' || count($data) == 0) continue;
                $sqlite->storeEntry('ireadit', array(
                    'page' => $page,
                    'rev' => $rev
                ));
                $id = $db->lastInsertId();
                if ($rev === 0) {
                    $current = $id;
                }
                foreach ($data as $user_read) {
                    $sqlite->storeEntry('ireadit_user', array(
                        'ireadit_id' => $id,
                        'user' => $user_read['client'],
                        'timestamp' => date('c', $user_read['time'])
                    ));
                }
            }

            //import current data
            $content = file_get_contents($datadir . str_replace(':', '/', $page) . '.txt');
            $status = preg_match('/~~IREADIT.*~~/', $content, $matches);
            //no ireadit on page
            if ($status !== 1) continue;

            $match = trim(substr($matches[0], strlen('~~IREADIT'), -2));
            $splits = preg_split('/\s+/', $match, -1, PREG_SPLIT_NO_EMPTY);

            $users = array();
            $groups = array();
            foreach ($splits as $split) {
                if ($split[0] == '@') {
                    $group = substr($match, 1);
                    $groups[] = $group;
                } else {
                    $users[] = $split;
                }
            }

            $usersToInsert = array();
            if (empty($users) && empty($groups)) {
                $usersToInsert = $auth->retrieveUsers();
            } else {
                $all_users = $auth->retrieveUsers();
                foreach ($all_users as $user => $info) {
                    if (in_array($user, $users)) {
                        $usersToInsert[$user] = $info;
                    } elseif (array_intersect($groups, $info['grps'])) {
                        $usersToInsert[$user] = $info;
                    }
                }
            }

            if ($usersToInsert) {
                if ($current === NULL) {
                    $sqlite->storeEntry('ireadit', array(
                        'page' => $page,
                        'rev' => 0
                    ));
                    $current = $db->lastInsertId();
                }
                foreach ($usersToInsert as $user => $info) {
                    $this->insertOrIgnore($sqlite,'ireadit_user', array(
                        'ireadit_id' => $current,
                        'user' => $user
                    ));
                }
            }

        }
        $db->commit();

        return true;
    }
}
