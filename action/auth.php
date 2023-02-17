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
        $controller->register_hook('AUTH_USER_CHANGE','AFTER', $this, 'handle_auth_user_change');
    }

    public function handle_auth_user_change(Doku_Event $event) {
        // update the index
        global $conf;
        $data = array();
        search($data, $conf['datadir'], 'search_allpages', array('skipacl' => true));
        foreach($data as $val) {
            // if we use ireadit on the page, invalidate index
            if (p_get_metadata($val['id'], 'plugin_ireadit=0.1')) {
                $idxtag = metaFN($val['id'],'.indexed');
                @unlink($idxtag);
            }
        }
    }
}
