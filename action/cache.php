<?php
/**
 * DokuWiki Plugin notification (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class action_plugin_ireadit_cache extends DokuWiki_Action_Plugin
{

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_parser_cache_use');
    }

    /**
     * @param Doku_Event $event event object by reference
     * @param mixed $param [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     *
     * @return void
     */
    public function handle_parser_cache_use(Doku_Event $event, $param)
    {
        /** @var cache_renderer $cache */
        $cache = $event->data;

        if(!$cache->page) return;
        //purge only xhtml cache
        if($cache->mode != 'xhtml') return;

        //Check if it is plugins
        $ireadit_list = p_get_metadata($cache->page, 'plugin ireadit_list');
        if(!$ireadit_list) return;

        if ($ireadit_list['dynamic_user']) {
            $cache->_nocache = true;
        } else {
            /** @var helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $cache->depends['files'][] = $db_helper->getDB()->getAdapter()->getDbFile();
        }
    }
}
