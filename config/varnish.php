<?php

/**
 * Varnish Purger config file
 *
 * Publish this file to `config/craft/varnish.php` and change the settings there; values set here override the ones
 * saved in the control panel. Use env() for anything that differs per environment.
 */

return [
    // Where BAN requests are delivered: one entry per Varnish instance, e.g. `http://varnish` or `http://10.0.0.11:6081`.
    // Setting it here locks the servers table in the control panel.
    // 'purgeUrls' => array_filter([env('VARNISH_URL')]),
    // hostname => IP to connect to for a purge URL, like an /etc/hosts entry just for purging
    'resolve' => [],
    // Only ban pages cached under these hostnames; needed when other sites share the same Varnish
    'hostnames' => [],
    // Pages whose X-Cache-Tags header would be longer aren't cached
    'maxTagsHeaderLength' => 4000,
];
