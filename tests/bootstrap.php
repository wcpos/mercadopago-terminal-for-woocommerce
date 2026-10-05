<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/mercadopago-terminal-for-woocommerce.php';

WP_Stub::$boot_actions = WP_Stub::$actions;
