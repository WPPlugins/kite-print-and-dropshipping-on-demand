<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
defined( 'WP_UNINSTALL_PLUGIN' ) or die( 'Invalid uninstall request.' );

require_once dirname( __FILE__ ) . '/kite-print-and-dropshipping-on-demand.php';

$WooKitePlugin = WooKitePlugin::get_instance();
$WooKitePlugin->init();
$WooKitePlugin->uninstall();


