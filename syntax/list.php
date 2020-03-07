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

        $params = [
            'user' => '$USER$',
            'state' => 'all'
        ];

        foreach ($lines as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) < 2) {
                continue;
            }
            $key = trim($pair[0]);
            $value = trim($pair[1]);
            if ($key == 'state') {
                $statemap = [
                    'read' => ['read'],
                    'outdated' => ['outdated'],
                    'unread' => ['unread'],
                    'not read' => ['outdated', 'unread'],
                    'all' => ['read', 'outdated', 'unread'],
                ];
                $states = array_map('trim', explode(',', strtolower($value)));
                $value = [];
                foreach ($states as $state) {
                    if (isset($statemap[$state])) {
                        $value += $statemap[$state];
                    } else {
                        msg('ireadit plugin: unknown state "'.$state.'" should be: ' .
                            implode(', ', array_kes($statemap)), -1);
                        return false;
                    }
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

        global $conf;
        /** @var DokuWiki_Auth_Plugin $auth */
        global $auth;

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

        $where_query = [];
        $query_args = [];
        if ($params['user']) {
            $where_query[] = "ireadit.user=?";
            $query_args[] = $params['user'];
        }

        if($params['state'] == 'not read') {
            $where_query[] = "ireadit.timestamp IS NULL";
        } else {
            $where_query[] = "ireadit.timestamp IS NOT NULL";
        }

        $where_query_string = '';
        if ($where_query) {
            $where_query_string = 'WHERE ' . implode(' AND ', $where_query);
        }

        $q = "SELECT ireadit.page, MAX(ireadit.rev) AS read_rev, MAX(meta.last_change_date) AS current_rev
                FROM ireadit INNER JOIN meta
                    ON (ireadit.page=meta.page)
                    $where_query_string
                    GROUP BY ireadit.page
                    ORDER BY ireadit.page";

        $res = $sqlite->query($q, $query_args);

        // Output List
        $renderer->doc .= '<ul>';
        while ($row = $sqlite->res_fetch_assoc($res)) {
            $page = $row['page'];
            if (!isset($row['read_rev'])) {
                $state = 'unread';
            } elseif ($row['read_rev'] == $row['current_rev']) {
                $state = 'read';
            } else {
                $state = 'outdated';
            }
            if (!in_array($state, $params['state'])) {
                continue;
            }

            $url = wl($page);
            if (isset($row['read_rev'])) {
                $url .= '?rev=' . $row['read_rev'];
            }
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
