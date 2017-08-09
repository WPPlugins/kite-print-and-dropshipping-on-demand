<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

class WooKiteEndpoint {

	static $restricted = true;
	static $endpoint = null;
	static $sql = array();

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function handle_url() {
	}

	public function restricted() {
		return self::$restricted;
	}

}

class WooKiteAPI {


	static $api_classes = array(
		'cron' => 'WooKiteEndpointCron',
		'me' => 'WooKiteEndpointMe',
		'product_ranges' => 'WooKiteEndpointProductRanges',
		'publish_product' => 'WooKiteEndpointPublishProduct',
	);

	public function __construct( $plugin ) {
		global $wpdb;
		$this->plugin = $plugin;
		$this->endpoint = (empty( $_GET['endpoint'] ) ? null : $_GET['endpoint']);
		$dirname = dirname( __FILE__ ) . '/api/';
		foreach ( self::$api_classes as $name => $cls ) {
			include_once $dirname . $name . '.php';
			$this->$name = $obj = new $cls($plugin);
			$obj->api = $this;
		}
	}

	public function run() {
		// Handle URLs for all
		$allowed = (
		    $this->plugin->current_user_is_admin() &&
		    isset( $_GET['wpnonce'] ) &&
		    wp_verify_nonce( $_GET['wpnonce'], 'wookite-frontend' )
        );
		$this->method = strtolower( $_SERVER['REQUEST_METHOD'] );
		if ( in_array( $this->method, array( 'put', 'post' ) ) ) {
			$this->post = json_decode( file_get_contents( 'php://input' ), true );
		} elseif ( in_array( $this->method, array( 'delete' ) ) ) {
			parse_str( file_get_contents( 'php://input' ), $this->delete );
		}
		foreach ( self::$api_classes as $name => $cls ) {
			$obj = $this->$name;
			if ( $obj::$endpoint == $this->endpoint ) {
				if ( apply_filters( 'wookite_api_handler_allowed', $allowed || ! $obj->restricted(), $obj ) ) {
					if ( ! empty( $this->post ) ) {
						$obj->post =& $this->post;
					}
					if ( ! empty( $this->delete ) ) {
						$obj->delete =& $this->delete;
					}
					do_action( 'wookite_api_pre_handle_url', $obj );
					$obj->handle_url();
					do_action( 'wookite_api_post_handle_url', $obj );
				}
			}
		}
	}
}


