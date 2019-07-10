<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once DOKU_PLUGIN . 'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_ireadit_display extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'AFTER', $this, 'render_list');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_ireadit_action');
        $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'updatre_ireadit_metadata');
        $controller->register_hook('PLUGIN_NOTIFICATION_REGISTER_SOURCE', 'AFTER', $this, 'add_notifications_source');
        $controller->register_hook('PLUGIN_NOTIFICATION_GATHER', 'AFTER', $this, 'add_notifications');
        $controller->register_hook('PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES', 'AFTER', $this, 'add_notification_cache_dependencies');


    }

    public function render_list()
    {
        global $INFO, $ACT, $auth;

        if ($ACT != 'show') return;
        if (!p_get_metadata($INFO['id'], 'plugin ireadit')) return;
        

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'ireadit_db');
        $sqlite = $db_helper->getDB();

        echo '<div';
        if ($this->getConf('print') == 0) {
            echo' class="no-print"';
        }
        echo '>';

        $last_change_date = p_get_metadata($INFO['id'], 'last_change date');

        if ($INFO['rev'] == 0) {
            $res = $sqlite->query('SELECT page FROM ireadit WHERE page = ?
                                                AND rev = ?
                                                AND timestamp IS NULL
                                                AND user = ?', $INFO['id'],
                                                    $last_change_date, $INFO['client']);
            if ($sqlite->res2single($res)) {
                echo '<a href="' . wl($INFO['id'], ['do' => 'ireadit']) . '">' . $this->getLang('ireadit') . '</a>';
            }
        }

        $rev = !$INFO['rev'] ? $last_change_date : $INFO['rev'];
        $res = $sqlite->query('SELECT user, timestamp FROM ireadit
                                        WHERE page = ?
                                        AND timestamp IS NOT NULL
                                        AND rev = ?
                                        ORDER BY timestamp', $INFO['id'], $rev);

        $readers = $sqlite->res2arr($res);
        if (count($readers) > 0) {
            echo '<h3>' . $this->getLang('readit_header') . '</h3>';
            echo '<ul>';
            foreach ($readers as $reader) {
                $udata = $auth->getUserData($reader['user'], false);
                $name = $udata ? $udata['name'] : $reader['user'];
                $time = strtotime($reader['timestamp']);
                echo '<li>' . $name . ' - ' . date('d/m/Y H:i', $time) . '</li>';
            }
            echo '</ul>';
        }
        

        echo '</div>';
    }

    public function handle_ireadit_action(Doku_Event $event)
    {
        global $INFO, $ACT;
        if ($event->data != 'ireadit') return;
        $ACT = 'show';
        if (!$INFO['client']) return;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'ireadit_db');
        $sqlite = $db_helper->getDB();

        $last_change_date = p_get_metadata($INFO['id'], 'last_change date');

        //check if user can "ireadit" the page and didn't "ireadit" already
        $res = $sqlite->query('SELECT page FROM ireadit
                                            WHERE page = ?
                                            AND rev = ?
                                            AND timestamp IS NULL
                                            AND user = ?',
                                        $INFO['id'], $last_change_date, $INFO['client']);
        if (!$sqlite->res2single($res)) return;

        $sqlite->query('UPDATE ireadit SET timestamp=? WHERE page=? AND rev=? AND user=?',
            date('c'), $INFO['id'], $last_change_date, $INFO['client']);
    }

    public function updatre_ireadit_metadata(Doku_Event $event)
    {
        //don't use ireadit here
        if (!isset($event->data['current']['plugin']['ireadit'])) return;
        $ireadit = $event->data['current']['plugin']['ireadit'];

        $db_helper = plugin_load('helper', 'ireadit_db');
        $sqlite = $db_helper->getDB();

        $page = $event->data['current']['last_change']['id'];
        $last_change_date = $event->data['current']['last_change']['date'];

        //check if new revision exists
        $res = $sqlite->query('SELECT page FROM ireadit WHERE page = ? AND rev = ?',
            $page, $last_change_date);

        //revision already in table
        if ($sqlite->res2single($res)) return;

        /* @var \helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');

        //remove old "ireaders"
        $sqlite->query('DELETE FROM ireadit WHERE page=? AND timestamp IS NULL', $page);

        $newUsers = $helper->users_set($ireadit['users'], $ireadit['groups']);
        //insert new users
        foreach ($newUsers as $user => $info) {
            $timestamp = date('c');
            $sqlite->query('INSERT OR IGNORE INTO ireadit (page, rev, user) VALUES (?,?,?)',
                $page, $last_change_date, $user);
        }
    }

    public function add_notifications_source(Doku_Event $event)
    {
        $event->data[] = 'ireadit';
    }

    public function add_notification_cache_dependencies(Doku_Event $event)
    {
        if (!in_array('ireadit', $event->data['plugins'])) return;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'ireadit_db');
        $event->data['dependencies'][] = $db_helper->getDB()->getAdapter()->getDbFile();
    }

    public function add_notifications(Doku_Event $event)
    {
        if (!in_array('ireadit', $event->data['plugins'])) return;

        /** @var \helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'ireadit_db');
        $sqlite = $db_helper->getDB();

        $user = $event->data['user'];

        $res = $sqlite->query('SELECT page, rev FROM ireadit
                                        WHERE timestamp IS NULL
                                        AND user = ?
                                        ORDER BY timestamp', $user);

        $notifications = $sqlite->res2arr($res);

        foreach ($notifications as $notification) {
            $page = $notification['page'];
            $rev = $notification['rev'];

            $link = '<a class="wikilink1" href="' . wl($page) . '">';
            if (useHeading('content')) {
                $heading = p_get_first_heading($page);
                if (!blank($heading)) {
                    $link .= $heading;
                } else {
                    $link .= noNSorNS($page);
                }
            } else {
                $link .= noNSorNS($page);
            }
            $link .= '</a>';
            $full = sprintf($this->getLang('notification full'), $link);
            $event->data['notifications'][] = [
                'plugin' => 'ireadit',
                'full' => $full,
                'brief' => $link,
                'timestamp' => (int)$rev
            ];
        }
    }
}
