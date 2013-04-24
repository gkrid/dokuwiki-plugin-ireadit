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
class action_plugin_talkvisit extends DokuWiki_Action_Plugin {

    function register(&$controller) {
	    $controller->register_hook('IO_WIKIPAGE_READ', 'BEFORE',  $this, 'add_signature');
    }
    function add_signature($event)
    {
	global $INFO;
	$id = $INFO['id'];
	
	if(strpos($id, 'talk') === 0 && is_array($INFO['userinfo']))
	{
	    $userinfo = $INFO['userinfo'];
	    $name = $userinfo['name'];
	    $email = $userinfo['mail'];
	    $date = date('Y-m-d H:i');
	    $file = $INFO['filepath'];

	    //create signature
	    $sign = "  * [[$email|$name]] - $date\n";

	    //check if signature doesn't exist
	    $sign_ex = false;
	    if(file_exists($file))
	    {
		$file_lines = file($file);
		foreach($file_lines as $line)
		{
		    if($line == $sign)
			$sign_ex = true;
		}
	    }
	    if($sign_ex == false)
	    {
		$fp = fopen($file, 'a');
		fwrite($fp, $sign);
		fclose($fp);
	    }
	}
    }
    
}
