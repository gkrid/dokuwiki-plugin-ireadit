<?php
/**
 * DokuWiki Plugin struct (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class helper_plugin_ireadit extends DokuWiki_Plugin
{
    /**
     * @param array $users
     * @param array $groups
     * @return array
     */
    public function users_set($ireadit_data) {
        global $auth;

        $users = $ireadit_data['users'];
        $groups = $ireadit_data['groups'];
        $set = [];
        if (empty($users) && empty($groups)) {
            $set = $auth->retrieveUsers();
        } else {
            $all_users = $auth->retrieveUsers();
            foreach ($all_users as $user => $info) {
                if (in_array($user, $users)) {
                    $set[$user] = true;
                } elseif (array_intersect($groups, $info['grps'])) {
                    $set[$user] = true;
                }
            }
        }
        return array_keys($set);
    }

    public function find_last_approved($id) {
        /** @var helper_plugin_approve_db $approve_db */
        $approve_db = plugin_load('helper', 'approve_db');
        if ($approve_db == null) {
            msg('You must install approve plugin to use ireadit-approve integration.', -1);
            return null;
        }

        return $approve_db->getLastDbRev($id, 'approved');
    }

    public function use_approve_here($id) {
        /** @var helper_plugin_approve_acl $approve_acl */
        $approve_acl = plugin_load('helper', 'approve_acl');
        if ($approve_acl == null) {
            msg('You must install approve plugin to use ireadit-approve integration.', -1);
            return null;
        }

        return $approve_acl->useApproveHere($id);
    }

    public function get_approved_revs($id) {
        /** @var helper_plugin_approve_db $approve_db */
        $approve_db = plugin_load('helper', 'approve_db');
        if ($approve_db == null) {
            msg('You must install approve plugin to use ireadit-approve integration.', -1);
            return null;
        }
        $revs = $approve_db->getPageRevisions($id);
        $approved_revs = array_filter($revs, function ($rev) {
            return $rev['status'] == 'approved';
        });
        return array_map(function ($row) {
            return (int) $row['rev'];
        }, $approved_revs);

        try {
            /** @var \helper_plugin_approve_db $db_helper */
            $approve_db_helper = plugin_load('helper', 'approve_db');
            if ($approve_db_helper == null) {
                msg('You must install approve plugin to use ireadit-approve integration.', -1);
                return [];
            }
            $approve_sqlite = $approve_db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return [];
        }

        $res = $approve_sqlite->query('SELECT rev FROM revision WHERE page=? AND approved IS NOT NULL', $id);
        return array_map(function ($row) {
            return (int) $row['rev'];
        }, $approve_sqlite->res2arr($res));
    }

    public function use_ireadit_here($id, $rev) {
        if ($this->getConf('approve_integration') && $this->use_approve_here($id)) { // check if this is newest approve page
            $last_approved_rev = $this->find_last_approved($id);
            if ($rev == $last_approved_rev) { // this is last approved version
                return true;
            }
        } elseif ($rev == p_get_metadata($id, 'last_change date')) { // check if it is last page revision
            return true;
        }
        return false;
    }

    public function user_can_read_page($ireadit_data, $id, $rev, $user) {
        if (!$this->use_ireadit_here($id, $rev)) return false;

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        $res = $sqlite->query('SELECT user, timestamp FROM ireadit
                                        WHERE page = ?
                                        AND rev = ?
                                        ORDER BY timestamp', $id, $rev);
        $readers = $sqlite->res2arr($res);
        $users_set = $this->users_set($ireadit_data);
        return in_array($user, $users_set) && !in_array($user, array_column($readers, 'user'));
    }

    /**
     * @param $user NULL means overview mode
     * @return array
     */
    public function get_list($user=NULL) {
        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return [];
        }

        $indexer = idx_get_indexer();
        if ($user) {
            $current_user_pages = $indexer->lookupKey('ireadit', $user);
        } else {
            $current_user_pages = $indexer->getPages('ireadit');
        }

        $pages = [];
        foreach ($current_user_pages as $page) {
            $current_rev = p_get_metadata($page, 'last_change date');

            $pages[$page] = [
                'current_rev' => $current_rev,
                'last_read_rev' => NULL,
                'timestamp' => NULL
            ];
        }
        if ($user) {
            $res = $sqlite->query('SELECT page, MAX(rev) as "rev", timestamp FROM ireadit WHERE user=? GROUP BY page',
                $user);
        } else {
            $res = $sqlite->query('SELECT page, MAX(rev) as "rev", timestamp FROM ireadit GROUP BY page');
        }
        while ($row = $sqlite->res_fetch_assoc($res)) {
            $page = $row['page'];
            $rev = (int) $row['rev'];
            $timestamp = $row['timestamp'];
            if (isset($pages[$page])) {
                $pages[$page]['last_read_rev'] = $rev;
                $pages[$page]['timestamp'] = $timestamp;
            }
        }

        if ($this->getConf('approve_integration')) {
            foreach ($current_user_pages as $page) {
                if (!$this->use_approve_here($page)) continue; // ignore the pages where approve doesn't apply
                $approved_revs = $this->get_approved_revs($page);
                if (count($approved_revs) == 0) { // page was never approved - don't list it
                    unset($pages[$page]);
                    continue;
                }

                $current_rev = max($approved_revs);
                if ($user) {
                    $res = $sqlite->query('SELECT rev, timestamp FROM ireadit WHERE user=? AND page=? ORDER BY rev DESC',
                        $user, $page);
                } else {
                    $res = $sqlite->query('SELECT rev, timestamp FROM ireadit WHERE page=? ORDER BY rev DESC', $page);
                }
                $user_reads = $sqlite->res2arr($res);
                $last_read_rev = NULL;
                $last_read_timestamp = NULL;
                foreach ($user_reads as $row) {
                    $rev = (int) $row['rev'];
                    if (in_array($rev, $approved_revs)) {
                        $last_read_rev = $rev;
                        $last_read_timestamp = $row['timestamp'];
                        break;
                    }
                }

                $pages[$page] = [
                    'current_rev' => $current_rev,
                    'last_read_rev' => $last_read_rev,
                    'timestamp' => $last_read_timestamp
                ]; // override default values
            }
        }

        // apply states to pages
        foreach ($pages as &$page) {
            if ($page['current_rev'] == $page['last_read_rev']) {
                $page['state'] = 'read';
            } elseif ($page['last_read_rev'] == NULL) {
                $page['state'] = 'unread';
            } else {
                $page['state'] = 'outdated';
            }
        }
        return $pages;
    }
}
