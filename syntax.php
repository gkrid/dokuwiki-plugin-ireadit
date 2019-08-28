<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

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

    function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~IREADIT.*?~~', $mode, 'plugin_ireadit');
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = trim(substr($match, strlen('~~IREADIT'), -2));
        $splits = preg_split('/[\s:]+/', $match, -1, PREG_SPLIT_NO_EMPTY);

        $users = [];
        $groups = [];
        foreach ($splits as $split) {
            if ($split[0] == '@') {
                $group = substr($split, 1);
                $groups[] = $group;
            } else {
                $users[] = $split;
            }
        }

        return ['users' => $users, 'groups' => $groups];
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if (!$data) {
            return false;
        }

        $method = "render_$mode";
        if (method_exists($this, $method)) {
            call_user_func([$this, $method], $renderer, $data);
            return true;
        }
        return false;
    }

    /**
     * Render metadata
     *
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     */
    public function render_metadata(Doku_Renderer $renderer, $data)
    {
        $plugin_name = $this->getPluginName();
        $renderer->meta['plugin'][$plugin_name] = $data;
    }
}
