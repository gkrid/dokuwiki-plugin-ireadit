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
            'overview' => '0'
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
        $method = 'render' . ucfirst($mode);
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
    public function renderMetadata(Doku_Renderer $renderer, $params)
    {
        $renderer->meta['plugin']['ireadit_list'] = [];

        if ($params['user'] == '$USER$') {
            $renderer->meta['plugin']['ireadit_list']['dynamic_user'] = true;
        }
    }

    public function renderXhtml(Doku_Renderer $renderer, $params)
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

        if ($params['user'] == '$USER$') {
            $params['user'] = $INFO['client'];
        }

        if ($params['overview'] == '1') {
            $q = 'SELECT I.page, I.timestamp,
                    (SELECT T.rev FROM ireadit T
                    WHERE T.page=I.page AND T.timestamp IS NOT NULL
                    ORDER BY rev DESC LIMIT 1) lastread
                    FROM ireadit I INNER JOIN meta M ON I.page = M.page AND I.rev = M.last_change_date
                    ';
            $res = $sqlite->query($q);
        } else {
            $user = $params['user'];
            $q = 'SELECT I.page, I.timestamp,
                    (SELECT T.rev FROM ireadit T
                    WHERE T.page=I.page AND T.user=? AND T.timestamp IS NOT NULL
                    ORDER BY rev DESC LIMIT 1) lastread
                    FROM ireadit I INNER JOIN meta M ON I.page = M.page AND I.rev = M.last_change_date
                    WHERE I.user=?';
            $res = $sqlite->query($q, $user, $user);
        }

        // Output List
        $renderer->doc .= '<ul>';
        while ($row = $sqlite->res_fetch_assoc($res)) {
            $page = $row['page'];
            $timestamp = $row['timestamp'];
            $lastread = $row['lastread'];

            if (!$timestamp && $lastread) {
                $state = 'outdated';
            } elseif (!$timestamp && !$lastread) {
                $state = 'unread';
            } else {
                $state = 'read';
            }

            if (!in_array($state, $params['state'])) {
                continue;
            }

            $urlParameters = [];
            if ($params['lastread'] && $state == 'outdated') {
                $urlParameters['rev'] = $lastread;
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
    }
}
