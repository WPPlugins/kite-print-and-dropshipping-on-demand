<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once ABSPATH . 'wp-includes/pluggable.php';
require_once 'kite.php';
require_once 'plugin.php';
require_once 'api.php';

class WooKiteFrontendBase {

	public $scripts = array();
	public $styles = array();
	public $currency = null;
	protected $required_options = null;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		// From https://thomas.vanhoutte.be/miniblog/wordpress-hide-update/
		add_filter( 'pre_site_transient_update_core', array( $this, 'remove_core_updates' ) );
		add_filter( 'pre_site_transient_update_plugins', array( $this, 'remove_core_updates' ) );
		add_filter( 'pre_site_transient_update_themes', array( $this, 'remove_core_updates' ) );
	}

	public function remove_core_updates() {
		global $wp_version;
		return (object) array( 'last_checked' => time(),'version_checked' => $wp_version );
	}

	public function fails_to_run() {
		return false;
	}

	public function enqueue() {
		if ( $this->fails_to_run() !== false ) return;
		$url = $this->plugin->plugins_url( '/' );
		wp_enqueue_script( 'wookite-frontend-script', $url . 'frontend.js' );
		wp_enqueue_style( 'wookite-frontend-style', $url . 'frontend.css' );
		$url .= 'frontend';
		foreach ( $this->scripts as $script ) {
			wp_enqueue_script(
				'wookite-' . wookite_slugify( $script ),
				(preg_match( '#^https?://#i', $script ) ? '' : $url) . $script,
				'wookite-frontend-script'
			);
		}
		foreach ( $this->styles as $style ) {
			wp_enqueue_style(
				'wookite-' . wookite_slugify( $style ),
				(preg_match( '#^https?://#i', $style ) ? '' : $url) . $style
			);
		}
	}

	public function run() {
		$problem = $this->fails_to_run();
		if ( $problem === false ) {
			add_filter( 'admin_footer_text', '__return_empty_string', 999 );
			add_filter( 'update_footer', '__return_empty_string', 999 );
			$this->currency = get_woocommerce_currency();
			$this->name = get_option( 'blogname', '' );
			 $current_user = wp_get_current_user();
			 $this->email = (is_object( $current_user ) ? $current_user->email : '');
			if ( empty( $this->email ) ) {
				$this->email = get_option( 'admin_email', '' );
			}
			$this->created_at = $this->plugin->get_config( 'install_time' );
			$this->shop_url = esc_attr( $this->plugin->shop_url() );
			$this->shop_front_url = esc_attr( $this->plugin->shop_url() . '?orderby=date' );
            $plugin_class = get_class($this->plugin);
            $this->base_url = $plugin_class::plugins_url('frontend/');
		} else {
			printf( '<div class="notice notice-error"><p>%s</p></div>', $problem );
		}
	}

}

function wookite_request( $url, $args = array() ) {
	static $defaults = array(
	'method' => 'GET',
	'timeout' => 30,
	'user-agent' => 'Kite.ly Merch Plugin for WordPress',
	);
	static $default_headers = array(
	'Content-Type' => 'application/json; charset=utf-8',
	'Accept' => 'application/json',
	);
	if ( isset( $args['method'] ) ) {
		$args['method'] = strtoupper( $args['method'] );
	}
	if ( isset( $args['data'] ) ) {
		$args['body'] = json_encode( $args['data'] );
		unset( $args['data'] );
		if ( ! (isset( $args['method'] ) && in_array( $args['method'], array( 'PATCH', 'PUT' ) )) ) {
			$args['method'] = 'POST';
		}
	}
	if ( isset( $args['headers'] ) ) {
		$args['headers'] = wp_parse_args( $args['headers'], $default_headers );
	} else { $args['headers'] = $default_headers;
	}
	$args = wp_parse_args( $args, $defaults );
	$resp = wp_remote_request( $url, $args );
	if ( ! ($resp instanceof WP_Error) ) {
		$resp['json'] = json_decode( wp_remote_retrieve_body( $resp ), true );
		$resp['status_code'] = wp_remote_retrieve_response_code( $resp );
	}
	return $resp;
}

// From http://cubiq.org/the-perfect-php-clean-url-generator
function wookite_slugify( $str, $delimiter = '-' ) {
	$clean = iconv( 'UTF-8', 'ASCII//TRANSLIT', $str );
	$clean = preg_replace( '#[^a-zA-Z0-9/_| -]#', '', $clean );
	$clean = preg_replace( '#[/_| -]+#', $delimiter, $clean );
	$clean = strtolower( trim( $clean, $delimiter ) );
	if ( empty( $clean ) ) { $clean = '-';
	}
	return $clean;
}

function wookite_slug2str( $slug, $candidates ) {
	foreach ( $candidates as $candidate ) {
		if ( wookite_slugify( $candidate ) === $slug ) {
			return $candidate;
		}
	}
	return false;
}

function wookite_json_output( $data, $error_data = null, $error_code = 400 ) {
	if ( isset( $data ) ) {
		@header( 'Content-type: application/json' );
        $output = json_encode( $data );
		echo $output;
        # if (defined('WOOKITE_DEV') && WOOKITE_DEV)
    	# 	error_log( $output );
	} else
	if ( isset( $error_data ) ) {
		@header( 'Content-type: application/json' );
		http_response_code( $error_code );
		$output = json_encode( $error_data );
		echo $output;
        #if (defined('WOOKITE_DEV') && WOOKITE_DEV)
    	#	error_log( $output );
	}
	wookite_exit( 0 );
}

function wookite_json_error( $code, $message, $error_code = 400 ) {
	wookite_json_output(
		null,
		array( 'error' => array( 'code' => $code, 'message' => $message ) ),
		$error_code
	);
}

class WooKite_Unit_Testing_Exit extends Exception {


}

function wookite_exit( $code = 0 ) {
	if ( defined( 'WOOKITE_UNIT_TESTING' ) ) {
		throw new WooKite_Unit_Testing_Exit();
	} else { exit( $code );
	}
}

function wookite_redirect( $url ) {
	if ( defined( 'WOOKITE_UNIT_TESTING' ) ) {
		throw new WooKite_Unit_Testing_Exit( $url );
	} else {
		header( "Location: $url" );
		exit( 0 );
	}
}

function wookite_safe_redirect( $url ) {
	if ( defined( 'WOOKITE_UNIT_TESTING' ) ) {
		throw new WooKite_Unit_Testing_Exit( $url );
	} else {
		wp_safe_redirect( $url );
		exit( 0 );
	}
}

function wookite_maybe_unserialize_replace_callback($matches) {
	return sprintf('s:%d:"%s";', strlen($matches[2]), $matches[2]);
}

# From https://core.trac.wordpress.org/ticket/21109
function wookite_maybe_unserialize( $original ) {
	if ( is_serialized( $original ) ) {
		$original = preg_replace_callback(
			'#s:(\d+):"(.*?)";#s',
			'wookite_maybe_unserialize_replace_callback',
			$original
		);
		return @unserialize( $original );
	}
	return $original;
}

# A convenience function for unsupportive themes
function wookite_process_image($html, $post_id) {
	global $WooKitePlugin;
	return $WooKitePlugin->image_tag($html, $post_id);
}

