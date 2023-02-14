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
            'approved' => ['approved'], // MTK added
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

        $query_args = [$params['namespace'].'%'];
        $filter_q = '';

        // MTK 2021-10-22 addition option to get ireadit of last approved
        $id = $params['namespace'] ;

        if(!plugin_isdisabled('approve')) {
            try {
                $appdb_helper = plugin_load('helper', 'approve_db');
                $appsqlite = $appdb_helper->getDB();
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }
            $apphelper = plugin_load('helper', 'approve');
            $apprev = $apphelper->find_last_approved($appsqlite, $id);

            // get all users to read it
            $helper = plugin_load('helper', 'ireadit'); // need it?
            $users_set = [];
            $res = $sqlite->query('SELECT meta FROM meta where page = ?', $id);
            $row = $sqlite->res2row($res);
            $page = $row['page'];
            $meta = json_decode($row['meta'], true);
            $users = $meta['users'];
            $groups = $meta['groups'];
            $users_set = $helper->users_set($users, $groups);


            // get users from ireadit for page an approved rev
            $res = $sqlite->query('SELECT I.user FROM ireadit I WHERE I.page = ? and I.rev = ?', $id, $apprev);
            $reads = $sqlite->res2arr($res);


//            msg('apprev:' . $apprev);
        }


        if ($params['filter']) {
            $query_args[] = $params['filter'];
            $filter_q .= " AND I.page REGEXP ?";
        }

        if ($params['overview'] == '1') {
            $q = "SELECT I.page, MAX(I.timestamp) timestamp,
                    (SELECT MAX(T.rev) FROM ireadit T
                    WHERE T.page=I.page AND T.timestamp IS NOT NULL
                    ORDER BY rev DESC LIMIT 1) lastread
                    FROM ireadit I INNER JOIN meta M ON I.page = M.page AND I.rev = M.last_change_date
                    WHERE I.page LIKE ? ESCAPE '_' GROUP BY I.page
                    $filter_q";
            //GROUP BY I.page
            $res = $sqlite->query($q, $query_args);
            // MTK state 'approved# with page in field namespace
        } elseif (in_array('approved', $params['state'])) {
            array_unshift($query_args, $apprev);
            $q = "SELECT I.user, I.page, I.timestamp FROM ireadit I WHERE I.rev = ? AND I.page  like ? ESCAPE '_' $filter_q";
            $res = $sqlite->query($q, $query_args);
        } else {
            array_unshift($query_args, $params['user'], $params['user']);
            $q = "SELECT I.page, I.timestamp,
                    (SELECT T.rev FROM ireadit T
                    WHERE T.page=I.page AND T.user=? AND T.timestamp IS NOT NULL
                    ORDER BY rev DESC LIMIT 1) lastread
                    FROM ireadit I INNER JOIN meta M ON I.page = M.page AND I.rev = M.last_change_date
                    WHERE I.user=?
                    AND I.page LIKE ? ESCAPE '_'
                    $filter_q";
            $res = $sqlite->query($q, $query_args);
        }

        // Output List

        if (in_array('approved', $params['state'])) {
            $row = $sqlite->res_fetch_assoc($res);
            $user = $row['user'];
            $page = $row['page'];
            $timestamp = $row['timestamp'];
	    if (blank($user)) {
		    $page=$id;
		    $rev=$apprev;
	    }
            $readsarray = [];

            $urlParameters = [];
            $urlParameters['rev'] = $apprev;
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
            $renderer->doc .= '<h3>' . $link . '</h3>';


            $renderer->doc .= '<ul>';

            $renderer->doc .= '<li class="li">';

            /*
            $renderer->doc .= "All: " ;
            foreach ($users_set as $key => $setuser) {
                $renderer->doc .= " ". $setuser['name'] ;
                $renderer->doc .= " (". $key . "), " ;
            }

            $renderer->doc .= '</li><li class="li">';
             */

            $renderer->doc .= $this->getLang('applist_read') ;
            foreach ($reads as $key => $readuser) {
                $renderer->doc .= $users_set[$readuser['user']]['name'] ;
                $renderer->doc .= " (". $readuser['user'] . ")" ;
                if ($key != array_key_last($reads)) {
                    $renderer->doc .= ", " ;
                }

                $readsarray[$readuser['user']] = $users_set[$readuser['user']]['name'] ;
            }


            $renderer->doc .= '</li><li class="li">';

            $renderer->doc .= $this->getLang('applist_not_read') ;
            foreach ($users_set as $key => $setuser) {
                if (!array_key_exists($key, $readsarray)) {
                    $renderer->doc .=  $setuser['name'] . " (" . $key . "), " ;
                }
            }

            $renderer->doc .= '</li>';
            $renderer->doc .= '</ul>';

        } else {
            $renderer->doc .= '<ul>';
            while ($row = $sqlite->res_fetch_assoc($res)) {
                $user = $row['user'];
                $page = $row['page'];
                $timestamp = $row['timestamp'];
                $lastread = $row['lastread'];
                $readsarray = [];



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
}
