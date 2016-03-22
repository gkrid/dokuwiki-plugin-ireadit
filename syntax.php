<?php
// must be run within DokuWiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once DOKU_PLUGIN.'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_ireadit extends DokuWiki_Syntax_Plugin {

    function getPType(){
       return 'block';
    }

    function getType() { return 'substition'; }
    function getSort() { return 99; }

    private $users = array(), $groups = array(), $all_users = false;
    function connectTo($mode) {
	$this->Lexer->addEntryPattern('~~IREADIT', 'base', 'plugin_ireadit');
	 
	// Match user or group
	$this->Lexer->addPattern('[^ ~]+', 'plugin_ireadit');
	 
	$this->Lexer->addExitPattern('~~', 'plugin_ireadit');
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {
        switch ( $state ) {
            case DOKU_LEXER_MATCHED:
                if ($match[0] == '@') {
                	$group = substr($match, 1);
                	$this->groups[] = $group;
		} else 
			$this->users[] = $match;
            break;
            case DOKU_LEXER_EXIT:
            	if (count($this->users) == 0 && count($this->groups) == 0)
            		$this->all_users = true;
            break;
        }
        return TRUE;
    }

    function render($mode, Doku_Renderer $renderer, $data) {
	if($mode == 'xhtml')
	    return true;
	elseif($mode == 'metadata'){
	    $renderer->meta['plugin_ireadit_display'] = true;
	    $renderer->meta['plugin_ireadit_users'] = $this->users;
	    $renderer->meta['plugin_ireadit_groups'] = $this->groups;
	    $renderer->meta['plugin_ireadit_all_users'] = $this->all_users;
	    return true;
        }
        return false;
    }
}
