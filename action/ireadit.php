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
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handle_pagesave_after');
        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'set_ireadit_index_version');
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'add_readers_to_index');
    }

    public function render_list()
    {
        global $INFO, $ACT, $auth;

        if ($ACT != 'show') return;
        if (!isset($INFO['meta']['plugin_ireadit=0.2'])) return;
        $ireadit_data = $INFO['meta']['plugin_ireadit=0.2'];

        echo '<div';
        if ($this->getConf('print') == 0) {
            echo' class="no-print"';
        }
        echo '>';

        /** @var helper_plugin_ireadit $helper */
        $helper = $this->loadHelper('ireadit');

        // we use 'lastmod' insetead of 'rev' to get the timestamp also for the current revision
        if ($helper->user_can_read_page($ireadit_data, $INFO['id'], $INFO['lastmod'], $INFO['client'], $readers)) {
            echo '<a href="' . wl($INFO['id'], ['do' => 'ireadit']) . '">' . $this->getLang('ireadit') . '</a>';
        }

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
        global $INFO;
        if ($event->data != 'ireadit') return;
        if (!$INFO['client']) return;
        if (!isset($INFO['meta']['plugin_ireadit=0.2'])) return;
        $ireadit_data = $INFO['meta']['plugin_ireadit=0.2'];

        /** @var helper_plugin_ireadit $helper */
        $helper = $this->loadHelper('ireadit');
        if ($helper->user_can_read_page($ireadit_data, $INFO['id'], $INFO['lastmod'], $INFO['client'])) {
            try {
                /** @var \helper_plugin_ireadit_db $db_helper */
                $db_helper = plugin_load('helper', 'ireadit_db');
                $sqlite = $db_helper->getDB();
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }
            $sqlite->storeEntry('ireadit', [
                'page' => $INFO['id'],
                'rev' => $INFO['lastmod'], // we use 'lastmod' inseted of 'rev' to get the timestamp also for the current revision
                'user' => $INFO['client'],
                'timestamp' => date('c')
            ]);
        }
        $event->data = 'redirect';
    }

    public function handle_pagesave_after(Doku_Event $event)
    {
        global $INFO;
        if (!isset($INFO['meta']['plugin_ireadit=0.2'])) return;

        if ($this->getConf('minor_edit_keeps_readers') &&
            $event->data['changeType'] == DOKU_CHANGE_TYPE_MINOR_EDIT) {
            try {
                /** @var \helper_plugin_ireadit_db $db_helper */
                $db_helper = plugin_load('helper', 'ireadit_db');
                $sqlite = $db_helper->getDB();
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }
            $sqlite->query('INSERT INTO ireadit (page,rev,user,timestamp)
                            SELECT page, ?, user, timestamp FROM ireadit WHERE rev=? AND page=?',
                $event->data['newRevision'], $event->data['oldRevision'], $event->data['id']);
        }
    }

    /**
     * Add a version string to the index so it is rebuilt
     * whenever the stored data format changes.
     */
    public function set_ireadit_index_version(Doku_Event $event) {
        $event->data['plugin_ireadit'] = '0.2';
    }

    /**
     * Add all data of the readers metadata to the metadata index.
     */
    public function add_readers_to_index(Doku_Event $event, $param) {
        $ireadit_data = p_get_metadata($event->data['page'], 'plugin_ireadit=0.2');
        if (!$ireadit_data) return;

        /** @var helper_plugin_ireadit $helper */
        $helper = $this->loadHelper('ireadit');
        $event->data['metadata']['ireadit'] = $helper->users_set($ireadit_data);
    }
}
