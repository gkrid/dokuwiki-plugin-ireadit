<?php

// must be run within DokuWiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_ireadit_list extends DokuWiki_Syntax_Plugin {

    function getType() {
        return 'substition';
    }

    function getSort() {
        return 20;
    }

    function PType() {
        return 'block';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *ireadit list *-+\n.*?----+', $mode,'plugin_ireadit_list');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        $lines = explode("\n", $match);
        array_shift($lines);
        array_pop($lines);

        $statemap = [
            'read' => ['read'],
            'outdated' => ['outdated'],
            'unread' => ['unread'],
            'not read' => ['outdated', 'unread'],
            'all' => ['read', 'outdated', 'unread'],
        ];

        $params = [
            'user' => '$USER$',
            'state' => $statemap['all'],
            'lastread' => '0',
            'overview' => '0',
            'namespace' => '',
            'filter' => false
        ];

        foreach ($lines as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) < 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key == 'state') {
                $states = array_map('trim', explode(',', strtolower($value)));
                $value = [];
                foreach ($states as $state) {
                    if (isset($statemap[$state])) {
                        $value = array_merge($value, $statemap[$state]);
                    } else {
                        msg('ireadit plugin: unknown state "'.$state.'" should be: ' .
                            implode(', ', array_keys($statemap)), -1);
                        return false;
                    }
                }
            } elseif ($key == 'namespace') {
                $value = trim(cleanID($value), ':');
            } elseif($key == 'filter') {
                $value = trim($value, '/');
                if (preg_match('/' . $value . '/', null) === false) {
                    msg('ireadit plugin: invalid filter regex', -1);
                    return false;
                }
            } elseif ($key == 'lastread') {
                if ($value != '0' && $value != '1') {
                    msg('ireadit plugin: lastread should be 0 or 1', -1);
                    return false;
                }
            } elseif ($key == 'overview') {
                if ($value != '0' && $value != '1') {
                    msg('ireadit plugin: overview should be 0 or 1', -1);
                    return false;
                }
            }
            $params[$key] = $value;
        }
        return $params;
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
     * @param array         $params     The data from the handler() function
     */
    public function render_metadata(Doku_Renderer $renderer, $params)
    {
        $renderer->meta['plugin_ireadit_list'] = true;
    }

    public function render_xhtml(Doku_Renderer $renderer, $params)
    {
        global $INFO;

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        //overview overrides user setting
        if ($params['overview'] == '1') {
            $user = NULL;
        } elseif ($params['user'] == '$USER$') {
            $user = $INFO['client'];
        } else {
            $user = $params['user'];
        }

        /** @var helper_plugin_ireadit $helper */
        $helper = $this->loadHelper('ireadit');
        $pages = $helper->get_list($user);

        // apply "filter" and "namespace"
        $pages = array_filter($pages, function ($k) use ($params) {
            return substr($k, 0, strlen($params['namespace'])) == $params['namespace'];
        }, ARRAY_FILTER_USE_KEY);
        $pages = array_filter($pages, function ($k) use ($params) {
            return preg_match('/' . $params['filter'] . '/', $k);
        }, ARRAY_FILTER_USE_KEY);

        // Output List
        $renderer->doc .= '<ul>';
        foreach ($pages as $page => $row) {
            if (!in_array($row['state'], $params['state'])) {
                continue;
            }

            $urlParameters = [];
            if ($params['lastread'] && $row['state'] == 'outdated') {
                $urlParameters['rev'] = $row['last_read_rev'];
            }
            $url = wl($page, $urlParameters);
            $link = '<a class="wikilink1" href="' . $url . '">';
            if (useHeading('content')) {
                $heading = p_get_first_heading($page);
                if (!blank($heading)) {
                    $link .= $heading;
                } else {
                    $link .= noNSorNS($page);
                }
            } else {
                $link .= noNSorNS($page);
            }
            $link .= '</a>';
            $renderer->doc .= '<li class="li">' . $link . '</li>';
        }
        $renderer->doc .= '</ul>';

        return true;
    }
}
