<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_ireadit_ireadit extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'AFTER', $this, 'render_list');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_ireadit_action');
        $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'updatre_ireadit_metadata');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
    }

    public function render_list()
    {
        global $INFO, $ACT, $auth;

        if ($ACT != 'show') return;
        if (!p_get_metadata($INFO['id'], 'plugin ireadit')) return;

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

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

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

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
        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return;
        }

        $page = $event->data['current']['last_change']['id'];
        $last_change_date = $event->data['current']['last_change']['date'];

        //don't use ireadit here
        if (!isset($event->data['current']['plugin']['ireadit'])) {
            //remove some old data
            $sqlite->query('DELETE FROM ireadit WHERE page=? AND timestamp IS NULL', $page);
            $sqlite->query('DELETE FROM meta WHERE page=?', $page);
            return;
        }
        $ireadit = $event->data['current']['plugin']['ireadit'];

        //check if new revision exists
        $res = $sqlite->query('SELECT page FROM ireadit WHERE page = ? AND rev = ?',
            $page, $last_change_date);

        //revision already in table
        if ($sqlite->res2single($res)) return;

        /* @var \helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');

        //remove old "ireaders"
        $sqlite->query('DELETE FROM ireadit WHERE page=? AND timestamp IS NULL', $page);
        //update metadata
        $sqlite->query('REPLACE INTO meta(page,meta,last_change_date) VALUES (?,?,?)',
            $page, json_encode($ireadit), $last_change_date);

        if ($this->getConf('minor_edit_keeps_readers') &&
            $event->data['current']['last_change']['type'] == 'e') {
            $res = $sqlite->query('SELECT user, timestamp FROM ireadit
                                    WHERE rev=(SELECT MAX(rev) FROM ireadit WHERE page=?)
                                      AND page=? AND timestamp IS NOT NULL', $page, $page);
            $prevReaders = [];
            while ($row = $sqlite->res_fetch_assoc($res)) {
                $user = $row['user'];
                $timestamp = $row['timestamp'];
                $prevReaders[$user] = $timestamp;
            }
        }

        $newUsers = $helper->users_set($ireadit['users'], $ireadit['groups']);
        //insert new users
        foreach ($newUsers as $user => $info) {
            if (isset($prevReaders[$user])) {
                $sqlite->query('INSERT OR IGNORE INTO ireadit (page, rev, user, timestamp)
                            VALUES (?,?,?,?)', $page, $last_change_date, $user, $prevReaders[$user]);
            } else {
                $sqlite->query('INSERT OR IGNORE INTO ireadit (page, rev, user) VALUES (?,?,?)',
                    $page, $last_change_date, $user);
            }
        }
    }

    /**
     *
     * @param Doku_Event $event  event object by reference
     * @return void
     */
    public function handle_pagesave_after(Doku_Event $event)
    {
        //no content was changed
        if (!$event->data['contentChanged']) return;

        if ($event->data['changeType'] == DOKU_CHANGE_TYPE_DELETE) {
            try {
                /** @var \helper_plugin_ireadit_db $db_helper */
                $db_helper = plugin_load('helper', 'ireadit_db');
                $sqlite = $db_helper->getDB();
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }

            $sqlite->query('DELETE FROM ireadit WHERE page=? AND timestamp IS NULL', $event->data['id']);
            $sqlite->query('DELETE FROM meta WHERE page=?', $event->data['id']);
        }
    }
}
