<?php

// must be run within Dokuwiki

if(!defined('DOKU_INC')) die();

class action_plugin_ireadit_auth extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook('AUTH_USER_CHANGE', 'AFTER', $this, 'handle_auth_user_change');
    }

    public function handle_auth_user_change(Doku_Event $event) {
        $type = $event->data['type'];

        /** @var helper_plugin_ireadit_db $db_helper */
        $db_helper = plugin_load('helper', 'ireadit_db');
        $sqlite = $db_helper->getDB();

        /** @var helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');

        switch ($type) {
            case 'create':
                $user = $event->data['params'][0];
                $res = $sqlite->query('SELECT page,meta FROM meta');
                $rows = $sqlite->res2arr($res);
                foreach ($rows as $row) {
                    $page = $row['page'];
                    $meta = json_decode($row['meta'], true);
                    if ($helper->in_users_set($user, $meta)) {
                        $last_change_date = p_get_metadata($page, 'last_change date');
                        $sqlite->storeEntry('ireadit', [
                            'page' => $page,
                            'rev' => $last_change_date,
                            'user' => $user
                        ]);
                    }
                }
                break;
            case 'modify':
                $old_username =  $event->data['params'][0];
                if (!isset($event->data['params'][1]['user'])) return;

                $new_username =  $event->data['params'][1]['user'];
                if ($old_username == $new_username) return;

                $sqlite->query('UPDATE ireadit SET user=? WHERE user=?', $new_username, $old_username);
                break;
            case 'delete':
                $user = $event->data['params'][0][0];

                $sqlite->query('DELETE FROM ireadit WHERE user=? AND timestamp IS NULL', $user);
                break;
        }

    }

}
