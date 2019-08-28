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
    public static function users_set($users=[], $groups=[]) {
        global $auth;

        $set = [];
        if (empty($users) && empty($groups)) {
            $set = $auth->retrieveUsers();
        } else {
            $all_users = $auth->retrieveUsers();
            foreach ($all_users as $user => $info) {
                if (in_array($user, $users)) {
                    $set[$user] = $info;
                } elseif (array_intersect($groups, $info['grps'])) {
                    $set[$user] = $info;
                }
            }
        }
        return $set;
    }

    /**
     * @param $user
     * @param $meta
     * @return bool
     */
    public function in_users_set($user, $meta) {
        $users = $meta['users'];
        $groups = $meta['groups'];
        $users_set = self::users_set($users, $groups);
        if (array_key_exists($user, $users_set)) {
            return true;
        }
        return false;
    }
}
