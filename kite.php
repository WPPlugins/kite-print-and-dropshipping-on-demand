<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;
define( 'WOOKITE_ORDERS_TABLE_NAME', $wpdb->prefix . 'wookite_orders' );
define( 'WOOKITE_POSTMETA_TABLE_NAME', $wpdb->prefix . 'wookite_postmeta' );
define( 'WOOKITE_MEDIA_URLS_TABLE_NAME', $wpdb->prefix . 'wookite_media_urls' );

class WooKiteKite {
	public $order_status_not_sent = 'Not yet sent to Kite';
	public $item_status_not_sent = 'Pending';
	public static $order_statuses = array(
		'Received',
		'Accepted',
		'Validated',
		'Processed',
		'Cancelled',
	);
	public static $order_item_statuses = array(
		'Pending',
		'Sent to Printer',
		'Received by Printer',
		'Shipped',
		'Cancelled',
		'On Hold',
		'Fulfilment failed',
	);
	public static $post_type2parent = array(
		'p' => 'range_id', // product
		'v' => 'product_id', // variant
	);
	public $api_url = 'https://api.kite.ly/v4.0/';
	public $posts_to_delete = array();

	// Called from WooKitePlugin::init
	public function __construct( $plugin ) {
		global $wpdb;
		$this->plugin = $plugin;
		// Order-Kite relation
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . WOOKITE_ORDERS_TABLE_NAME . ' (
			id BIGINT(20) UNSIGNED NOT NULL auto_increment,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			kite_id VARCHAR(15) DEFAULT "",
			time_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			kite_data LONGTEXT DEFAULT "",
			items LONGTEXT DEFAULT "",
			done TINYINT(1) DEFAULT 0,
			kite_status VARCHAR(17) DEFAULT NULL,
			status_code SMALLINT(3) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id_index  (order_id)
		)'
		);
		dbDelta(
			'CREATE TABLE ' . WOOKITE_POSTMETA_TABLE_NAME . ' (
			id BIGINT(20) UNSIGNED NOT NULL auto_increment,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			type CHAR(1) NOT NULL,
			time_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			parent_id BIGINT(20) UNSIGNED NOT NULL,
			kite_data LONGTEXT DEFAULT "",
			media_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id_index  (post_id),
			KEY type_index  (type),
			KEY parent_id_index  (parent_id)
		)'
		);
		dbDelta(
			'CREATE TABLE ' . WOOKITE_MEDIA_URLS_TABLE_NAME . ' (
			id BIGINT(20) UNSIGNED NOT NULL auto_increment,
			range_id BIGINT(20) UNSIGNED NOT NULL,
			post_id BIGINT(20) UNSIGNED NOT NULL,
			url varchar(4096) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id_index  (post_id)
		)'
		);
		// Products
		add_filter( 'manage_edit-product_columns', array( $this, 'show_products_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'products_column' ), 11, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'show_order_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'order_column' ), 11, 2 );
		add_action( 'deleted_post', array( $this, 'deleted_post' ), 10 );
		add_action( 'shutdown', array( $this, 'shutdown' ), 0 );
		// Orders
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_admin_order_action' ), 10, 2 );
		add_action( 'wp_ajax_wookite_send_order_to_kite', array( $this, 'send_order' ) );
		add_action( 'wp_ajax_wookite_get_order_status', array( $this, 'get_order_status' ) );
		add_action( 'woocommerce_order_action_wookite_send_to_kite', array( $this, 'send_order' ) );
		add_action( 'woocommerce_order_action_wookite_get_order_status', array( $this, 'get_order_status' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_order_status' ), 10, 1 );
		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'add_order_item_column' ), 10, 1 );
		add_action( 'woocommerce_admin_order_item_values', array( $this, 'add_order_item_data' ), 10, 3 );
		add_action( 'woocommerce_admin_order_items_after_fees', array( $this, 'add_order_details' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'order_processing' ), 10, 1 );
		// Various filters use text sanitization, so let's parse that one too
		add_filter( 'sanitize_text_field', array( $this, 'sanitize_text_field' ), 10, 2 );
		// YOAST SEO
		add_filter( 'wpseo_opengraph_image', array( $this, 'wpseo_opengraph_image' ), 10, 1 );
		add_filter( 'wpseo_twitter_image', array( $this, 'wpseo_opengraph_image' ), 10, 1 );
		add_filter( 'wpseo_json_ld_output', array( $this, 'wpseo_json_ld_output' ), 10, 2 );
		// Special post types
		$this->register_post_types();
	}

	public function shutdown() {
		global $wpdb;
		if ( ! empty( $this->posts_to_delete ) ) {
			// Delete data of all posts scheduled for destruction
			$ids = array_keys( $this->posts_to_delete );
			$this->delete_posts_data( $ids );
			$this->delete_orders_data( $ids );
			// Delete URLs of all attachments scheduled for destruction
			$wpdb->query(sprintf(
				'DELETE FROM ' . WOOKITE_MEDIA_URLS_TABLE_NAME . ' ' .
				'WHERE post_id IN (%s)',
				implode(',', $ids)
			));
		}
	}

	public function register_post_types() {
		# Hiden products
		register_post_type(
			'wookite_hid_prod',
			array(
				'label'           => __( 'Hidden Kite product', 'wookite' ),
				'public'          => false,
				'hierarchical'    => false,
				'supports'        => false,
				'capability_type' => 'product',
				'rewrite'         => false,
			)
		);
		# Hiden variants
		register_post_type(
			'wookite_hid_var',
			array(
				'label'           => __( 'Hidden Kite product variation', 'wookite' ),
				'public'          => false,
				'hierarchical'    => false,
				'supports'        => false,
				'capability_type' => 'product',
				'rewrite'         => false,
			)
		);
	}

	public function account_status( $fields = null ) {
		$me = $this->plugin->me();
		if ( $me ) {
			if ( $this->plugin->get_option( 'kite-live-mode' ) ) {
				if ( empty( $me['kite_live_publishable_key'] )
					|| empty( $me['kite_live_secret_key'] )
				) {
					$status = array(
					'ok' => false,
					'err' => 'keys-live',
					);
				} else {
					$status = array(
					'ok' => true,
					'live' => true,
					);
				}
			} else {
				if ( empty( $me['kite_test_publishable_key'] )
					|| empty( $me['kite_test_secret_key'] )
				) {
					$status = array(
					'ok' => false,
					'err' => 'keys-test',
					);
				} else {
					$status = array(
					'ok' => true,
					'live' => false,
					);
				}
			}
		} else {
			$status = array(
			'ok' => false,
			'err' => 'account',
			);
		}
		if ( isset( $fields ) ) {
			if ( is_array( $fields ) ) {
				$res = array();
				foreach ( (array) $fields as $field ) {
					if ( isset( $status[ $field ] ) ) {
						$res[ $field ] = $status[ $field ];
					}
				}
				return $res;
			} else {
				return $status[ $fields ];
			}
		}
		return $status;
	}

	public function request( $endpoint, $args = array() ) {
		$url = $this->api_url . $endpoint;
		$me = (
			(empty( $args['ignore-keys'] ) || ! $args['ignore-keys']) ?
			$this->plugin->me( null ) :
			false
		);
		if ( $me ) {
			if ( isset( $args['use-keys'] ) && in_array( $args['use-keys'], array( 'test', 'live' ) ) ) {
				$live = ($args['use-keys'] === 'live');
			} else {
				$live = $this->plugin->get_option( 'kite-live-mode' );
			}
			if ( $live ) {
				$key = $me['kite_live_publishable_key'];
				$kite_secret = $me['kite_live_secret_key'];
			} else {
				$key = $me['kite_test_publishable_key'];
				$kite_secret = $me['kite_test_secret_key'];
			}
			if ( empty( $kite_secret ) ) {
				return new WP_Error(
					'wookite-invalid-keys',
					'You need to confirm your Kite account (check your email) before doing this.'
				);
			}
			$key .= ":$kite_secret";
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = "ApiKey $key";
		}
		unset( $args['ignore-keys'], $args['use-keys'] );
		return wookite_request( $url, $args );
	}

	public function time_for_cron_now() {
		$delay = 10 * 60;
		$last = get_option( 'wookite_last_cron', false );
		$now = time();
		if ( $last === false || $last + $delay < $now ) {
			return $now;
		} else {
			return false;
		}
	}

	public function cron_now() {
		$now = $this->time_for_cron_now();
		if ( $now !== false ) {
			update_option( 'wookite_last_cron', $now );
			$this->autosend_orders();
			$this->autoupdate_statuses();
		}
		wookite_exit();
	}

	public function redirect_back_to_orders( $urls = null ) {
		if ( empty( $url ) ) {
			$url = wp_get_referer();
			if ( empty( $url ) ) {
				$url = admin_url( 'edit.php?post_type=shop_order' );
			}
		}
		wookite_safe_redirect( $url );
	}

	protected function is_order_done( $order_data ) {
		if ( empty( $order_data['jobs'] ) ) {
			return false;
		}
		foreach ( (array) $order_data['jobs'] as $job ) {
			if ( $job['status'] !== 'Shipped' && $job['status'] !== 'Cancelled' ) {
				if (
					! $order_data['test_order'] ||
					($job['status'] !== 'Sent to Printer' && $job['status'] !== 'Received by Printer')
				) {
					return false;
				}
			}
		}
		return true;
	}

	public function update_order_data( $order_id, $data ) {
		global $wpdb;
		$oid = $this->plugin->get_order_id( $order_id );
		if ( isset( $data['kite_data'] ) ) {
			if ( ! empty( $data['kite_data']['error'] ) ) {
				$data['kite_data']['status'] = 'Error';
			}
			$data['kite_data']['done'] = $this->is_order_done( $data['kite_data'] );
			if ( $data['kite_data']['done'] ) {
				// Check if this has just happened or it was done before
				$was_done = $this->get_kite_order_data( $oid, 'done' );
				if ( ! $was_done ) {
					// Yup, it just happened!
					$order = $this->plugin->get_order( $oid );
					$order_data = $this->get_order_data( $order );
					if ( $order && $order_data['only_kite'] ) {
						// Kite-only order, so complete
						$note = (
						$this->plugin->get_option( 'add_order_notes' ) ?
						__( 'Kite order completed.', 'wookite' ) :
						''
						);
						$order->update_status( 'completed', $note );
					}
				}
			}
			if ( ! isset( $data['kite_id'] ) || empty( $data['kite_id'] ) ) {
				$data['kite_id'] = $data['kite_data']['order_id'];
			}
			if ( empty( $data['done'] ) ) {
				$data['done'] = $data['kite_data']['done'];
			}
			if ( empty( $data['kite_status'] ) ) {
				$data['kite_status'] = $data['kite_data']['status'];
			}
			$data['kite_data'] = apply_filters(
				'wookite_fetched_kite_data', $data['kite_data'], $oid, $data['kite_id']
			);
		}
		$values = array( $wpdb->prepare( 'order_id=%d', $oid ) );
		foreach ( $data as $name => $value ) {
			$values[ $name ] = sprintf( '%s=%s', $name, $wpdb->prepare( '%s', maybe_serialize( $value ) ) );
		}
		return $wpdb->query(
			sprintf(
				'INSERT INTO ' . WOOKITE_ORDERS_TABLE_NAME . ' SET %1$s ON DUPLICATE KEY UPDATE %1$s',
				implode( ', ', $values )
			)
		);
	}

	public function update_kite_data( $order_id, $kite_data ) {
		return $this->update_order_data( $order_id, array( 'kite_data' => $kite_data ) );
	}

	public function delete_orders_data( $order_ids ) {
		global $wpdb;
		$del = array();
		foreach ( (array) $order_ids as $id ) {
			$del[] = (int) $id;
		}
		return $wpdb->get_var(
			sprintf(
				'DELETE FROM ' . WOOKITE_ORDERS_TABLE_NAME . '
				WHERE order_id IN (%s)',
				implode( ',', $del )
			)
		);
	}

	public function update_post_data( $post_id, $type, $post_data ) {
		global $wpdb;
		$wpdb->replace(
			WOOKITE_POSTMETA_TABLE_NAME,
			array(
				'post_id' => $post_id,
				'type' => $type,
				'parent_id' => $post_data[ self::$post_type2parent[ $type ] ],
				'kite_data' => serialize( $post_data ),
			),
			array(
				'post_id' => '%d',
				'type' => '%s',
				'parent_id' => '%d',
				'kite_data' => '%s',
			)
		);
	}

	public function get_post_data( $post_id, $type ) {
		global $wpdb;
		$res = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT kite_data FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
				'WHERE post_id=%d AND type=%s',
				$post_id, $type
			)
		);
		if ( empty( $res ) ) { return false;
		}
		return wookite_maybe_unserialize( $res );
	}

	public function get_posts_data( $post_ids = null, $field = null, $type = null ) {
		// Ensures that the products/variants actually exist in both
		// WP's and WooKite's posts tables.
		global $wpdb;
		$conds = array();
		if ( isset( $post_ids ) ) {
			$pids = array();
			foreach ( (array) $post_ids as $pid ) {
				$pids[] = (int) $pid;
			}
			if ( empty( $pids ) ) { return array();
			}
			$conds[] = sprintf( 'post_id IN (%s)', implode( ', ', $pids ) );
		}
		if ( isset( $type ) ) {
			$conds[] = $wpdb->prepare( 'type=%s', $type );
		}
		$kite_table = (
			empty( $conds ) ?
			WOOKITE_POSTMETA_TABLE_NAME :
			'(SELECT post_id, kite_data FROM ' . WOOKITE_POSTMETA_TABLE_NAME .
				' WHERE ' . implode( ' AND ', $conds ) . ')'
		);
		$posts = $wpdb->get_results(
			sprintf(
				'SELECT kite.post_id AS post_id, kite_data AS kite_data '.
				'FROM %s kite ' .
				'INNER JOIN ' . $wpdb->posts . ' wp ON kite.post_id = wp.ID',
				$kite_table
			), ARRAY_A
		);
		if ( empty( $posts ) ) { return array();
		}
		$res = array();
		foreach ( $posts as $post ) {
			if ( $field === true ) {
				$res[ (int) $post['post_id'] ] = true;
			} else {
				$data = wookite_maybe_unserialize( $post['kite_data'] );
				$res[ (int) $post['post_id'] ] = (isset( $field ) ? $data[ $field ] : $data);
			}
		}
		return $res;
	}

	public function get_post_type_data( $post_id ) {
		global $wpdb;
		$res = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT type, kite_data, parent_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
				'WHERE post_id=%d',
				$post_id
			), ARRAY_A
		);
		if ( empty( $res ) ) {
			return false;
		}
		$res['kite_data'] = wookite_maybe_unserialize( $res['kite_data'] );
		return $res;
	}

	public function get_post_parent_id( $post_id, $type = null ) {
		global $wpdb;
		$type_cond = (
			is_null( $type ) ?
			'' :
			$wpdb->prepare( ' AND type=%s', $type )
		);
		$res = $wpdb->get_var(
			sprintf(
				'SELECT parent_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
				'WHERE post_id=%d%s',
				(int) $post_id, $type_cond
			)
		);
		if ( is_null( $res ) ) {
			return false;
		}
		return (int) $res;
	}

	public function get_posts_parents_ids( $posts_ids, $type = null ) {
		// Returns array( $parent_id => array of $post_ids with that parent )
		global $wpdb;
		$pids = array();
		foreach ( (array) $posts_ids as $pid ) {
			$pids[] = (int) $pid;
		}
		if ( empty( $pids ) ) {
			return array();
		}
		$type_cond = (
			is_null( $type ) ?
			'' :
			$wpdb->prepare( ' AND type=%s', $type )
		);
		$res = array();
		foreach ( $wpdb->get_results(
			sprintf(
				'SELECT post_id, parent_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
				'WHERE post_id IN (%s)%s',
				implode( ', ', $pids ), $type_cond
			),
			ARRAY_A
		) as $row ) {
			$parent_id = (int) $row ['parent_id'];
			if ( ! isset( $res[ $parent_id ] ) )
				$res[ $parent_id ] = array( (int) $row['post_id'] );
			else
				$res[ $parent_id ][] = (int) $row['post_id'];
		}
		return $res;
	}

	public function get_post_parent_data( $post_id, $type = null ) {
		// If it doesn't exist, `false` is returned
		global $wpdb;
		$type_cond = (
		is_null( $type ) ?
		'' :
		$wpdb->prepare( ' AND post_table.type=%s', $type )
		);
		$res = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT parents_table.kite_data FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' post_table ' .
				'INNER JOIN ' . WOOKITE_POSTMETA_TABLE_NAME . ' parents_table ' .
				'ON parents_table.post_id = post_table.parent_id ' .
				'WHERE post_table.post_id=%d',
				$post_id
			) .
			$type_cond
		);
		if ( empty( $res ) ) { return false;
		}
		return wookite_maybe_unserialize( $res );
	}

	public function get_post_children_ids( $post_ids, $max_depth = null ) {
		// Returns IDs of all the children (products and variants)
		// parented (directly or indirectly) by `$post_ids`.
		global $wpdb;
		$cnt = 0;
		$parents = array();
		foreach ( $this->get_posts_by_parent( $post_ids ) as $pid => $data ) {
			$parents[ $pid ] = true;
		}
		while ( true ) {
			$parents_ids = array_keys( $parents );
			if ( isset( $max_depth ) && $max_depth <= 0 ) {
				return $this->plugin->pids2pids( $parents_ids );
			}
			$new_cnt = count( $parents_ids );
			if ( $cnt === $new_cnt ) {
				return $this->plugin->pids2pids( $parents_ids );
			}
			$cnt = $new_cnt;
			$max_depth--;
			$children = $wpdb->get_results(
				sprintf(
					'SELECT post_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
					'WHERE parent_id IN (%s)',
					implode( ', ', $parents_ids )
				), ARRAY_A
			);
			foreach ( $children as $child ) {
				$parents[ (int) $child['post_id'] ] = true;
			}
		}
	}

	public function get_post_range_id( $post_id ) {
		global $wpdb;
		$fetched = array();
		while ( true ) {
			$data = $this->get_post_type_data( $post_id );
			if ( $data === false ) { return false;
			}
			$fetched[ $post_id ] = true;
			if ( $data['type'] === 'p' && ! empty( $data['parent_id'] ) ) {
				return (int) $data['parent_id'];
			}
			$post_id = $data['parent_id'];
			if ( isset( $fetched[ $post_id ] ) ) {
				return false; // Inf. loop (corrupted data)
			}
		}
	}

	public function get_posts_by_parent( $parent_ids, $type = null, $limit = null ) {
		global $wpdb;
		$type_cond = (
			isset( $type ) ?
			$wpdb->prepare( ' AND type=%s', $type ) :
			''
		);
		$pids = array();
		foreach ( (array) $parent_ids as $pid ) {
			$pids[] = (int) $pid;
		}
		$posts = $wpdb->get_results(
			sprintf(
				'SELECT post_id, kite_data FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' ' .
				'WHERE parent_id IN (%s)%s ORDER BY post_id ASC',
				implode( ',', $pids ), $type_cond
			),
			ARRAY_A
		);
		$res = array();
		$idx = 0;
		foreach ( $posts as $post ) {
			if ( isset( $limit ) && ++$idx > $limit ) { break;
			}
			$res[ (int) $post['post_id'] ] = wookite_maybe_unserialize( $post['kite_data'] );
		}
		return $res;
	}

	public function get_all_parents_ids( $type ) {
		global $wpdb;
		$type_cond = (
		isset( $type ) ?
		$wpdb->prepare( 'WHERE type=%s', $type ) :
		''
		);
		$res = array();
		$posts = $wpdb->get_results(
			sprintf(
				'SELECT DISTINCT parent_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' %s',
				$type_cond
			),
			ARRAY_A
		);
		return wp_list_pluck( $posts, 'parent_id' );
	}

	public function get_all_posts_ids( $type = null ) {
		// Ensures that the products/variants actually exist in both
		// WP's and WooKite's posts tables.
		global $wpdb;
		$type_cond = (
		isset( $type ) ?
		$wpdb->prepare( 'WHERE kite.type=%s', $type ) :
		''
		);
		$res = array();
		$posts = $wpdb->get_results(
			sprintf(
				'SELECT DISTINCT kite.post_id AS post_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' kite
                INNER JOIN ' . $wpdb->posts . ' wp ON kite.post_id = wp.ID
                %s',
				$type_cond
			),
			ARRAY_A
		);
		$res = array();
		foreach ( $posts as $post ) {
			$res[] = (int) $post['post_id'];
		}
		return $res;
	}

	public function delete_posts_data( $post_ids, $type = null ) {
		global $wpdb;
		$del = array();
		foreach ( (array) $post_ids as $id ) {
			$del[] = (int) $id;
		}
		$where_type = (
		isset( $type ) ?
		$wpdb->prepare( ' AND type=%s', $type ) :
		''
		);
		return $wpdb->get_var(
			sprintf(
				'DELETE FROM ' . WOOKITE_POSTMETA_TABLE_NAME . '
            WHERE post_id IN (%s)%s',
				implode( ',', $del ), $where_type
			)
		);
	}

	public function get_has_only( $post_ids, $type = null ) {
		global $wpdb;
		$pids = array();
		foreach ( (array) $post_ids as $pid ) {
			$pids[ (int) $pid ] = true;
		}
		$pids = array_keys( $pids );
		$type_cond = (
		isset( $type ) ?
		$wpdb->prepare( ' AND type=%s', $type ) :
		''
		);
		$cnt = (int) $wpdb->get_var(
			sprintf(
				'SELECT COUNT(*) FROM ' . WOOKITE_POSTMETA_TABLE_NAME . '
            WHERE post_id IN (%s)%s',
				implode( ', ', $pids ), $type_cond
			)
		);
		return array(
		'has' => ($cnt > 0),
		'only' => ($cnt == count( $pids )),
		);
	}

	public function update_product_data( $product_id, $product_data ) {
		return $this->update_post_data( $product_id, 'p', $product_data );
	}

	public function get_product_data( $product_id ) {
		return $this->get_post_data( $product_id, 'p' );
	}

	public function get_products_data( $product_ids = null, $field = null ) {
		return $this->get_posts_data( $product_ids, $field, 'p' );
	}

	public function get_product_range_id( $product_id ) {
		return $this->get_post_parent_id( $product_id, 'p' );
	}

	public function get_products_ranges_ids( $product_ids ) {
		return $this->get_posts_parents_ids( $product_ids, 'p' );
	}

	public function get_product_range_data( $product_id ) {
		// If it doesn't exist, `false` is returned
		global $wpdb;
		$res = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT parents_table.* FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' post_table
            INNER JOIN ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' parents_table
            ON parents_table.id = post_table.parent_id
            WHERE post_table.post_id=%d AND post_table.type="p"',
				$product_id
			), ARRAY_A
		);
		if ( empty( $res ) ) {
			return false;
		}
		return $this->plugin->api->product_ranges->clean_range_data(
			wookite_maybe_unserialize( $res[0] )
		);
	}

	public function get_products_by_range( $range_ids, $limit = null ) {
		return $this->get_posts_by_parent( $range_ids, 'p', $limit );
	}

	public function get_all_ranges_ids() {
		return $this->get_all_parents_ids( 'p' );
	}

	public function get_all_products_ids() {
		return $this->get_all_posts_ids( 'p' );
	}

	public function delete_products( $product_ids ) {
		$this->delete_posts_data( $product_ids, 'p' );
	}

	public function update_variant_data( $variant_id, $variant_data ) {
		return $this->update_post_data( $variant_id, 'v', $variant_data );
	}

	public function get_variant_data( $variant_id ) {
		return $this->get_post_data( $variant_id, 'v' );
	}

	public function get_variants_data( $variant_ids = null, $field = null ) {
		return $this->get_posts_data( $variant_ids, $field, 'v' );
	}

	public function get_variant_product_id( $variant_id ) {
		return $this->get_post_parent_id( $variant_id, 'v' );
	}

	public function get_variants_products_ids( $variant_ids ) {
		return $this->get_posts_parents_ids( $variant_ids, 'v' );
	}

	public function get_variant_product_data( $variant_id ) {
		return $this->get_post_parent_data( $variant_id, 'v' );
	}

	public function get_variants_by_product( $product_ids, $limit = null ) {
		return $this->get_posts_by_parent( $product_ids, 'v', $limit );
	}

	public function get_all_variants_ids() {
		return $this->get_all_posts_ids( 'v' );
	}

	public function delete_variants( $variant_ids ) {
		$this->delete_posts_data( $variant_ids, 'v' );
	}

	public function deleted_post( $post_id ) {
		$this->posts_to_delete[ $post_id ] = true;
	}

	public function get_related_products_ids( $post_ids ) {
		// Returns IDs of products with IDs in `$post_ids` or
		// having any of the variants with an ID in `$post_ids`.
		global $wpdb;
		$post_ids = array_merge(
			$post_ids,
			array_keys( $this->get_variants_products_ids( $post_ids ) )
		);
		$pids = array();
		foreach ( $post_ids as $pid ) {
			$pids[ (int) $pid ] = true;
		}
		return array_keys(
			$this->get_products_data( array_keys( $pids ), true )
		);
	}

	public function product_is_frozen($post_id) {
		$range_id = $this->get_post_range_id($post_id);
		$range = $this->plugin->api->product_ranges->get_range($range_id);
		return (isset($range['frozen']) && (bool) $range['frozen']);
	}

	public function product_has_back_image($post_id) {
		$variants_data = $this->get_variants_by_product((int) $post_id);
		foreach ($variants_data as $vid=>$variant)
			if (
				isset($variant['back_image']) &&
				isset($variant['back_image']['url_full']) &&
				!empty($variant['back_image']['url_full'])
			)
				return true;
		return false;
	}

	public function dual_image_style_to_variants($dual_image_style=null) {
		$conversion = array(
			'bfsb' => array( 'image', 'back_image' ),
			'bbsf' => array( 'back_image', 'image' ),
			'fo' => array( 'image' ),
			'bo' => array( 'back_image' ),
		);
		if ( is_null( $dual_image_style ) )
			$dual_image_style = $this->plugin->get_option('dual_image_style');
		if (!isset($conversion[$dual_image_style]))
			$dual_image_style = 'bfsb';
		return $conversion[$dual_image_style];
	}

	public function product_image_params($data, $side='image', $print_image=false) {
		static $background = null, $format = null;
		$both_sides = ( $side === 'both_images' );
		$sides = ( $both_sides ? $this->dual_image_style_to_variants() : array( $side ) );
		$idx = 0;
		if (is_null($background) && is_null($format)) {
			$background = '00000000';
			$format = 'png';
			$prod_bg = $this->plugin->get_option('product_images_background');
			if (preg_match('#^[0-9a-f]{6,8}#i', $prod_bg)) {
				if (strlen($prod_bg) == 6) {
					$background = $prod_bg;
					$format = 'jpg';
				} elseif (strlen($prod_bg) == 8) {
					$background = $prod_bg;
				}
			}
		}
		$res = array(
			'product_id' => $data['template_id'],
			'print_image' => ($print_image ? 'true' : 'false'),
		);
		if ($print_image) {
			$res['side'] = ($side == 'image' ? 'Front' : 'Back');
		} else {
			$res = array_merge(
				$res,
				array(
					'background' => $background,
					'format' => $format,
					'product_id' => $data['template_id'],
					'fill_mode' => 'fit',
					'print_image' => ($print_image ? 'true' : 'false'),
				)
			);
		}
		foreach ( $sides as $side ) {
			$name_suffix = ( $both_sides ? "$idx" : '' );
			$image_data = (isset($data[$side]) ? $data[$side] : array());
			if (isset($image_data)) {
				if (isset($image_data['url_full']))
					$image_url = $image_data['url_full'];
				else
					$image_url = '';
			} else {
				$image_url = '';
			}
			$image_data["image$name_suffix"] = $image_url;
			unset($image_data['url_full']);
			unset($image_data['url_preview']);
			if (
				!isset($image_data['translate']) &&
				isset($image_data['tx']) && isset($image_data['ty'])
			) {
				$image_data["translate$name_suffix"] = sprintf(
					'%d,%d',
					(int)$image_data['tx'],
					(int)$image_data['ty']
				);
				unset($image_data['tx']);
				unset($image_data['ty']);
			} elseif ($name_suffix !== '') {
				$image_data["translate$name_suffix"] = $image_data["translate"];
				unset($image_data["translate"]);
			}
			if (!isset($image_data['rotate']) && isset($image_data['rotate_degrees'])) {
				$image_data["rotate$name_suffix"] = $image_data['rotate_degrees'];
				unset($image_data['rotate_degrees']);
			}
			if ($name_suffix !== '' && isset($image_data['scale'])) {
				$image_data["scale$name_suffix"] = $image_data['scale'];
				unset($image_data['scale']);
			}
			if (isset($image_data['mirror']))
				if ($image_data['mirror'])
					$image_data["mirror$name_suffix"] = 'true';
				else
					unset($image_data['mirror']);
			$variant = implode(',', $data['image_variants']);
			if ( $side === 'back_image' ) {
				$variant = (
					$variant === 'cover' ?
					'back' :
					'back_' . $variant
				);
			}
			$image_data["variant$name_suffix"] = $variant;
			$res = array_merge($res, $image_data);
			$idx++;
		}
		if (!$print_image) $res['size'] = '';
		return $res;
	}

	public function pig_image($params, $side='image', $print_image=false) {
		$ok = (
			$side === 'both_images' ?
			isset($params['image']) && isset($params['back_image']) :
			isset($params[$side])
		);
		if ($ok) {
			return (
				'https://image.kite.ly/render/?' .
				http_build_query(
					$this->product_image_params($params, $side, $print_image),
					'',
					'&'
				)
			);
		} else {
			return '';
		}
	}

	public function post_id2data( $post_id ) {
		static $img_types = array('image', 'back_image');
		static $post_data = array();
		if ( empty( $post_data[$post_id] ) ) {
			$data = $this->get_post_type_data($post_id);
			if ($data === false) return null;
			if ($data['type'] === 'p') {
				$product_id = $post_id;
				$data = $this->get_variants_by_product(array($post_id));
				$defaults = wookite_maybe_unserialize(
					get_post_meta( $post_id, '_default_attributes', true )
				);
				$ok = false;
				if ( is_array( $defaults ) )
					foreach ( $data as $vid=>$kite_data ) {
						$ok = true;
						foreach ( $defaults as $key=>$value ) {
							$attr_value = get_post_meta(
								$vid, 'attribute_' . $key, true
							);
							if ( $attr_value !== $value ) {
								$ok = false;
								break;
							}
						}
						if ( $ok ) {
							$post_id = $vid;
							$data = $kite_data;
							break;
						}
					}
				if ( $ok === false ) {
					$data_keys = array_keys($data);
					$post_id = $data_keys[0];
					$data = $data[$post_id];
				}
			} else {
				$product_id = $data['parent_id'];
				$data =& $data['kite_data'];
			}
			$image_variants = $data['image_variants'];
			if (empty($image_variants))
				$image_variants = array('cover');
			$template_id = $data['template_id'];
			$product_data = $this->get_variant_product_data($post_id);
			if (empty($template_id)) {
				if (!is_array($product_data)) return null;
				$template_id = $product_data['template_id'];
			}
			if (empty($template_id)) return null;
			$enabled = ! (
				( isset( $data['enabled'] ) && $data['enabled'] === false ) ||  # variant disabled
				( isset( $product_data['enabled'] ) && $product_data['enabled'] === false )  # product disabled
			);
			$data_images = array();
			foreach ($img_types as $img_type) {
				if (!isset($data[$img_type])) continue;
				$data_images[$img_type] = $data[$img_type];
			}
			$data = array_merge(
				array(
					'enabled' => $enabled,
					'image_variants' => $image_variants,
					'template_id' => $template_id,
				),
				$data_images
			);
			$data['product_image'] = $this->pig_image($data);
			if ( $this->product_has_back_image( $product_id ) ) {
				$data['product_back_image'] = $this->pig_image($data, 'back_image');
				$data['product_both_image'] = $this->pig_image($data, 'both_images');
				$data['product_single_image'] = $data['product_both_image'];
			} else
				$data['product_single_image'] = $data['product_image'];
			$data['title'] = get_the_title($post_id);
			$post_data[$post_id] = $data;
		}
		return $post_data[$post_id];
	}

	public function response( $code, $msg, $extra_data = null ) {
		$res = array(
		'code' => $code,
		'message' => $msg,
		);
		if ( is_array( $extra_data ) ) {
			foreach ( $extra_data as $var => $value ) {
				$res[ $var ] = $value;
			}
		}
		return $res;
	}

	public function ago2human( $interval ) {
		static $lengths = array(
		'sec' => 60,
		'min' => 60,
		'hr' => 24,
		'day' => 1,
		);
		if ( $interval < 0 ) {
			$interval = -$interval;
			$format = 'in %d %s';
		} else {
			$format = '%d %s ago';
		}
		if ( $interval <= 1 ) { return 'just now';
		}
		foreach ( $lengths as $desc => $len ) {
			if ( $interval < $len ) { break;
			}
			$interval /= $len;
		}
		$interval = floor( $interval );
		if ( $interval > 1 ) { $desc .= 's';
		}
		return sprintf( $format, $interval, __( $desc, 'wookite' ) );
	}

	protected function get_kite_order_data( $order_id, $which_data = null ) {
		global $wpdb;
		$oid = $this->plugin->get_order_id( $order_id );
		$data = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . WOOKITE_ORDERS_TABLE_NAME . ' WHERE order_id=%d',
				$oid
			), ARRAY_A
		);
		if ( empty( $which_data ) ) {
			$which_data = array_keys( $data );
		}
		if ( is_array( $which_data ) ) {
			$res = array();
			foreach ( $which_data as $wd ) {
				if ( isset( $data[ $wd ] ) ) {
					$res[ $wd ] = wookite_maybe_unserialize( $data[ $wd ] );
				}
			}
			return $res;
		}
		return wookite_maybe_unserialize( $data[ $which_data ] );
	}

	public function get_order_data( $order, $full_status_format = null ) {
		if ( ! isset( $full_status_format ) ) {
			$full_status_format = sprintf(
				' (%s: <span class="wookite last_status_update">%%s</span>)',
				__( 'last update', 'wookite' )
			);
		}
		$order = $this->plugin->get_order( $order );
		$oid = $this->plugin->get_order_id( $order );
		if ( ! $order ) {
			return false;
		}
		$order_data = $this->get_kite_order_data( $oid, 'kite_data' );
		$items = $order->get_items();
		$has_kite = null;
		$only_kite = null;
		$vids = array();
		foreach ( $items as $item ) {
			$vid = $item->get_variation_id();
			if ( ! empty( $vid ) ) {
				$vids[] = $vid;
			} else {
				$only_kite = false;
			}
		}
		if ( ! $vids ) {
			$has_kite = false;
			if ( ! isset( $only_kite ) ) { $only_kite = false;
			}
		}
		if ( ! isset( $has_kite ) ) {
			$has_only = $this->get_has_only( $vids );
			if ( ! isset( $has_kite ) ) { $has_kite = $has_only['has'];
			}
			if ( ! isset( $only_kite ) ) { $only_kite = $has_only['only'];
			}
		}
		$me = $this->plugin->me();
		$as = $this->account_status( array( 'ok', 'live', 'err' ) );
		$res = array(
		'working' => $as['ok'],
		'has_kite' => $has_kite,
		'only_kite' => $only_kite,
		'liveable_test_order' => false,
		);
		if ( $as['ok'] ) {
			$res['live'] = $as['live'];
		} else {
			$res['working-problem'] = $as['err'];
		}
		if ( is_array( $order_data ) and isset( $order_data['order_id'] ) ) {
			$res['sent_to_kite'] = true;
			$res['order_data'] = $order_data;
			// Was the order test order with the current system being live?
			// If yes, it makes sense to be able to resubmit it.
			$res['liveable_test_order'] = ($order_data['test_order'] && $res['live']);
			$items_statuses = array();
			foreach ( (array) $order_data['jobs'] as $job ) {
				$status = $job['status'];
				if ( empty( $status ) ) { continue;
				}
				if ( isset( $items_statuses[ $status ] ) ) {
					$items_statuses[ $status ]++;
				} else {
					$items_statuses[ $status ] = 1;
				}
			}
			$res['items_statuses'] = $items_statuses;
			$res['kite_id'] = $order_data['order_id'];
			$res['done'] = (isset( $order_data['done'] ) && $order_data['done']);
			$res['status'] = (
			empty( $order_data['status'] ) ?
			$this::$order_statuses[0] :
			$order_data['status']
			);
			$ft = $res['order_data']['fetch_time'];
			$ago_text = (
			isset( $ft ) && $ft > 0 ?
			sprintf(
				$full_status_format,
				$this->ago2human( time() - $ft )
			) :
			''
			);
			$res['full_status'] = sprintf(
				'<span class="wookite order status %s">%s</span>%s',
				wookite_slugify( $res['status'] ),
				$res['status'],
				$ago_text
			);
		} else {
			$res['sent_to_kite'] = false;
			$res['done'] = false;
			$res['full_status'] = $this->order_status_not_sent;
		}
		return $res;
	}

	public function where_autoupdate_statuses( $all = false ) {
		return (
		$all ?
		'' :
		' AND (
				(kite_status = "Processed" AND time_updated < NOW() - INTERVAL 1 HOUR) OR
				(kite_status <> "Processed" AND time_updated < NOW() - INTERVAL 10 MINUTE)
			)'
		);
	}

	public function cnt_autoupdate_statuses( $all = false, $where = null ) {
		global $wpdb;
		if ( ! isset( $where ) ) {
			$where = $this->where_autoupdate_statuses( $all );
		}
		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . WOOKITE_ORDERS_TABLE_NAME . ' ' .
			'WHERE done=0 AND kite_id IS NOT NULL' . $where
		);
	}

	public function autoupdate_statuses( $all = false ) {
		global $wpdb;
		$offset = 0;
		$prids = array();
		$where = $this->where_autoupdate_statuses( $all );
		if ( $all ) {
			$cnt = ceil( $this->cnt_autoupdate_statuses( $all, $where ) / 100 );
			$this->plugin->set_max_execution_time( 17 * $cnt );
		} else {
			$cnt = 1;
		}
		for ( $i = 0; $i < $cnt; $i++ ) {
			$need_update = $wpdb->get_results(
				'SELECT order_id, kite_id, kite_data FROM ' . WOOKITE_ORDERS_TABLE_NAME . '
				WHERE done=0 AND kite_id IS NOT NULL' . $where . '
				ORDER BY time_updated ASC
				LIMIT 100',
				ARRAY_A
			);
			$ids = array();
			foreach ( $need_update as $data ) {
				$ids[ $data['kite_id'] ] = $data['order_id'];
			}
			$resp = $this->request(
				sprintf(
					'order/?order_id__in=%s&limit=1000',
					implode( ',', array_keys( $ids ) )
				)
			);
			if ( is_array( $resp ) && is_array( $resp['json'] ) && is_array( $resp['json']['objects'] ) ) {
				$now = time();
				foreach ( $resp['json']['objects'] as $object ) {
					$kite_id = $object['order_id'];
					$order_id = $ids[ $kite_id ];
					if ( ! (empty( $order_id ) || empty( $kite_id )) ) {
						$object['fetch_time'] = $now;
						$this->update_kite_data( $order_id, $object );
					}
				}
			}
		}
	}

	public function ids_autosend_orders() {
		global $wpdb;
		return $wpdb->get_col(
			'SELECT wp.ID FROM (
				SELECT ID FROM ' . $wpdb->posts . '
					WHERE post_type="shop_order" AND post_status="wc-processing"
			) wp
			INNER JOIN ' . WOOKITE_ORDERS_TABLE_NAME . ' kite
				ON wp.ID=kite.order_id
			WHERE
				kite.done=0 AND
				(
					kite.kite_id IS NULL OR kite.kite_id="" OR
					kite.kite_status IS NULL OR kite.kite_status IN ("", "Error") OR
					kite.status_code IS NULL OR kite.status_code < 200 OR kite.status_code >= 300
				) AND
				kite.time_updated < NOW() - INTERVAL 1 MINUTE'
		);
	}

	public function cnt_autosend_orders() {
		global $wpdb;
		return count( $this->ids_autosend_orders() );
	}

	public function autosend_orders() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$oids = $this->ids_autosend_orders();
		if ( $oids ) {
			$this->plugin->set_max_execution_time( 17 * count( $oids ) );
			$qres = $wpdb->query(
				sprintf(
					'UPDATE ' . WOOKITE_ORDERS_TABLE_NAME . ' ' .
					'SET time_updated=NOW() ' .
	                'WHERE order_id IN (%s)',
					implode( ',', $oids )
				)
			);
			if ( $qres === false ) {
				$wpdb->query( 'ROLLBACK' );
			} else {
				$wpdb->query( 'COMMIT' );
				foreach ( $oids as $oid ) {
					$this->send_order_to_kite( $oid );
				}
			}
		} else {
			$qres = false;
			$wpdb->query( 'ROLLBACK' );
		}
	}

	public function get_insertion_index( $column_keys, $picks, $default = null ) {
		foreach ( $picks as $pick ) {
			$p = array_search( $pick, $column_keys );
			if ( $p !== false ) { return $p;
			}
		}
		if ( isset( $default ) ) {
			return $default;
		} else {
			return count( $column_keys );
		}
	}

	public function show_products_column( $columns ) {
		$p = $this->get_insertion_index(
			array_keys( $columns ),
			array( 'featured', 'product_type', 'date' )
		);
		$columns =
			array_slice( $columns, 0, $p, true ) +
			array(
				'wookite' => sprintf(
					'<span class="wookitext tips" data-tip="Kite %s?">?</span>',
					__( 'product', 'wookite' )
				),
			) +
			array_slice( $columns, $p, null, true );
		$this->products_data = $this->get_products_data(null, 'product_id');
		$this->plugin->set_max_execution_time(1719);
		return $columns;
	}

	public function products_column( $column, $pid ) {
		if ( $column == 'wookite' and isset( $this->products_data[ $pid ] ) !== false ) {
			$hint = sprintf( 'Kite %s', __( 'product', 'wookite' ) );
			if ( defined( 'WOOKITE_DEV' ) )
				$hint .= ': ' . $this->products_data[ $pid ];
			printf(
				'<span class="wookitext tips wookite-in-list" data-tip="%s">K</span>',
				$hint
			);
		}
	}

	public function show_order_column( $columns ) {
		$p = $this->get_insertion_index(
			array_keys( $columns ),
			array( 'order_actions', 'customer_message', 'order_notes' )
		);
		$columns =
		array_slice( $columns, 0, $p, true ) +
		array(
		'wookite' => sprintf(
			'<span class="wookitext tips" data-tip="%s">?</span>',
			sprintf( __( 'Summaries for the orders containing %s items', 'wookite' ), 'Kite' )
		),
		) +
		array_slice( $columns, $p, null, true );
		return $columns;
	}

	public function order_column( $column, $oid ) {
		if ( $column === 'wookite' ) {
			$order = new WC_Order( $oid );
			$kite_info = $this->get_order_data(
				$order,
				sprintf( '<br />%s: %%s', __( 'Last update', 'wookite' ) )
			);
			if ( $kite_info['has_kite'] ) {
				if ( ! $kite_info['working'] ) {
					$kite_status = '<div class="wookite-order-kite-status wookite-warning">' . __( 'Kite is not set up!', 'wookite' ) . '</div>';
				} elseif ( ! $kite_info['live'] ) {
					$kite_status = '<div class="wookite-order-kite-status wookite-test">' . __( 'Kite is in test mode.', 'wookite' ) . '</div>';
				} else {
					$kite_status = '';
				}
					$order_status = sprintf(
						'<div class="wookite-order-title">%s</div><div class="wookite-order-status">Kite items: %s</div>%s%s',
						__(
							(
							$kite_info['only_kite'] ?
							'Kite-only order' :
							'Some Kite items here'
							),
							'wookite'
						),
						$kite_info['full_status'],
						$this->profit_table( $order, $kite_info ),
						$kite_status
					);
					printf(
						'<span data-tip="%s" class="wookitext wookite-in-list tips wookite-order-k wookite-order-%s">K</span>',
						esc_attr( $order_status ),
						($kite_info['only_kite'] ? 'all' : 'some')
					);
			}
		}
	}

	public function add_admin_order_action( $actions, $order ) {
		$kite_info = $this->get_order_data(
			$order,
			sprintf( '<br />%s: %%s', __( 'Last update', 'wookite' ) )
		);
		// If account's not right, the buttons are useless
		if ( ! $kite_info['working'] ) { return $actions;
		}
		// Maybe make some buttons if the order contains Kite items
		if ( $kite_info['has_kite'] ) {
			$has_order_data = isset( $kite_info['order_data'] );
			if ( $has_order_data && $kite_info['order_data']['done'] && ! $kite_info['liveable_test_order'] ) {
				return $actions;
			}
			// Determine classes and extra text, depending on
			if ( ! $kite_info['live'] ) {
				$hint_extra = '<br /><span class="wookite-test">' . __( 'Kite is in test mode.', 'wookite' ) . '</span>';
				$kite_status = 'testkite';
			} else {
				$hint_extra = '';
				$kite_status = 'yeskite';
			}
			// Make buttons
			if ( !isset( $kite_info['kite_id'] ) || empty( $kite_info['kite_id'] ) || $kite_info['liveable_test_order'] ) {
				$actions['wookite_query'] = array(
					'url' => wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wookite_send_order_to_kite&order_id=' .
							$this->plugin->get_wc_id($order)
						),
						'wookite_send_order_to_kite'
					),
					'name' => (
						empty( $kite_info['kite_id'] ) ?
						esc_attr( __( 'Send order to Kite', 'wookite' ) . $hint_extra ) :
						esc_attr(
							__(
								'Resend this order to Kite<br />(it was already sent, but only as a test order)',
								'wookite'
							) . $hint_extra
						)
					),
					'action' => 'wookite send ' . $kite_status,
				);
			} else {
				$actions['wookite_send'] = array(
					'url' => wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wookite_get_order_status&order_id=' .
							$this->plugin->get_wc_id($order)
						),
						'wookite_get_order_status'
					),
					'name' => esc_attr(
						sprintf(
							'Kite %s: %s%s<br />%s',
							__( 'order status', 'wookite' ),
							$kite_info['full_status'],
							$hint_extra,
							__( 'Click to reload the status' )
						)
					),
					'action' => 'wookite query ' . wookite_slugify( $kite_info['status'] ),
				);
			}
		}
		return $actions;
	}

	public function add_order_status( $order ) {
		$kite_info = $this->get_order_data( $order );
		if ( $kite_info['has_kite'] ) {
			printf(
				'<p class="wookite form-field form-field-wide wc-customer-user"><span class="wookitext">K</span> Kite %s: ',
				__( 'order status', 'wookite' )
			);
			$format = '<span id="wookite_order_status">%s</span> <span class="button" data-url="%s" data-working-text="%s" id="wookite_order_status_button">%s</a>';
			if ( empty( $kite_info['status'] ) || $kite_info['status'] === 'Error' || $kite_info['liveable_test_order'] ) {
				$v2 = sprintf(
					' data-url2="%s" data-wt2="%s" data-desc2="%s"',
					wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wookite_get_order_status&order_id=' .
							$this->plugin->get_wc_id($order) .
							'&output=new'
						),
						'wookite_get_order_status'
					),
					sprintf( __( 'Fetching %s order status', 'wookite' ), 'Kite' ),
					__( 'Update now', 'wookite' )
				);
				if ( empty( $kite_info['status'] ) ) {
					$send_btn_text = __( 'Send now', 'wookite' );
				} elseif ( $kite_info['status'] === 'Error' ) {
					$send_btn_text = __( 'Resend now', 'wookite' );
				} else {
					$send_btn_text = __( 'Resend now as a live order', 'wookite' );
				}
				printf(
					$format,
					(empty( $kite_info['status'] ) ? __( $this->order_status_not_sent, 'wookite' ) : $kite_info['full_status']),
					wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wookite_send_order_to_kite&order_id=' .
							$this->plugin->get_wc_id($order) .
							'&output=new'
						),
						'wookite_send_order_to_kite'
					),
					__( 'Sending order to', 'wookite' ) . ' Kite',
					$send_btn_text
				);
			} else {
				printf(
					$format,
					$kite_info['full_status'],
					wp_nonce_url(
						admin_url(
							'admin-ajax.php?action=wookite_get_order_status&order_id=' .
							$this->plugin->get_wc_id($order) .
							'&output=new'
						),
						'wookite_get_order_status'
					),
					__( 'Fetching Kite order status', 'wookite' ),
					__( 'Update now', 'wookite' )
				);
			}
			echo '</p>';
		}
	}

	public function add_order_item_column( $order ) {
		$this->kite_info = $this->get_order_data( $order );
		$this->items_data = $this->get_kite_order_data( $order, 'items' );
		$this->order = $order;
		if ( $this->kite_info['has_kite'] ) {
			echo '<th class="wookitext">?</th>';
		}
	}

	public function add_order_item_data( $product, $item, $item_id ) {
		if ( $this->kite_info['has_kite'] ) {
			if (
				isset( $product ) && (
					$this->kite_info['only_kite'] ||
					$this->get_product_data( $this->plugin->get_wc_id( $product ) ) !== false
				)
			) {
				$idx = $this->items_data['woo2idx'][ $item_id ];
				$item['item_idx'] = $idx;
				$job_id = $this->items_data['all'][ $idx ]['job_id'];
				$item['job_id'] = $job_id;
				$this->kite_items[] = $item;
				$tip = sprintf(
					'%s<br />%s: %s',
					esc_attr( 'Kite ' . __( 'product', 'wookite' ) ),
					esc_attr( __( 'Status', 'wookite' ) ),
					esc_attr(
						wookite_mb_strtolower(
							empty( $this->kite_info['order_data']['jobs'][ $idx ]['status'] ) ?
							$this->item_status_not_sent :
							__(
								$this->kite_info['order_data']['jobs'][ $idx ]['status'],
								'wookite'
							)
						)
					)
				);
				$prefix = $suffix = '';
				if ( isset( $this->kite_info['order_data']['jobs'][$idx] ) ) {
					$order_data = $this->kite_info['order_data'];
					$job = $order_data['jobs'][$idx];
					if ( isset( $job['carrier_tracking_url'] ) ) {
						$prefix .= sprintf(
							'<a href="%s" style="text-decoration: inherit;">',
							$job['carrier_tracking_url']
						);
						$suffix = '</a>' . $suffix;
						$tip .= sprintf('<br />%s', __('Click for tracking info', 'wookite'));
					}
				}
				printf(
					'<td>%s<span class="wookitext tips wookite-big" ' .
					'data-tip="%s">K</span>%s</td>',
					$prefix,
					$tip,
					$suffix
				);
			} else {
				echo '<td></td>';
			}
		}
	}

	public function profit_table( $order, $kite_info = null, $kite_items = null ) {
		$order = $this->plugin->get_order( $order );
		$currency = (
			method_exists($order, 'get_currency') ?
			$order->get_currency() :
			$order->get_order_currency()
		);
		if ( empty( $kite_info ) ) {
			$kite_info =& $this->get_order_data( $order );
		}
		if ( ! isset( $kite_items ) ) {
			$kite_items = array();
			$pids = array();
			$items = $order->get_items();
			foreach ( $items as $id => $item ) {
				$pids[] = (int) $item->get_product_id();
				$pids[] = (int) $item->get_variation_id();
			}
			$pvs = $this->get_posts_data( $pids, true );
			foreach ( $items as $id => $item ) {
				if (
					isset( $pvs[ (int) $item->get_product_id() ] ) &&
					isset( $pvs[ (int) $item->get_variation_id() ] )
				) {
					$kite_items[] = $item;
				}
			}
		}
		$od =& $kite_info['order_data'];
		if ( $od['status'] !== 'Processed' ) { return '';
		}
		$res = '';
		$res .= '<table class="wookite-order-totals" cellspacing="0" cellpadding="0"><tbody>';
		$res .= sprintf( '<tr><td class="label">%s</td><td class="total">%s</td></tr>', __( 'Wholesale', 'wookite' ), $od['base_product_cost']['formatted'] );
		$res .= sprintf( '<tr><td class="label">%s</td><td class="total">%s</td></tr>', __( 'Shipping', 'wookite' ), $od['shipping_cost']['formatted'] );
		$res .= sprintf( '<tr><td class="label">%s</td><td class="total">%s</td></tr>', __( 'Taxes', 'wookite' ), $od['vat_due']['formatted'] );
		$res .= sprintf( '<tr class="total"><td class="label">%s:</td><td class="total">%s</td></tr>', __( 'You pay', 'wookite' ), $od['total_to_reconcile']['formatted'] );
		$res .= '<tr><td colspan="2" class="overline"></td></tr>';
		$items_cost = 0;
		foreach ( $kite_items as $item ) {
			$items_cost += (float) $item['line_total'] + (float) $item['line_tax'];
		}
		$res .= sprintf(
			'<tr><td class="label">%s</td><td class="total">%s</td></tr>', __( 'Customer items', 'wookite' ),
			wc_price( $items_cost, array( 'currency' => $currency ) )
		);
		$items_shipping = $order->get_total_shipping()
		- $order->get_total_shipping_refunded();
		$res .= sprintf(
			'<tr><td class="label">%s%s</td><td class="total">%s</td></tr>',
			__( 'Customer shipping', 'wookite' ),
			($kite_info['only_kite'] ? '' : '*'),
			wc_price(
				min( $items_shipping, $od['shipping_cost']['amount'] ),
				array( 'currency' => $currency )
			)
		);
		$customer_total = $items_cost + $items_shipping;
		$res .= sprintf(
			'<tr class="total"><td class="label">%s</td><td class="total">%s</td></tr>',
			__( 'Customer paid', 'wookite' ),
			wc_price(
				$customer_total,
				array( 'currency' => $currency )
			)
		);
		$res .= '<tr><td></td></tr>';
		if ( $od['total_to_reconcile']['currency'] == $currency ) {
			$res .= sprintf(
				'<tr class="total"><td class="label overline">%s:</td><td class="total">%s</td></tr>',
				__( 'Your profit', 'wookite' ),
				wc_price(
					$customer_total - $od['total_to_reconcile']['amount'],
					array( 'currency' => $currency )
				)
			);
		}
		$res .= '</tbody></table>';
		// TODO - disclaimer?
		return $res;
	}

	public function add_order_details( $order_id ) {
		if ( $this->kite_info['has_kite'] ) {
			$ki =& $this->kite_info;
			$od =& $ki['order_data'];
			$processed = ($od['status'] === 'Processed');
			echo '<tr class="wookite-order-totals">';
			echo '<td><span class="wookitext wookite-order-totals-logo">K</span></td>';
			echo '<td colspan="6" class="wookite-order-totals"><h4>';
			printf(
				'Kite %s%s (Kite %s)',
				__( 'order', 'wookite' ),
				(
				empty( $od['order_id'] ) ?
				'' :
				sprintf( ' #<span id="kite_order_id">%s</span>', $od['order_id'] )
				),
				__( 'items only', 'wookite' )
			);
			echo '</h4>';
			if ( $processed ) {
				echo '<table class="wookite-columns" cellspacing="0" cellpadding="0"><tbody><tr><td>';
				echo $this->profit_table( $this->order, $ki );
				echo '</td><td></td><td>';
				if ( $ki['items_statuses'] ) {
					printf( '<div id="wookite_statuses"><span id="wookite_statuses_title">%s</span>:<ul class="wookite-ul">', __( 'Number of tems per status', 'wookite' ) );
					foreach ( $ki['items_statuses'] as $status => $cnt ) {
						printf( '<li>%s: %d</li>', __( $status, 'wookite' ), $cnt );
					}
					echo '</ul></div>';
				}
					echo '</td></tr></table>';
				if ( ! $ki['only_kite'] ) {
					printf(
						'<table cellspacing="0" cellpadding="0" border="0" class="wookite-disclaimer"><tbody><tr><td>*</td><td>%s</td></tr></tbody></table>',
						__(
							'The order contains products that were not created by this plugin. This means that, depending on your shipping settings, the profit calculation might be incorrect, as we cannot account for what proportion of customer shipping costs/discounts were applied to which product.',
							'wookite'
						)
					);
				}
			} else {
					echo '<p>', __( 'This section will become available after the Kite order is processed.', 'wookite' ), '</p>';
			}
			echo '</td></tr>';
		}
	}

	protected function resp2me( $resp ) {
		$status_code = wp_remote_retrieve_response_code( $resp );
		$res = array(
		'registered' => false,
		);
		if ( 200 <= $status_code && $status_code <= 299 ) {
			// All is well and jolly!
			$res = array(
			'registered' => true,
			'account' => $resp['json'],
			);
		} else {
			$res['fail'] = array(
			'code' => $status_code,
			'message' => wp_remote_retrieve_response_message( $resp ),
			);
			if ( $status_code == 409 ) {
				$res['fail']['reason'] = 'mail';
			}
		}
		return $res;
	}

	// Adapted from http://stackoverflow.com/a/1837443/1667018
	public function random_password(
		$length = 17,
		$chars = ',./<>?;:|[]{}=-)(*&^%$#@!`~abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
	) {
		$count = strlen( $chars );
		for ( $i = 0, $result = ''; $i < $length; $i++ ) {
			$index = rand( 0, $count - 1 );
			$result .= substr( $chars, $index, 1 );
		}
		return $result;
	}

	public function autoregister( $email = null ) {
		if ( empty( $email ) ) {
			$email = get_option( 'admin_email', '' );
		}
		$full_name = get_option( 'blogname', '' );
		if ( empty( $full_name ) ) {
			$full_name = 'YAWKU Inc.'; // Yet Another WooKite User :-)
		}
		$signup_data = array(
			'full_name' => $full_name,
			'email' => $email,
			'phone_number' => '5555483', // 555-kite
			'company_name' => $full_name,
			'password' => $this->random_password(),
			'acquisition_source' => 'WooKite',
			'country' => get_option( 'woocommerce_default_country', 'GB' ),
		);
		if ( ! defined( 'WOOKITE_UNIT_TESTING' ) ) {
			$signup_data['confirmation_url'] = admin_url(
				'admin.php?page=wookite-plugin&endpoint=me&action=relink' .
				'&email={email}&token={token}'
			);
			$signup_data['app'] = 'wookite';
		}
		$resp = $this->request(
			'signup/', array(
			'data' => $signup_data,
			'ignore-keys' => true,
			)
		);
		return $this->resp2me( $resp );
	}

	public function fetch_me() {
		if ( empty( $_GET['email'] ) || empty( $_GET['token'] ) ) {
			return $this->resp2me( $this->request( 'user/me/' ) );
		} else {
			// No need to sanitize these; the server will worry about them
			return $this->resp2me(
				$this->request(
					sprintf(
						'user/me/?username=%s&token=%s',
						$_GET['email'],
						$_GET['token']
					)
				)
			);
		}
	}

	public function images2assets(
		$front_image_field, $front_image_url,
		$back_image_field, $back_image_url
	) {
		$res = array();
		if ( ! empty( $front_image_url ) ) {
			$res[$front_image_field] = $front_image_url;
		}
		if ( ! empty( $back_image_url ) ) {
			$res[$back_image_field] = $back_image_url;
		}
		return $res;
	}

	public function kite_order_image($variation_meta, $side) {
		if (!isset($variation_meta[$side])) return '';
		if (!isset($variation_meta[$side]['url_full'])) return '';
		if (empty($variation_meta[$side]['url_full'])) return '';
		return $this->pig_image(
			$variation_meta,
			$side,
			true
		);
	}

	public function get_garment_size( $size ) {
		$size = strtolower( $size );
		if ( in_array( $size, array( "small", "medium", "large", "extra large", ) ) ) {
			return ( $size[0] == 'e' ? 'xl' : $size[0] );
		}
		if (preg_match('#(\d+)-(\d+)[_\s]+years#i', $size, $reOut))
			return sprintf('%sto%s', $reOut[1], $reOut[2]);
		return $size;
	}

	protected function get_job_shipping_class( $template_id, $shipping_zone, $shipping_tracked ) {
		$kite_zones = $this->plugin->get_config( 'shipping_classes' );
		foreach ( $kite_zones as $zone )
			if ( $zone['template_id'] == $template_id && $zone['prices'][$shipping_zone] )
				foreach ( $zone['prices'][$shipping_zone] as $cost )
					if ( $shipping_tracked === $cost['tracked'] )
						return $cost['id'];
				foreach ( $zone['prices'][$shipping_zone] as $cost )
					return $cost['id'];
		return null;
	}

	public function send_order_to_kite( $order ) {
		$order = $this->plugin->get_order( $order );
		if ( ! $order ) {
			return $this->response( 1, 'Order does not exist' );
		}
		$kite_info = $this->get_order_data( $order );
		if ( $kite_info['sent_to_kite'] && ! $kite_info['liveable_test_order'] ) {
			return $this->response( 2, 'Kite part of this order was already sent to Kite' );
		}
		if ( ! $kite_info['has_kite'] ) {
			return $this->response( 3, 'No Kite items in this order' );
		}
		$oid = $this->plugin->get_order_id( $order );
		if ( $oid === false ) {
			return $this->response( 4, 'WooCommerce error: order does not have an ID' );
		}

		// Recipient name
		$shipping_first_name = implode( ' ', get_post_meta( $oid, '_shipping_first_name' ) );
		$shipping_last_name = implode( ' ', get_post_meta( $oid, '_shipping_last_name' ) );
		if ( $shipping_first_name ) {
			if ( $shipping_last_name ) {
				$recipient_name = sprintf( '%s %s', $shipping_first_name, $shipping_last_name );
			} else {
				$recipient_name = $shipping_first_name;
			}
		} else {
			$recipient_name = $shipping_last_name;
		}
		$shipping_company = implode( ' ', get_post_meta( $oid, '_shipping_company' ) );
		if ( ! $recipient_name ) {
			$recipient_name = $shipping_company;
		} elseif ( $shipping_company ) {
			$recipient_name = sprintf( '%s (%s)', $shipping_company, $recipient_name );
		}
		if ( empty( $recipient_name ) ) {
			return $this->response( 5, 'Unknown address' );
		}

		// Proper ISO3 country code
		$country = get_post_meta( $oid, '_shipping_country', true );
		$country = $this->plugin->country_2to3( $country );
		if ( $country === false ) {
			return $this->response( 6, 'Unknown country' );
		}

		// Let's grab the shipping method ID
		$shipping_zone = null;
		$shipping_methods = $order->get_shipping_methods();
		if ( $shipping_methods ) {
            $shipping_method = @array_shift( $shipping_methods );
			$shipping_method_id = $this->plugin->get_wc_obj_value(
				$shipping_method, 'get_method_id', 'method_id'
			);
			if ( preg_match( '#^flat_rate:(\d+)$#', $shipping_method_id, $reOut) ) {
				$all_shipping_methods = $this->plugin->get_option( 'shipping-methods' );
				$shipping_method_id = (int)$reOut[1];
				if ( isset( $all_shipping_methods[$shipping_method_id] ) ) {
					$shipping_zone = $all_shipping_methods[$shipping_method_id]['kite_zone_code'];
					$shipping_tracked = $all_shipping_methods[$shipping_method_id]['tracked'];
				}
			}
		}

		// Jobs & payment
		$amount = 0.0;
		$jobs = array();
		$items = $order->get_items();
		$kite_items = array();
		foreach ( $items as $id => $item ) {
			$product_meta = $this->get_product_data( $item->get_product_id() );
			$variation_meta = $this->get_variant_data( $item->get_variation_id() );
			if ( $product_meta !== false && $variation_meta !== false ) {
				$amount +=
					(float) $item['line_total'] + (float) $item['line_tax'];
				$template_id = $variation_meta['template_id'];
				$product_id = $product_meta['product_id'];
				$image_url = $this->kite_order_image($variation_meta, 'image');
				$back_image_url = $this->kite_order_image($variation_meta, 'back_image');
				$job = (
					isset( $variation_meta['job'] ) ?
					$variation_meta['job'] :
					array(
						'assets' => array( $image_url ),
						'template_id' => $template_id,
					)
				);
				$job['multiples'] = max( (int) $item['qty'], 1 );
				if (isset($shipping_zone))
					$job['shipping_class'] = $this->get_job_shipping_class(
						$template_id, $shipping_zone, $shipping_tracked
					);
				$pmo =& $product_meta['options'];
				$vmo =& $variation_meta['options'];
				$options = array();
				$opt_cnt = min( count( $pmo ), count( $vmo ) );
				for ( $i = 0; $i < $opt_cnt; $i++ ) {
					$options[ strtolower( $pmo[ $i ] ) ] = strtolower( strtr( $vmo[ $i ], ' ', '_' ) );
				}
				if ( $product_id === 'phone_cases' ) {
					$job['options'] = array(
						'case_style' => $options['finish'],
					);
				} elseif ( $product_id === 'magnet_frame' ) {
					$job['options'] = array(
						'magnet_frame_style' => $options['color'],
					);
				} elseif ( $product_id === 'flip_flops' ) {
					$job['options'] = array(
						'garment_size' => $this->get_garment_size( $options['size'] ),
					);
				} elseif ( in_array(
					$product_id,
					array(
						'awd_sublimation_tshirt',
						'aa_sublimation_tshirt',
						'roly_sublimation_tshirt',
						'aa_sublimation_vest',
						'awd_kids_sublimation_tshirt',
					)
				) ) {
					$job['options'] = array(
						'garment_size' => $this->get_garment_size( $options['size'] ),
					);
					$job['assets'] = $this->images2assets(
						'front_image', $image_url,
						'back_image', $back_image_url
					);
				} elseif ( $product_id === 'twill_tote_bag' ) {
					$job['options'] = array(
						'garment_color' => $color,
					);
					$job['assets'] = $this->images2assets(
						'front_image', $image_url,
						'back_image', $back_image_url
					);
				} elseif ( $product_id === 'sublimation_tote_bag' ) {
					$job['assets'] = $this->images2assets(
						'front_image', $image_url,
						'back_image', $back_image_url
					);
				} elseif (
					in_array(
						$product_id,
						array(
							'hoodie',
							'hoodie_aa',
							'aa_fleece_pullover_hoodie',
							'gildan_hooded_sweatshirt',
							'awd_hooded_sweatshirt',
							'tshirts',
							'aa_womens_tshirt',
							'tshirts_gildan',
							'gildan_softstyle_tshirt',
							'tank_top',
							'tank_top_gildan',
							'zip_hoodie',
							'aa_zip_hoodie',
							'aa_tank_top',
							'gildan_tank_top',
							'gildan_dry_blend_sweatshirt',
							'gildan_heavy_blend_sweatshirt',
							'awd_kids_sweatshirt',
							'awd_kids_zip_hoodie_jh50j',
							'awd_kids_sweatshirt_jh30j',
							'bc_kids_tshirt',
							'awd_kids_sweatshirt',
						)
					)
				) {
					if (
						in_array(
							$product_id,
							array(
								'aa_mens_tshirt',
								'aa_womens_tshirt',
								'aa_tank_top',
								'gildan_tank_top',
							)
						)
					) {
						$color = 'heather_grey';
					} else {
						$color = $options['color'];
					}
					$job['options'] = array(
						'garment_color' => $color,
						'garment_size' => $this->get_garment_size( $options['size'] ),
					);
					$job['assets'] = $this->images2assets(
						'center_chest', $image_url,
						'center_back', $back_image_url
					);
				} elseif ( $product_id === 'greeting_card' ) {
					$job['assets'] = array(
						'front_image' => $job['assets'][0],
					);
				}
				$kite_items[] = array(
					'id' => $id,
					'name' => $item['name'],
				);
				$jobs[] = $job;
			}
		}

		// All together
		$customer_email = get_post_meta( $oid, '_billing_email', true );
		// $customer_email = 'test-503-failure@kite.ly'; // Test 503 server response
		$kite_order = array(
			'shipping_address' => array(
				'recipient_name' => $recipient_name,
				'address_line_1' => implode( ' ', get_post_meta( $oid, '_shipping_address_1' ) ),
				'address_line_2' => implode( ' ', get_post_meta( $oid, '_shipping_address_2' ) ),
				'city' => implode( ' ', get_post_meta( $oid, '_shipping_city' ) ),
				'postcode' => implode( ' ', get_post_meta( $oid, '_shipping_postcode' ) ),
				'county_state' => implode( ' ', get_post_meta( $oid, '_shipping_state' ) ),
				'country_code' => $country,
			),
			'customer_email' => $customer_email,
			'customer_payment' => array(
				'amount' => $amount,
				'currency' => get_woocommerce_currency(),
			),
			'jobs' => $jobs,
		);
		/* Send the request */
		$resp = $this->request( 'print', array( 'data' => $kite_order ) );
		$status_code = wp_remote_retrieve_response_code( $resp );
		/* Handle errors */
		if ( $status_code < 200 || $status_code >= 300 || $resp instanceof WP_Error ) {
			if ( $status_code != 503 ) {
				$this->update_order_data(
					$oid,
					array(
						'kite_data' => array(
							'status' => 'Error',
							'errors' => $resp->errors,
						),
						'status_code' => $status_code,
					)
				);
				if ( $this->plugin->get_option( 'add_order_notes' ) ) {
						$reasons = array();
					if ( is_array( $resp->errors ) ) {
						foreach ( $resp->errors as $error_id => $error_texts ) {
							$reasons[] = sprintf(
								'<tt>%s</tt>: %s',
								$error_id,
								__( implode( ', ', $error_texts ), 'wookite' )
							);
						}
					} elseif ( is_array( $resp ) && ! empty( $resp['json']['error']['message'] ) ) {
						$reasons[] = sprintf(
							'<tt>%s</tt>: %s',
							$error_id,
							__( $resp['json']['error']['message'], 'wookite' )
						);
					}
							$order->add_order_note(
								sprintf(
									'%s:<br />%s.',
									sprintf(
										__( 'Order with %1$s items failed due to the following reason%1$s', 'wookite' ),
										'<span class="wookitext">K</span>',
										count( $reasons ) > 1 ? 's' : ''
									),
									implode( '<br />', $reasons )
								)
							);
				}
			}
			return $this->response( 7, 'Error sending order to Kite', array( 'resp' => $resp ) );
		}
		if ( empty( $resp['json']['print_order_id'] ) ) {
			return $this->response( 7, 'Error sending order to Kite', array( 'resp' => $resp['json'] ) );
		}
		/* Get order data and save to the database */
		sleep( 2 );
		$resp = $this->get_order_status_from_kite_by_id( $oid, $resp['json']['print_order_id'] );
		if ( $resp['code'] !== 0 ) {
			return $resp;
		}
		$kite_data =& $resp['kite_data'];
		usort( $kite_data['jobs'], 'wookite_jobs_cmp' );
		$cnt = min( count( $kite_data['jobs'] ), count( $kite_items ) );
		$kite2idx = array();
		$woo2idx = array();
		for ( $i = 0; $i < $cnt; $i++ ) {
			$job_id = $kite_data['jobs'][ $i ]['job_id'];
			$woo_id = $kite_items[ $i ]['id'];
			$kite_items[ $i ]['job_id'] = $job_id;
			$kite2idx[ $job_id ] = $i;
			$woo2idx[ $woo_id ] = $i;
		}
		$this->update_order_data(
			$oid,
			array(
				'kite_data' => $kite_data,
				'items' => array(
					'all' => $kite_items,
					'kite2idx' => $kite2idx,
					'woo2idx' => $woo2idx,
				),
				'status_code' => $status_code,
			)
		);
		if ( $this->plugin->get_option( 'add_order_notes' ) ) {
			$order->add_order_note(
				sprintf(
					(
					$kite_info['live'] ?
					__( 'Order with %s items sent to Kite.', 'wookite' ) :
					__( 'Test order with %s items sent to Kite.', 'wookite' )
					),
					'<span class="wookitext">K</span>'
				)
			);
		}
		return $this->response( 0, 'Order sent to Kite' );
	}

	public function order_processing( $oid ) {
		global $wpdb;
		$oid = $this->plugin->get_order_id( $oid );
		// Add an empty entry to the orders table so it can be picked up by
		// the autosend function.
		// Ignore errors that can happen if this order already has an entry
		// in the table.
		if ( $oid ) {
			return $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . WOOKITE_ORDERS_TABLE_NAME . ' ' .
					'SET order_id=%d',
					$oid
				)
			);
		}
	}

	public function sanitize_text_field( $filtered, $str ) {
		return $this->plugin->image_thumb( $filtered );
	}

	public function wpseo_opengraph_image( $image_url ) {
		return $this->plugin->image_thumb( $image_url );
	}

	public function wpseo_json_ld_output( $data, $context ) {
		$res = array();
		foreach ( $data as $key=>$value)
			if ( @is_array( $value ) )
				$res[ $key ] = $this->wpseo_json_ld_output( $value, $context );
			else
				$res[ $key ] = $this->plugin->image_thumb( $value );
		return $res;
	}

	public function just_send_order( $oid ) {
		$order = $this->plugin->get_order( $oid );
		$this->send_order_to_kite( $order );
	}

	public function send_order() {
		if ( current_user_can( 'edit_shop_orders' )
			&& check_admin_referer( 'wookite_send_order_to_kite' )
			&& isset( $_GET['order_id'] )
		) {
			$order_id = (int) $_GET['order_id'];
			if ( $order_id ) {
				$this->just_send_order( $order_id );
				$this->maybe_output_new_status( $order_id );
			}
		}
		$this->redirect_back_to_orders();
	}

	public function maybe_send_order( $order_id ) {
		$order = $this->plugin->get_order( $order_id );
		$kite_info = $this->get_order_data( $order );
		if ( empty( $kite_info['kite_id'] ) ) {
			$resp = $this->send_order_to_kite( $order );
			return ! (isset( $resp['code'] ) && $resp['code'] && isset( $resp['message'] ));
		}
		return false;
	}

	protected function get_order_status_from_kite_by_id( $oid, $kite_id ) {
		$resp = $this->request( 'order/' . $kite_id );
		if ( $resp instanceof WP_ERROR || empty( $resp['json'] )
			|| $resp['status_code'] < 200 || $resp['status_code'] >= 300
			|| empty( $resp['json']['order_id'] )
			|| $resp['json']['order_id'] != $kite_id
		) {
			return $this->response( 3, 'Error fetching order status from Kite', $resp );
		}
		$resp['json']['fetch_time'] = time();
		return $this->response(
			0,
			'',
			array(
				'kite_data' => $resp['json'],
			)
		);
	}

	public function get_order_status_from_kite( $order ) {
		$order = $this->plugin->get_order( $order );
		if ( ! $order ) {
			return $this->response( 1, 'Order does not exist' );
		}
		$oid = $this->plugin->get_order_id( $order );
		$kite_info = $this->get_order_data( $order );
		if ( ! $kite_info['has_kite'] ) {
			return $this->response( 2, 'No Kite items in this order' );
		}
		if ( empty( $kite_info['kite_id'] ) ) {
			return array(
			'status' => $this->order_status_not_sent,
			);
		}
		$resp = $this->get_order_status_from_kite_by_id( $oid, $kite_info['kite_id'] );
		if ( $resp['code'] === 0 ) {
			$this->update_kite_data( $oid, $resp['kite_data'] );
		}
		return $resp;
	}

	public function maybe_output_new_status( $order ) {
		if ( isset( $_GET['output'] ) && $_GET['output'] === 'new' ) {
			$kite_info = $this->get_order_data( $order );
			if ( empty( $kite_info['full_status'] ) ) {
				printf( '<span class="wookite order status error">%s</span>', __( 'Error fetching new status', 'wookite' ) );
			} else {
				echo sprintf(
					'%s (%s)',
					$kite_info['full_status'],
					__( 'Reload the page to see all the changes', 'wookite' )
				);
			}
			die();
		}
	}

	public function get_order_status() {
		if ( current_user_can( 'edit_shop_orders' )
			&& check_admin_referer( 'wookite_get_order_status' )
		) {
			$order = $this->plugin->get_order( (int) $_GET['order_id'] );
			$this->get_order_status_from_kite( $order );
			$this->maybe_output_new_status( $order );
		}
		$this->redirect_back_to_orders();
	}

	public function update_billing_card( $stripeToken = null ) {
		if ( empty( $stripeToken ) ) {
			$resp = $this->request(
				'user/me',
				array(
				'data' => array(
				'stripe_customer_id' => '',
				),
				'method' => 'patch',
				'use-keys' => 'live',
				)
			);
		} else {
			$resp = $this->request(
				'user/register_card/',
				array(
				'data' => array(
				'stripeToken' => $stripeToken,
				),
				'use-keys' => 'live',
				)
			);
		}
		$sc = wp_remote_retrieve_response_code( $resp );
		if ( 200 <= $sc && $sc <= 299 ) {
			$this->plugin->me( true );
			return true;
		}
		if ( $resp instanceof WP_Error ) {
			return $this->response( $sc, $resp );
		} else {
			return $this->response( $sc, $resp['json'] );
		}
	}

	public function delete_billing_card() {
		return $this->update_billing_card();
	}

}

function wookite_jobs_cmp( $a, $b ) {
	return strcmp( substr( $a['job_id'], 15, 2 ), substr( $b['job_id'], 15, 2 ) );
}

