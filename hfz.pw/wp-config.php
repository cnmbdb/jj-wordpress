<?php
define('WP_CACHE', false); // Added by WP Rocket
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'wp.hfz.pw');

/** Database username */
define('DB_USER', 'wp.hfz.pw');

/** Database password */
define('DB_PASSWORD', 'wp.hfz.pw');

/** Database hostname */
define('DB_HOST', 'db');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');
define('WP_CACHE_KEY_SALT', 'hpWQo8vfb3');
/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY', '_z.ot7^Du.BP>n0eQJ3-.Ix^2EOpifkobApg6)$,C{lP9ijM,1T=9A2E.h!&5Ve_');
define('SECURE_AUTH_KEY', '{izBJy${4h?(`ABX=ri&nSiZmM*2;jp=Bknf</./v~r,0CtSB/pFuS(vO%RZ+kt~');
define('LOGGED_IN_KEY', 'S^G;:gIX~@BJ>W7/N+EbhC& 4-@K1ccL>e:6`s1}L&teO781*O->J^./+nVQ;O;-');
define('NONCE_KEY', '*Isj}2PWHhb_0KtX: 8B@0JFjYZrxU3)dn9:Sh!`um^@!cBLib4+Z<QPIr)hEKJz');
define('AUTH_SALT', 't^88u1NpPQYT4j&7I8FIll4w3<YGJ+18?Na/n$MZz+{l}U&CE-vH3 k/,V>/ghj{');
define('SECURE_AUTH_SALT', '2N x8woYS@HSO.AW$c>O%fP[GwxTVW :sVTUk*>jsys(7mB](ADifK2>a`}Ut]iC');
define('LOGGED_IN_SALT', 'ycgFWoX]%N-$]=K^EgE*nfs8t>v&9&+Y$`0</294eq@GA3/0!LLU/g7 8h$*j#q[');
define('NONCE_SALT', '.D ^J( %}).vD1~>Hv.qFbb%B!Ql;E+}!y|q9Ij<;!AiYh5Dn=#0/]O73<N2+7eJ');

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
define('WP_DEBUG', false);

/* Add any custom values between this line and the "stop editing" line. */

/**
 * Support for Reverse Proxy and HTTPS
 */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) {
    $_SERVER['HTTPS'] = 'on';
}

if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}

// Dynamic Site URL and Home
if (isset($_SERVER['HTTP_HOST'])) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

    // Avoid defining if already defined (safety check)
    if (!defined('WP_HOME')) {
        define('WP_HOME', $scheme . '://' . $_SERVER['HTTP_HOST']);
    }
    if (!defined('WP_SITEURL')) {
        define('WP_SITEURL', $scheme . '://' . $_SERVER['HTTP_HOST']);
    }
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}


/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
