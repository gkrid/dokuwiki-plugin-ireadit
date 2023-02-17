<?php
// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_ireadit_notification extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PLUGIN_NOTIFICATION_REGISTER_SOURCE', 'AFTER', $this, 'add_notifications_source');
        $controller->register_hook('PLUGIN_NOTIFICATION_GATHER', 'AFTER', $this, 'add_notifications');
        $controller->register_hook('PLUGIN_NOTIFICATION_CACHE_DEPENDENCIES', 'AFTER', $this, 'add_notification_cache_dependencies');
    }

    public function add_notifications_source(Doku_Event $event)
    {
        $event->data[] = 'ireadit';
    }

    public function add_notification_cache_dependencies(Doku_Event $event)
    {
        if (!in_array('ireadit', $event->data['plugins'])) return;
        $event->data['_nocache'] = true; // TODO: notification cache mechanism should be updated to "Igor" dokuwiki
    }

    public function add_notifications(Doku_Event $event)
    {
        if (!in_array('ireadit', $event->data['plugins'])) return;

        $user = $event->data['user'];

        /** @var helper_plugin_ireadit $helper */
        $helper = $this->loadHelper('ireadit');
        $pages = $helper->get_list($user);

        foreach ($pages as $page => $row) {
            if ($row['state'] == 'read') continue;

            $link = '<a class="wikilink1" href="' . wl($page, '', true) . '">';
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
            $full = sprintf($this->getLang('notification full'), $link);
            $event->data['notifications'][] = [
                'plugin' => 'ireadit',
                'id' => $page . ':' . $row['current_rev'],
                'full' => $full,
                'brief' => $link,
                'timestamp' => (int) $row['current_rev']
            ];
        }
    }
}
