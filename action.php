<?php
/**
 * Plugin Now: Inserts a timestamp.
 * 
 * @license    GPL 3 (http://www.gnu.org/licenses/gpl.html)
 * @author     Szymon Olewniczak <szymon.olewniczak@rid.pl>
 */

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_ireadit extends DokuWiki_Action_Plugin {

    function register(&$controller) {
	$controller->register_hook('TPL_CONTENT_DISPLAY', 'AFTER',  $this, 'add_link_and_list');
	$controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER',  $this, 'remove_meta');
	$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE',  $this, 'add_to_ireadit_metadata');
    }
    function add_link_and_list($event)
    {
	global $ID, $INFO;
	if(p_get_metadata($ID, 'plugin_ireadit') == true)
	{
	    $readers = p_get_metadata($ID, 'plugin_ireadit_readers');
	    if( $readers == NULL || ( 
		is_array($INFO['userinfo']) && 
		! in_array($INFO['userinfo']['name'], $readers[0]) ) )
	    {
		echo '<a href="?id='.$ID.'&do=ireadit">'.$this->getLang('ireadit').'</a>';
	    } 
	    if($readers != NULL)
	    {
		echo '<h3>'.$this->getLang('readit_header').'</h3>';
		echo '<ul>';
		for($i=0;$i<count($readers[0]);$i++)
		{
		   echo '<li>'.$readers[0][$i].' - '.date('d/m/Y H:i', $readers[1][$i]).'</li>'; 
		}
		echo '</ul>';
	    }
	}
    }
    function add_to_ireadit_metadata($event)
    {
	global $ACT, $ID, $INFO;
	if($event->data == 'ireadit')
	{
	    $readers = p_get_metadata($ID, 'plugin_ireadit_readers');
	    if($readers == NULL)
	    {
		$readers = array(array(), array());
	    }
	    if(is_array($INFO['userinfo']) && ! in_array($INFO['userinfo']['name'], $readers[0]))
	    {
		$readers[0][] = $INFO['userinfo']['name'];
		$readers[1][] = time();
	    }	
	    p_set_metadata($ID, array('plugin_ireadit_readers' => $readers));

	    $ACT = 'show';
	}
    }
    function remove_meta()
    {
	global $ID;
	p_set_metadata($ID,array('plugin_ireadit_readers' => NULL));
    }
}
