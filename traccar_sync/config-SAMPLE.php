<?php
/**
 * Grusher - Traccar sync config
 * RENAME THIS FILE TO config.php
 * @gviabcua
 */

$TRACCAR_URL      = 'http://localhost/api/';
$TRACCAR_USERNAME = 'user';
$TRACCAR_PASSWORD = 'pass';

// Set to false only for self-signed certs
$TRACCAR_SSL_VERIFY = true;

// Timezone for converting Traccar's UTC timestamps
// List: https://www.php.net/manual/en/timezones.php
$TIMEZONE = 'Europe/Kiev';

$GRUSHER_URL        = 'http://localhost';
$GRUSHER_API_KEY    = '';  // by default no key
$GRUSHER_TIMEOUT    = 5;
$GRUSHER_SSL_VERIFY = false;
