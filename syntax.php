<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once DOKU_PLUGIN . 'syntax.php';

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_ireadit extends DokuWiki_Syntax_Plugin
{
    function getPType()
    {
        return 'block';
    }

    function getType()
    {
        return 'substition';
    }

    function getSort()
    {
        return 99;
    }

    private $users = array(), $groups = array(), $all_users = false;

    function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~IREADIT.*~~', $mode, 'plugin_ireadit');
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = trim(substr($match, strlen('~~IREADIT'), strlen($match) - 2));
        $splits = preg_split('/\s+/', $match);

        $users = array();
        $groups = array();
        foreach ($splits as $split) {
            if ($split[0] == '@') {
                $group = substr($match, 1);
                $groups[] = $group;
            } else {
                $users[] = $split;
            }
        }

        return array('users' => $users, 'groups' => $groups);
    }

    function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode == 'xhtml')
            return true;
        elseif ($mode == 'metadata') {
            $renderer->meta['plugin_ireadit_display'] = true;
            $renderer->meta['plugin_ireadit_users'] = $data['users'];
            $renderer->meta['plugin_ireadit_groups'] = $data['groups'];
            $all_users = false;
            if (empty($data['users']) && empty($data['groups'])) {
                $all_users = true;
            }
            $renderer->meta['plugin_ireadit_all_users'] = $all_users;
            return true;
        }
        return false;
    }
}
