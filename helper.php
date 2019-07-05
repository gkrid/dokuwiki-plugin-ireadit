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
    public function users_set($users=array(), $groups=array()) {
        global $auth;

        $set = array();
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
}