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

    public function user_can_read_page($ireadit_data, $id, $rev, $user, &$readers=array()) {
        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
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
     * @return array|false
     */
    public function get_list($user=NULL) {
        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        $indexer = idx_get_indexer();
        if ($user) {
            $current_user_pages = $indexer->lookupKey('ireadit', $user);
        } else {
            $current_user_pages = $indexer->getPages('ireadit');
        }

        $pages = [];
        foreach ($current_user_pages as $page) {
            $last_change_date = p_get_metadata($page, 'last_change date');
            $pages[$page] = [
                'current_rev' => $last_change_date,
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
