<?php
/**
 * DokuWiki Plugin watchcycle (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <dokuwiki@cosmocode.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class admin_plugin_ireadit extends DokuWiki_Admin_Plugin
{
    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort()
    {
        return 1;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
        /* @var Input */
        global $INPUT;
        global $conf;
        /** @var DokuWiki_Auth_Plugin */
        global $auth;

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        /** @var helper_plugin_ireadit $helper */
        $helper = plugin_load('helper', 'ireadit');

        if ($INPUT->str('action') == 'regenerate_metadata' && checkSecurityToken()) {
            $datadir = $conf['datadir'];
            if (substr($datadir, -1) != '/') {
                $datadir .= '/';
            }

            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($datadir));
            $pages = [];
            foreach ($rii as $file) {
                if ($file->isDir()){
                    continue;
                }

                //remove start path and extension
                $page = substr($file->getPathname(), strlen($datadir), -4);
                $pages[] = str_replace('/', ':', $page);
            }

            $sqlite->query('DELETE FROM meta');
            foreach ($pages as $page) {
                //import historic data
                $meta = p_get_metadata($page, 'plugin ireadit');
                $last_change_date = p_get_metadata($page, 'last_change date');
                if (!$meta) continue;

                $sqlite->storeEntry('meta', [
                    'page' => $page,
                    'meta' => json_encode($meta),
                    'last_change_date' => $last_change_date
                ]);
            }

            //remove old rows
            $sqlite->query('DELETE FROM ireadit WHERE timestamp IS NULL');

            $res = $sqlite->query('SELECT page,meta FROM meta');
            while ($row = $sqlite->res_fetch_assoc($res)) {
                $page = $row['page'];
                $meta = json_decode($row['meta'], true);
                $last_change_date = p_get_metadata($page, 'last_change date');

                $users = $auth->retrieveUsers();
                foreach ($users as $user => $info) {
                    $res2 = $sqlite->query('SELECT user FROM ireadit WHERE page=? AND rev=? AND user=?', $page, $last_change_date, $user);
                    $existsAlready = $sqlite->res2single($res2);
                    if (!$existsAlready && $helper->in_users_set($user, $meta)) {
                        $sqlite->storeEntry('ireadit', [
                            'page' => $page,
                            'rev' => $last_change_date,
                            'user' => $user
                        ]);
                    }
                }
            }

            msg($this->getLang('admin success'), 1);
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        global $ID;
        /* @var DokuWiki_Auth_Plugin $auth */
        global $auth;

        try {
            /** @var \helper_plugin_ireadit_db $db_helper */
            $db_helper = plugin_load('helper', 'ireadit_db');
            $sqlite = $db_helper->getDB();
        } catch (Exception $e) {
            msg($e->getMessage(), -1);
            return false;
        }

        $res = $sqlite->query('SELECT page, meta, last_change_date FROM meta ORDER BY page');
        $metadata = $sqlite->res2arr($res);


       echo '<table class="inline">';
        // header
        echo '<tr>';
        echo '<th>'.$this->getLang('admin h_page').'</th>';
        echo '<th>'.$this->getLang('admin h_meta').'</th>';
        echo '<th>'.$this->getLang('admin h_last_change_date').'</th>';
        echo '</tr>';

        // existing assignments
        foreach($metadata as $row) {
            $page = $row['page'];
            $meta = $row['meta'];
            $last_change_date = $row['last_change_date'];

            echo '<tr>';
            echo '<td>' . hsc($page) . '</td>';
            echo '<td>' . hsc($meta) . '</td>';
            echo '<td>' . hsc($last_change_date) . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<form action="' . wl($ID) . '" action="post">';
        echo '<input type="hidden" name="do" value="admin" />';
        echo '<input type="hidden" name="page" value="ireadit" />';
        echo '<input type="hidden" name="sectok" value="' . getSecurityToken() . '" />';
        echo '<button name="action" value="regenerate_metadata">'.$this->getLang('admin regenerate_metadata').'</button>';
        echo '</form>';
    }
}

// vim:ts=4:sw=4:et:
