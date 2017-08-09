<?php

class WooKiteTools {

	function __construct( $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function get_tools() {
		$tools = array(
			'reload_config' => array(
				'name' => __( 'Reload Configuration', 'wookite' ),
				'button' => __( 'Reload', 'wookite' ),
				'desc' => __( 'This tool will reload the Kite.ly Merch Plugin configuration.', 'wookite' ),
				'done' => __( 'The Kite.ly Merch Plugin configuration has been updated', 'wookite' ),
				'kite_live_matters' => false,
				'info_text' => __( ' (the next automatic update: %s)', 'wookite' ),
				'help' => __( '<p>Kite.ly Merch Plugin has some configuration loaded automatically once a week. This includes shipping costs, automatically generated categories, etc.</p><p>This tool will reload configuration immediately.</p>', 'wookite' ),
			),
		);
		$raw_me = get_option( 'wookite_me', false );
		if ( is_array( $raw_me ) && ! (bool) $raw_me['registered'] ) {
			$tools = array_merge(
				$tools, array(
					'kite_reregister' => array(
						'name' => __( 'Account Registration', 'wookite' ),
						'button' => __( 'Retry Registration', 'wookite' ),
						'desc' => __( 'Attempts to register a Kite account with your Wordpress admin e-mail address.', 'wookite' ),
						'done' => __( 'Registration request sent', 'wookite' ),
						'kite_live_matters' => false,
						'help' => __( '<p>In order to process and fulfil your Kite orders, Kite.ly Merch Plugin needs to register with our main server. This is usually done automatically using your Wordpress admin email address.</p><p>If there is already an account associated with that email address, you will receive an e-mail with a confirmation link enabling you to to link your existing account with a single click.</p><p>This tool will repeat the registration attempt. If it fails again, the confirmation email will be resent to you.</p>', 'wookite' ),
					),
					'kite_register_email' => array(
						'name' => __( 'Alternative Registration', 'wookite' ),
						'button' => __( 'Register with this email:', 'wookite' ),
						'post-button' => ' <input type="text" name="email" /> ',
						'desc' => __( 'Attempts to register a Kite account with an alternative e-mail address, that you provide above.', 'wookite' ),
						'done' => __( 'Registration request sent', 'wookite' ),
						'done-fail-email' => __( 'Invalid email address', 'wookite' ),
						'kite_live_matters' => false,
						'help' => __( '<p>In order to process and fulfil your Kite orders, Kite.ly Merch Plugin needs to register with our main server. This is usually done automatically using your Wordpress account email address.</p><p>If there is already an account associated with that email address, you will receive an e-mail with a confirmation link enabling you to to link your existing account with a single click.</p><p>This tool allows you to register with our main server using a different email address from the one you use with your Wordpress/WooCommerce store.</p>', 'wookite' ),
						// 'help_title' => __( 'Register with a different email', 'wookite' ),
					),
				)
			);
		}
		$tools = array_merge(
			$tools, array(
				'retry_sending' => array(
					'name' => __( 'Unsent Orders', 'wookite' ),
					'button' => __( 'Send now', 'wookite' ),
					'desc' => __( 'Submits all unsent Kite orders to Kite immediately.', 'wookite' ),
					'done' => __( 'Kite orders dispatched', 'wookite' ),
					'kite_live_matters' => true,
					'info_text' => __( ' (number of such orders: %d)', 'wookite' ),
					'help' => __( '<p>Orders will be sent to the Kite server periodically. This tool will attempt send your queued Kite orders immediately.</p>', 'wookite' ),
				),
				'update_statuses' => array(
					'name' => __( 'Order Statuses', 'wookite' ),
					'button' => __( 'Update now', 'wookite' ),
					'desc' => __( 'Refreshes the status of all incomplete Kite orders.', 'wookite' ),
					'done' => __( 'Kite orders\' statuses updated', 'wookite' ),
					'kite_live_matters' => true,
					'info_text' => __( ' (number of such orders: %d)', 'wookite' ),
					'help' => __( '<p>Kite.ly Merch Plugin periodically checks the statuses of current Kite orders and updates the data in your WooCommerce Orders section.</p><p>This tool requests an immediate update for all orders that are not yet marked as either fulfilled or failed.</p>', 'wookite' ),
				),
				'autoset_product_images' => array(
					'name' => __( 'Missing Images', 'wookite' ),
					'button' => __( 'Add Kite Images', 'wookite' ),
					'desc' => __( 'Sets a Kite image for Kite products that don\'t have one set.', 'wookite' ),
					'done' => __( 'Kite products\' images updated', 'wookite' ),
					'kite_live_matters' => false,
					'info_text' => __( ' (number of such products: %d)', 'wookite' ),
					'help' => __( '<p>Kite product images are generated dynamically. In order for that to work correctly, both the products and the variants need to have a specific image set. This image is automatically added to your Media Library and set as a placeholder product/variant image for all Kite products.</p><p>In cases where products and/or their variants are missing this image, this tool will reset it.</p>', 'wookite' ),
					// 'help_title' => __( 'Set images for products that miss them', 'wookite' ),
				),
				'reset_product_images' => array(
					'name' => __( 'Reset Images', 'wookite' ),
					'button' => __( 'Reset Kite Images', 'wookite' ),
					'desc' => __( 'Sets a Kite image for <b>all</b> Kite products, regardless of them having one set or not.', 'wookite' ),
					'done' => __( 'Kite products\' images reset', 'wookite' ),
					'kite_live_matters' => false,
					'info_text' => __( ' (number of such products: %d)', 'wookite' ),
					'help' => __( '<p>Kite product images are generated dynamically. In order for that to work correctly, both the products and the variants need to have a specific image set. This image is automatically added to your Media Library and set as a placeholder product/variant image for all Kite products.</p><p>This tool will reset that image for all Kite products and their variants, <b>including those which you may have replaced with another image</b>.</p>', 'wookite' ),
					// 'help_title' => __( 'Reset images for all products', 'wookite' ),
				),
				'autoset_shipping_classes' => array(
					'name' => __( 'Missing Shipping Classes', 'wookite' ),
					'button' => __( 'Add Shipping Classes', 'wookite' ),
					'desc' => __( 'Sets a proper shipping class for Kite products that don\'t have one set.', 'wookite' ),
					'done' => __( 'Kite products\' shipping classes updated', 'wookite' ),
					'kite_live_matters' => false,
					'info_text' => __( ' (number of such products: %d)', 'wookite' ),
					'help' => __( '<p>In order for your store to be able to calculate accurate shipping costs, Kite products and their variants should have their shipping class properly set. If your store shipping zones have been setup to correspond with Kite shipping zones (in Kite.ly Merch&gt;Settings&gt;Shipping), this is done automatically.</p><p>If certain Kite products and/or variants have been created without shipping classes or had their shipping classes deleted, this tool will reset them to their proper values.</p>', 'wookite' ),
					// 'help_title' => __( 'Set shipping classes for products that miss them', 'wookite' ),
				),
				'reset_shipping_classes' => array(
					'name' => __( 'Reset Shipping Classes', 'wookite' ),
					'button' => __( 'Reset Shipping Classes', 'wookite' ),
					'desc' => __( 'Sets a proper shipping class for <b>all</b> Kite products, regardless of them having one set or not.', 'wookite' ),
					'done' => __( 'Kite products\' shipping classes reset', 'wookite' ),
					'kite_live_matters' => false,
					'info_text' => __( ' (number of such products: %d)', 'wookite' ),
					'help' => __( '<p>In order for your store to be able to calculate accurate shipping costs, Kite products and their variants should have their shipping class properly set. If your store shipping zones have been setup to correspond with Kite shipping zones (in Kite.ly Merch&gt;Settings&gt;Shipping), this is done automatically.</p><p>This tool will reset all Kite products and variants to their prescribed shipping class, even if they have been previously changed or deleted.</p>', 'wookite' ),
					// 'help_title' => __( 'Reset shipping classes for all Kite products', 'wookite' ),
				),
				'uninstall' => array(
					'name' => __( 'Uninstall', 'wookite' ),
					'button' => __( 'Remove Kite.ly Merch Plugin and Erase All Data', 'wookite' ),
					'button_style' => 'wookite-uninstall',
					'desc' => __( 'This will permanently remove all Kite.ly Merch Plugin data (products and their images, Kite-specific information on orders, Kite account information, shipping classes, dynamically created categories, etc.) from your Wordpress database, and deactivate the plugin.<br /><span class="wookite-uninstall-warning">Warning: There is no undo for this action!</span>', 'wookite' ),
					'confirmation_text' => __( 'Unless you have a fresh backup of your database, it is impossible to undo this action. Are you sure that you want to proceed?', 'wookite' ),
					'help' => __( '<p>In order to function properly, Kite.ly Merch Plugin needs to save its data in Wordpress\' database. This data is automatically removed if you decide to “delete” the plugin via Wordpress\' interface. </p><p>If you prefer manual (un)installation, this tool will do most of the job (you will still need to delete the plugin directory manually).</p><p>If you do decide to uninstall, we would appreciate your feedback via <a href="hello@kite.ly">email</a> so that we can continue to improve the plug-in.</p><p>Please note that there is <b>no way to undo</b> this tool\'s work - all data including products, settings and connections will be permanently erased. If you only wish to temporarily remove the plug-in, please "Deactivate" it via the <a href="plugins.php">Plugins page</a> instead.</p>', 'wookite' ),
					// 'help_title' => __( 'Uninstall Kite.ly Merch Plugin', 'wookite' ),
				),
			)
		);
		return $tools;
	}

	public function run() {
		if ( $this->plugin->current_user_is_admin() ) {
			$tools = $this->get_tools();
			$action = @sanitize_title( $_GET['action'] );
			$wpnonce = @$_REQUEST['_wpnonce'];
			if ( ! empty( $action ) && ! empty( $tools[ $action ] ) && ! empty( $wpnonce ) && wp_verify_nonce( $wpnonce, 'wookite-tools' ) ) {
				$tool = 'tool_' . $action;
				$res = $this->$tool();
				if ( isset( $_GET['redirect'] ) ) {
					$url = admin_url( $_GET['redirect'] );
					if ( ! empty( $res ) && strpos( $url, '%s' ) !== false ) {
						$url = sprintf( $url, $res );
					}
				} else {
					$url = admin_url( "admin.php?page=wookite-tools&done=$action" );
					if ( ! empty( $res ) ) { $url .= ",$res";
					}
				}
				wookite_redirect( $url );
			}
		}
	}

	public function admin_notices() {
		$tools = $this->get_tools();
		if ( isset( $_GET['done'] ) ) {
			$done_fields = explode( ',', $_GET['done'], 2 );
			if ( ! empty( $done_fields ) ) {
				$tool = $tools[ $done_fields[0] ];
				if ( count( $done_fields ) > 1 && ! empty( $tool[ 'done-' . $done_fields[1] ] ) ) {
					$done = $tool[ 'done-' . $done_fields[1] ];
					$type = 'error';
				} else {
					$done = (isset( $tool['done'] ) ? $tool['done'] : '');
					$type = 'success';
				}
				if ( ! empty( $done ) ) {
					printf( '<div class="notice notice-%s"><p>%s</p></div>', $type, $done );
				}
			}
		}
	}

	public function frontend() {
		wp_enqueue_style( 'woocommerce_admin_styles' );
		$tools = $this->get_tools();
		$kite_live = $this->plugin->get_option( 'kite-live-mode' );
		$kite_button_style = ($kite_live ? '' : ' testkite');
		include_once 'view-tools.php';
	}

	/* Tools */

	public function info_reload_config() {
		return sprintf(
			'%s, %s',
			date( wc_time_format(), $this->plugin->get_config( 'expiration' ) ),
			date( wc_date_format(), $this->plugin->get_config( 'expiration' ) )
		);
	}

	public function tool_reload_config() {
		$this->plugin->force_config_fetch = true;
		$this->plugin->get_config();
	}

	public function tool_kite_reregister() {
		delete_option( 'wookite_me' );
		$this->plugin->me();
	}

	public function tool_kite_register_email() {
		$email = $_GET['email'];
		if ( empty( $email ) || ! is_email( $email ) ) {
			return 'fail-email';
		}
		$this->plugin->api->me->autoregister( $email );
	}

	function info_retry_sending() {
		return $this->plugin->kite->cnt_autosend_orders();
	}

	function tool_retry_sending() {
		$this->plugin->kite->autosend_orders();
	}

	function info_update_statuses() {
		return $this->plugin->kite->cnt_autoupdate_statuses( true );
	}

	function tool_update_statuses() {
		$this->plugin->kite->autoupdate_statuses( true );
	}

	function info_autoset_product_images() {
		return $this->plugin->cnt_autoset_product_images( false );
	}

	function tool_autoset_product_images() {
		$this->plugin->autoset_product_images( false );
	}

	function info_reset_product_images() {
		return $this->plugin->cnt_autoset_product_images( true );
	}

	function tool_reset_product_images() {
		$this->plugin->autoset_product_images( true );
	}

	function info_autoset_shipping_classes() {
		return $this->plugin->cnt_autoset_shipping_classes( false );
	}

	function tool_autoset_shipping_classes() {
		$this->plugin->autoset_shipping_classes( false );
	}

	function info_reset_shipping_classes() {
		return $this->plugin->cnt_autoset_shipping_classes( true );
	}

	function tool_reset_shipping_classes() {
		$this->plugin->autoset_shipping_classes( true );
	}

	function tool_uninstall() {
		$this->plugin->uninstall( true );
		wookite_redirect( admin_url( 'plugins.php' ) );
	}

}


