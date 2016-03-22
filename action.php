<?php
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_ireadit extends DokuWiki_Action_Plugin {
    function register(Doku_Event_Handler $controller) {
		$controller->register_hook('TPL_CONTENT_DISPLAY', 'AFTER',  $this, 'add_link_and_list');
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE',  $this, 'add_to_ireadit_metadata');
		$controller->register_hook('PARSER_METADATA_RENDER', 'AFTER',  $this, 'updatre_ireadit_metadata');
		$controller->register_hook('PARSER_CACHE_USE','BEFORE', $this, 'handle_cache_prepare');
    }
    function has_read($readers, $who) {
    	if (!is_array($readers))
    		return false;
	foreach ($readers as $reader)
		if ($reader['client'] == $who)
			return true;
		return false;
    }
    function add_link_and_list($event)
    {
		global $ID, $INFO, $ACT, $REV, $auth;

		if($ACT != 'show')
			return;
		
		if(!p_get_metadata($ID, 'plugin_ireadit_display'))
			return;
		
		
		$plugin_ireadit = p_get_metadata($ID, 'plugin_ireadit');
		if ($REV == 0)
			$date = p_get_metadata($ID, 'date modified');
		else
			$date = $REV;
		$readers = $plugin_ireadit[$date];
		
		echo '<div';
			if ($this->getConf('print') == 0)
				echo ' class="no-print"';
		echo '>';
		
		//check if the user should read the page
		$all_users = p_get_metadata($ID, 'plugin_ireadit_all_users');
		$users = p_get_metadata($ID, 'plugin_ireadit_users');
		$groups = p_get_metadata($ID, 'plugin_ireadit_groups');
		
		//if we using old version of plugin varibles may not exist
		if (
		    ($all_users === TRUE) ||
		    ($all_users === FALSE && (in_array($INFO['client'], $users) || count(array_intersect($INFO['userinfo']['grps'], $groups)) > 0))
		) if($REV == 0 && is_array($INFO['userinfo']) && !$this->has_read($readers, $INFO['client']))
			echo '<a href="?id='.$ID.'&do=ireadit">'.$this->getLang('ireadit').'</a>';
		
		if(count($readers) > 0){
			echo '<h3>'.$this->getLang('readit_header').'</h3>';
			echo '<ul>';
			foreach ($readers as $reader) {
			   $udata = $auth->getUserData($reader['client'], false);
			   echo '<li>'.$udata['name'].' - '.date('d/m/Y H:i', $reader['time']).'</li>';
			}

			echo '</ul>';
		}
		echo '</div>';
    }
    function updatre_ireadit_metadata($event) 
    {
    	if (!is_array($event->data['persistent']['plugin_ireadit']))
    		$event->data['persistent']['plugin_ireadit'] = array();
    	
    	$date = $event->data['persistent']['date']['modified'];
    	if (!isset($event->data['persistent']['plugin_ireadit'][$date]))
    		$event->data['persistent']['plugin_ireadit'][$date] = array();
    	
    	$event->data['current']['plugin_ireadit'] = $event->data['persistent']['plugin_ireadit']; 	
    }
    function add_to_ireadit_metadata($event)
    {
		global $ACT, $ID, $INFO;
		if($event->data != 'ireadit')
			return;
		$ACT = 'show';
		if(!is_array($INFO['userinfo']))
			return;
		
		//user can ireadit the page
		$all_users = p_get_metadata($ID, 'plugin_ireadit_all_users');
		$users = p_get_metadata($ID, 'plugin_ireadit_users');
		$groups = p_get_metadata($ID, 'plugin_ireadit_groups');
		if (!(
		    ($all_users === TRUE) ||
		    ($all_users === FALSE && (in_array($INFO['client'], $users) || count(array_intersect($INFO['userinfo']['grps'], $groups)) > 0))
		)) return;
			
		$plugin_ireadit = p_get_metadata($ID, 'plugin_ireadit');
	   	$date = p_get_metadata($ID, 'date modified');
	   	if (!is_array($plugin_ireadit))
	   		$plugin_ireadit = array($date => array());
		
	   	$readers = $plugin_ireadit[$date];
		$client = $INFO['client'];
	        //check if user has read the page already
	        //check if user can 
	   	if (!$this->has_read($readers, $client)) {
			$plugin_ireadit[$date][] = array('client' => $client, 'time' => time());
			p_set_metadata($ID, array('plugin_ireadit' => $plugin_ireadit));
		}
	}

    /**
     * prepare the cache object for default _useCache action
     */
    function handle_cache_prepare(&$event, $param) {
        $cache =& $event->data;
        
        // we're only interested in wiki pages
        if (!isset($cache->page)) return;
        if ($cache->mode != 'i') return;
        
        // get meta data
        $ireadit  = p_get_metadata($cache->page, 'plugin_ireadit_display');
        if ($ireadit)
        	$cache->depends['purge'] = true;
    }
}
