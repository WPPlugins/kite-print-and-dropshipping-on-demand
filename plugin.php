<?php

defined('ABSPATH') or die('No script kiddies please!');

require_once(dirname(__FILE__) . '/tools.php');
require_once(dirname(__FILE__) . '/help.php');
require_once(dirname(__FILE__) . '/shipment_tracking.php');

class WooKitePlugin {
	static $required_config_fields = array(
		'install_time', 'expiration', 'shipping_zones',
		'bogus_img', 'custom_attributes'
	);
	protected static $instance;
	protected $kite_key = null;
	protected $kite_secret = null;
	public $force_config_fetch = false;
	public $stripe_publishable_key = 'pk_live_o1egYds0rWu43ln7FjEyOU5E';
	public $config_url = 'http://shopify.kite.ly/v1.1/woo-config/?currency_code=%s';
	public $bogus_img_url = 'https://s3.amazonaws.com/kitewebsite/kite_logo.png';
	protected $getting_bogus_image = false;

	static function get_instance() {
		if (!isset(self::$instance))
			self::$instance = new self();
		return self::$instance;
	}

	public function __construct() {
		add_action('plugins_loaded', array($this, 'init'));
	}

	public function init() {
		// Load translations.
		load_plugin_textdomain('wookite', false, dirname(plugin_basename(__FILE__)) . '/lang');
		// Set plugin's name.
		$this->name = __('Kite.ly Merch Plugin', 'wookite');
		// Which options do we need the user to set up.
		$this->required_options = array(
			'shipping-methods' => sprintf(
				__('Please go to %s and set up your shipping zones.', 'wookite'),
				sprintf(
					'<a href="%s">%s</a>',
					admin_url('admin.php?page=wookite-settings&tab=shipping'),
					__('Kite.ly Merch Shipping settings', 'wookite')
				)
			),
		);
		/*
		Check if WooCommerce is activated; a trick taken from
		https://pippinsplugins.com/checking-dependent-plugin-active/
		*/
		if (class_exists('WooCommerce')) {
			// Start WooKite's special interfaces.
			if (!isset($this->api))
				$this->api = new WooKiteAPI($this);
			if (!isset($this->kite))
				$this->kite = new WooKiteKite($this);
			if (!isset($this->tools))
				$this->tools = new WooKiteTools($this);
			if (!isset($this->help))
				$this->help = new WooKiteHelp($this);
			if (!isset($this->shipment_tracking))
				$this->shipment_tracking = new WooKiteShipmentTracking($this);
			// Initialize the plugin.
			$this->iconi16_src = self::plugins_url('kite-inv-16x16.png');
			add_action('wp_loaded', array($this, 'wp_loaded'));
			add_action('wp_loaded', array($this, 'special_pages'));
			$this->load_stripe = false;
			$this->load_frontend = false;
			if (is_admin()) {
				$this->load_stripe =
					(
						isset($_GET['page']) &&
						$_GET['page'] === 'wookite-settings' &&
						(empty($_GET['tab']) || $_GET['tab'] === 'general')
					);
				// Plugin.
				add_action('admin_head', array($this, 'admin_head'));
				add_action('admin_menu', array($this, 'plugin_menu'));
				add_action('admin_init', array($this, 'register_settings'));
				add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 71);
				// Scripts and styles.
				add_action('admin_enqueue_scripts', array($this, 'enqueue_for_admin'));
				// Frontend.
				$this->load_frontend = (
					isset($_GET['page']) &&
					$_GET['page'] == 'wookite-plugin' &&
					empty($_GET['endpoint'])
				);
				if ($this->load_frontend) {
					$frontend_php = dirname(__FILE__) . '/frontend/index.php';
					require_once($frontend_php);
					$this->frontend = new WooKiteFrontend($this);
				} else
					add_action('admin_notices', array($this, 'admin_notices'));
			}
			add_action('wp_enqueue_scripts', array($this, 'enqueue_for_user'));
			// Unintrusive cron jobs.
			add_action('wp_head', array($this, 'wp_head'));
			add_action('wp_footer', array($this, 'cron_js'));
			add_action('admin_footer', array($this, 'cron_js'));
			// Make product images external.
			add_filter('woocommerce_single_product_image_html', array($this, 'image_tag'), 0, 2);
			add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'image_thumb'), 0, 4);
			add_filter('wp_get_attachment_url', array($this, 'image_tag'), 0, 2);
			add_filter('woocommerce_available_variation', array($this, 'image_variation'), 0, 3);
			# Oxygen theme is persistent with replacing this back, so we do it again
			add_filter('woocommerce_available_variation', array($this, 'image_variation'), 171923, 3);
			add_filter('post_thumbnail_html', array($this, 'image_post_thumb'), 0, 5);
			add_filter('woocommerce_cart_item_thumbnail', array($this, 'image_cart_item_thumb'), 0, 3);
			add_filter('image_downsize', array($this, 'image_downsize'), 0, 3);
			// Make category images external.
			add_action('woocommerce_before_subcategory_title', array($this, 'woocommerce_before_subcategory_title'), 0, 1);
			add_filter('woocommerce_placeholder_img_src', array($this, 'woocommerce_category_skip_empty_src'));
			add_filter('wp_get_attachment_image_src', array($this, 'woocommerce_fix_category_src'), 0, 4);
			add_filter('manage_product_cat_custom_column', array( $this, 'woocommerce_product_cat_column' ), 17, 3);
			add_action('woocommerce_product_meta_start', array($this, 'storefront_product_extra'));
		} else {
			/*
			Idea taken from
			https://gist.github.com/mathetos/7161f6a88108aaede32a
			*/
			add_action('admin_init', array($this, 'deactivate_me'));
			add_action('admin_notices', array($this, 'deactivation_admin_notice'));
		}
	}

	public function wp_loaded() {
		$this->version_upgrade();
	}

	public function enqueue_for_user() {
		if (class_exists('WooCommerce')) {
			wp_enqueue_script('wookite-script', self::plugins_url('script.js'), array('jquery'));
			wp_enqueue_script(
				'wookite-script-storefront',
				self::plugins_url('script-storefront.js'),
				array('jquery', 'wc-add-to-cart-variation')
			);
			wp_enqueue_style(
				'wookite-style-storefront',
				self::plugins_url('style-storefront.css')
			);
		}
	}

	public function enqueue_for_admin() {
		if (class_exists('WooCommerce')) {
			wp_enqueue_script('wookite-script', self::plugins_url('script.js'), array('jquery'));
			if ($this->load_stripe)
				wp_enqueue_script('wookite-stripe', 'https://js.stripe.com/v2/');
			wp_enqueue_style('wookite-style', self::plugins_url('style.css'));
			if ($this->load_frontend)
				$this->frontend->enqueue();
		}
	}

	protected function vid2img_json($data, $side='image') {
		$res = array();
		foreach ($data as $vid=>$variant) {
			$data = $this->kite->post_id2data($vid);
			$field_name = 'product_' . $side;
			$res[$vid] = (
				isset($data[$field_name]) && $data[$field_name] ?
				$data[$field_name] :
				''
			);
		}
		return json_encode($res);
	}

	public function storefront_product_extra() {
		global $post;
		if (
			$this->get_option('image_switcher') &&
			$this->kite->product_has_back_image($post->ID) &&
			! $this->kite->product_is_frozen($post->ID)
		) {
			$variants_data = $this->kite->get_variants_by_product($post->ID);
			printf(
				'<div id="wookite_front_back_picker">' .
				'<span id="wookite_front_side" class="active" ' .
				'data-images="%s">%s</span>' .
				'<span id="wookite_back_side" class="inactive" ' .
				'data-images="%s">%s</span>' .
				'</div>',
				esc_attr($this->vid2img_json($variants_data, 'image')),
				__('Front side', 'wookite'),
				esc_attr($this->vid2img_json($variants_data, 'back_image')),
				__('Back side', 'wookite')
			);
		}
	}

	public function deactivate_me() {
		deactivate_plugins(dirname(__FILE__) . '/wookite.php');
	}

	public function deactivation_admin_notice() {
		$woocommerce_name = __('WooCommerce', 'wookite');

		echo
			'<div class="error"><p>' .
			sprintf(
				__('%1$s requires %2$s plugin to function correctly. Please activate %2$s before activating %1$s. For now, the plugin has been deactivated.', 'wkwcst' ),
				'<strong>' . esc_html($this->name) . '</strong>',
				'<strong>' . esc_html($woocommerce_name) . '</strong>'
			) .
			'</p></div>';
		if (isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	public function current_user_is_admin() {
		/*
		`current_user_can('manage_options')` == "user is admin"
		Admins should also be WooCommerce managers, but
		that doesn't happen in unit tests, so we add it artificially
		*/
		return (
			current_user_can('manage_options') ||
			current_user_can('manage_woocommerce')
		);
	}

	public function admin_notices() {
		foreach ($this->required_options as $option=>$problem)
			if (is_null($this->get_option($option)))
				if (!defined('WOOKITE_UNIT_TESTING'))
					printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
						$problem
					);
	}

	public function k_logo($class=null) {
		if (!empty($class)) $class .= ' ';
		return "<span class=\"${class}wookitext\">K</span>";
	}

	public function shop_url() {
		// From https://snipt.net/jordi/get-the-woocommerce-shop-url/ .
		if (function_exists('wc_get_page_id'))
			return get_permalink(wc_get_page_id('shop'));
		else
			return get_permalink(woocommerce_get_page_id('shop'));
	}

	public function wp_head() {
		printf(
			"<meta name=\"generator\" content=\"Kite.ly Merch %s\" />\n",
			$this->version()
		);
	}

	public function cron_js() {
		$js = '';
		if ($this->kite->time_for_cron_now() !== false)
			$js .= sprintf(
				'wookite_trigger_cron("%s");',
				home_url('/?page=wookite-plugin&endpoint=cron')
			);
		$fbris = $this->api->product_ranges->freeze_broken_ranges_ids();
		if ($fbris)
			$js .= sprintf(
				'wookite_trigger_cron("%s", {freeze: %d});',
				admin_url(sprintf(
					'admin.php?page=wookite-plugin&endpoint=product_range&' .
					'wpnonce=%s',
					wp_create_nonce('wookite-frontend')
				)),
				$fbris[0]
			);
		if ($js) {
			printf('<script type="text/javascript">%s</script>', $js);
		}
	}

	public function me($reload=false) {
		return $this->api->me->get_me($reload);
	}

	public function get_wc_obj_value($object, $method='get_id', $field='id') {
		if (is_array($object)) return $objecy[$field];
		if (method_exists($object, $method))
			// WC 3.0+
			return $object->$method();
		else
			// WC<3.0
			return $var->$field;
	}

	public function get_wc_id($object, $id_name='id') {
		return $this->get_wc_obj_value($object, 'get_id', $id_name);
	}

	public function get_config_url($currency=null) {
		if (is_null($currency))
			$currency = get_woocommerce_currency();
		// $this->config_url = self::plugins_url('woo-config.json'); //.
		return sprintf($this->config_url, $currency);
	}

	protected function fetch_config() {
		$resp = wookite_request($this->get_config_url());
		return ($resp instanceof WP_Error ? null : $resp['json']);
	}

	public function get_option($name=null) {
		if (!isset($this->options)) {
			$options = get_option('wookite_options', array());
			if (!(
				isset($options['product-image-size']) and
				preg_match('#^\d+x\d+$#i', $options['product-image-size'])
			))
				$options['product-image-size'] = "1200x1200";
			if (!isset($options['kite-live-mode']))
				$options['kite-live-mode'] = true;
			if (!isset($options['default_markup']) || $options['default_markup'] === '')
				$options['default_markup'] = 30;
			if (!isset($options['fast_create_products']))
				$options['fast_create_products'] = true;
			if (!isset($options['add_order_notes']))
				$options['add_order_notes'] = true;
			if (!isset($options['image_switcher']))
				$options['image_switcher'] = true;
			if (!isset($options['product_images_background']))
				$options['product_images_background'] = '';
			if (!isset($options['pt2cat']))
				$options['pt2cat'] = array();
			if (!isset($options['default_published_status']))
				$options['default_published_status'] = 'publish';
			if (!isset($options['dual_image_style']))
				$options['dual_image_style'] = 'bfsb';
			$this->options = $options;
		}
		if (isset($name)) {
			if (!isset($this->options[$name])) {
				if (substr($name, 0, 17) === 'custom-attribute-')
					return true;
			}
			return (isset($this->options[$name]) ? $this->options[$name] : null);
		} else
			return $this->options;
	}

	public function set_options($options) {
		$this->options = wp_parse_args(
			$options,
			$this->get_option()
		);
		update_option('wookite_options', $this->options);
	}

	public function set_option($name, $value) {
		$this->set_options(array($name => $value));
	}

	public function set_max_execution_time($time) {
		$limit = get_option('wookite_max_execution_time');
		if ($limit !== false) {
			$limit = (int)$limit;
			if ($limit > 0) $time = min($time, $limit);
		}
		$current = (int)@ini_get('max_execution_time');
		@ini_set('max_execution_time', max($current, $time));
	}

	/*
	public function new_attachment($att_id){
		$this->last_attachment_id = $att_id;
	}

	public function upload_bogus_img() {
		add_action('add_attachment', array($this, 'new_attachment'));
		media_sideload_image(
			(
				defined('WOOKITE_UNIT_TESTING') ?
				self::plugins_url('bogus_img.png') :
				$this->bogus_img_url
			),
			0
		);
		$attachment_id = (int)$this->last_attachment_id;
		remove_action('add_attachment', array($this, 'new_attachment'));
		return $attachment_id;
	}
	*/

	// Adapted from https://gist.github.com/hissy/7352933
	public function upload_bogus_img() {
		/*
		$filename = 'bogus_img.png';
		$file = $this->plugins_path($filename);
		*/
		$file = WC()->plugin_url() . '/assets/images/placeholder.png';
		$filename = 'kite-placeholder.png';
		$upload_file = wp_upload_bits($filename, null, file_get_contents($file));
		if (!$upload_file['error']) {
			$wp_filetype = wp_check_filetype($filename, null);
			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				// 'post_parent' => $parent_post_id,
				'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
				'post_content' => __('This image is a placeholder for your Kite.ly Merch product images. It\'s important for the smooth-running of the plugin and should not be removed from your Media Library.', 'wookite'),
				'post_status' => 'inherit',
			);
			$attachment_id = wp_insert_attachment($attachment, $upload_file['file']);
			if (!is_wp_error($attachment_id)) {
				require_once(ABSPATH . 'wp-admin/includes/image.php');
				$attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
				wp_update_attachment_metadata($attachment_id,  $attachment_data);
				return $attachment_id;
			}
		}
		return null;
	}

	public function load_config() {
		global $wpdb;
		if (isset($this->config)) {
			if (isset($this->force_config_fetch) && $this->force_config_fetch) {
				$config = $this->config;
				$ok = false;
			} else {
				return $this->config;
			}
		} else {
			$config = get_option('wookite_config');
			if (isset($this->force_config_fetch) && $this->force_config_fetch) {
				$ok = false;
			} else {
				$ok = (bool)$config;
			}
		}
		$this->force_config_fetch = false;
		$now = time();
		$reset_product_images = false;
		$bogus_img = (isset($config['bogus_img']) ? $config['bogus_img'] : 0);
		if (!($bogus_img && wp_attachment_is_image($bogus_img))) {
			$bogus_img_posts = get_posts(array(
				'numberposts'	=> 1,
				'post_type'		=> 'attachment',
				'meta_key'		=> 'wookite',
				'meta_value'	=> 'yes'
			));
			if (empty($bogus_img_posts)) {
				$new_id = $this->upload_bogus_img();
				if (empty($bogus_img)) {
					$bogus_img = $new_id;
				} else {
					// Attempt to reset the old id, so the existing products would work.
					if (@$wpdb->query($wpdb->prepare(
						'UPDATE ' . $wpdb->posts . ' SET ID=%d WHERE ID=%d',
						$bogus_img, $new_id
					)))
						@$wpdb->query($wpdb->prepare(
							'UPDATE ' . $wpdb->postmeta . ' SET post_id=%d WHERE post_id=%d',
							$bogus_img, $new_id
						));
					else
						$bogus_img = $new_id;
				}
				add_post_meta($bogus_img, 'wookite', 'yes', true);
				$reset_product_images = true;
			} else
				$bogus_img = (int)$bogus_img_posts[0]->ID;
			$ok = false;
		}
		$install_time = (
			!isset($config['install_time']) || empty($config['install_time']) ?
			$now :
			$config['install_time']
		);
		if ($ok && $config) {
			foreach ($this::$required_config_fields as $rcf)
				if (!isset($config[$rcf])) {
					$ok = false;
					break;
				}
		}
		if ($ok && $config['expiration'] > $now) {
			$this->config = $config;
		} else {
			$new_config = $this->fetch_config();
			if (empty($new_config)) {
				if (empty($config)) {
					$this->config = array();
					return $this->config;
				}
				/*
				If there is no new config, but there is an old one,
				use the old one (but don't reset the expiration time).
				*/
				$this->config = $config;
			} else {
				$config =& $new_config;
				if (!isset($config['expiration']) || $config['expiration'] <= $now)
					$config['expiration'] = $now + 7*24*60*60;
			}
			if (isset($config['product_types'])) {
				sort(
					$config['product_types'],
					SORT_LOCALE_STRING | SORT_NATURAL | SORT_FLAG_CASE
				);
			}
			/*
			$tid2cost = array();
			if (isset($config['shipping_classes']) && is_array($config['shipping_classes'])) {
				foreach ($config['shipping_classes'] as $sc)
					foreach ($sc['templates'] as $tid)
						$tid2cost[$tid] = $sc['prices'];
				ksort($tid2cost);
			}
			$config['tid2cost'] = $tid2cost;
			unset($config['shipping_classes']);
			*/
			$config['bogus_img'] = $bogus_img;
			$config['install_time'] = $install_time;
			$this->config = $config;
			update_option("wookite_config", $config);
			if ($reset_product_images)
				$this->autoset_product_images(false);
			$this->update_all_shipping_costs();
		}
		return $config;
	}

	public function get_config($config_name=null) {
		$config = $this->load_config();
		if (!isset($config))
			return (isset($config_name) ? null : array());
		if (isset($config_name))
			if (is_array($config_name)) {
				$res = array();
				foreach ($config_name as $cn)
					if (isset($config[$cn]))
						$res[$cn] = $config[$cn];
				$config = $res;
			} else
				$config = (isset($config[$config_name]) ? $config[$config_name] : null);
		return $config;
	}

	public function set_configs($config) {
		$this->config = array_merge(
			$this->get_config(),
			$config
		);
		update_option('wookite_config', $this->config);
	}

	public function set_config($name, $value) {
		$this->set_configs(array($name => $value));
	}

	public function version() {
		if (!isset($this->plugin_data)) {
			$plugin_name = basename(dirname(__FILE__));
			$plugin_name = sprintf('%s/%s.php', dirname(__FILE__), $plugin_name);
			$this->plugin_data = get_plugin_data($plugin_name);
		}
		return $this->plugin_data['Version'];
	}

	protected function version_upgrade() {
		global $wpdb;
		$curr_ver = $this->version();
		$last_ver = get_option('wookite_ver', false);
		if ($last_ver === false)
			$last_ver = get_option('wookite_db_ver', '1.0.3');
		if ($last_ver === $curr_ver) return;
		// Make sure the config is reloaded, just in case
		$this->force_config_fetch = true;
		$this->load_config();
		// Remove DB columns that need removing
		// (dbDelta handles adding and updating new columns)
		$to_remove = array(
			// First version to remove => what to remove
			'1.0.4' => array(
				// Table => list of columns
				WOOKITE_ORDERS_TABLE_NAME => array('time_created'),
				WOOKITE_POSTMETA_TABLE_NAME => array('time_created'),
				WOOKITE_JOBS_TABLE_NAME => array('time_created'),
			),
		);
		foreach ($to_remove as $ver=>$remove_data)
			if (version_compare($last_ver, $ver, '<'))
				foreach ($remove_data as $table=>$remove_columns) {
					# From https://code.tutsplus.com/tutorials/custom-database-tables-maintaining-the-database--wp-28455
					$existing_columns = $wpdb->get_col("DESC $table", 0);
					$remove_columns = array_intersect($remove_columns, $existing_columns);
					if (!empty($remove_columns))
						$wpdb->query(
							"ALTER TABLE $table DROP COLUMN ".implode(
								', DROP COLUMN ',$remove_columns
							).';'
						);
				}
		// Update v1.0.x shipping config to v1.1+
		if (version_compare($last_ver, '1.1.0', '<')) {
			// Preserve old shipping zones data
			$old_zones = $this->get_option('shipping-zones');
			if (isset($old_zones) && is_array($old_zones)) {
				$new_methods = array();
				foreach ($old_zones as $ozid=>$old_zone) {
					$frms = $this->shipping_zone2frms($ozid);
					if ($frms) {
						foreach ($frms as $frm) {
							$frid = $frm->instance_id;
							$new_methods[$frid] = array(
								'kite_zone_code' => $old_zone['kite_zone_code'],
								'tracked' => (
									stripos($frm->title, 'tracked') !== false ||
									stripos($frm->title, __('tracked', 'wookite')) !== false
								),
							);
						}
					}
				}
				$this->set_option('shipping-methods', $new_methods);
				wp_cache_flush();
				wp_cache_delete('alloptions', 'options');
				unset($this->config);
				unset($this->options);
				$this->update_all_shipping_costs();
			}
		}
		update_option('wookite_ver', $curr_ver);
		// Delete old `wookite_db_ver` option from v1.0.x
		if (version_compare($last_ver, '1.1.0', '<')) {
			delete_option( 'wookite_db_ver' );
		}
	}

	public function kite_shipping_zones($add_empty=false) {
		if (!isset($this->_kite_shipping_zones[$add_empty])) {
			$zones = $this->get_config('shipping_zones');
			$res = array();
			foreach ($zones as $zname=>$zdata) {
				$zdata['name'] = $zname;
				$res[$zdata['code']] = $zdata;
			}
			uasort($res, 'wookite_zones_cmp');
			$res2 = ($add_empty ? array("" => "") : array());
			foreach ($res as $zid=>$zdata)
				$res2[$zid] = $zdata['name'];
			$this->_kite_shipping_zones[$add_empty] = $res2;
		}
		return $this->_kite_shipping_zones[$add_empty];
	}

	public function kite_rest_of_the_world() {
		if (!isset($this->_kite_rest_of_the_world)) {
			$this->_kite_rest_of_the_world = "";
			$zones = $this->get_config('shipping_zones');
			foreach ($zones as $zname=>$zdata)
				if (empty($zdata['locations'])) {
					$this->_kite_rest_of_the_world = $zdata['code'];
					break;
				}
		}
		return $this->_kite_rest_of_the_world;
	}

	public static function plugins_url($path) {
		return plugins_url(basename(dirname(__FILE__)) . "/" . $path);
	}

	public function plugins_path($path) {
		return plugin_dir_path(__FILE__) . $path;
	}

	public function country_2to3($code) {
		if (strlen($code) === 3) return strtoupper($code);
		if (strlen($code) !== 2) return false;
		$now = time();
		$country_2to3 = $this->get_config('countries');
		if ($country_2to3 === false || !is_array($country_2to3) ||
			!isset($country_2to3['expiration']) || $country_2to3['expiration'] < $now ||
			empty($country_2to3['codes'])
		) {
			$country_2to3 = wookite_request('http://country.io/iso3.json', array('timeout' => 5));
			if ($country_2to3 instanceof WP_Error)
				$country_2to3 = null;
			else
				$country_2to3 = $country_2to3['json'];
			if (!isset($country_2to3)) {
				// Cannot load from country.io; fallback to the locally saved file.
				$country_2to3 = json_decode(file_get_contents(dirname(__FILE__) . '/iso3.json'), true);
				if ($country_2to3 === false)
					return false; // How did you manage to get here?!
			}
			$this->set_config('countries', $country_2to3);
		}
		return (empty($country_2to3[$code]) ? false : $country_2to3[$code]);
	}

	public function get_bogus_image_id() {
		if (defined('WOOKITE_UNIT_TESTING'))
			unset($this->config);
		return $this->get_config('bogus_img');
	}

	public function get_bogus_image_url() {
		static $bogus_img = null;
		if (!isset($bogus_img)) {
			$this->getting_bogus_image = true;
			$bogus_img = wp_get_attachment_url($this->get_bogus_image_id());
			$this->getting_bogus_image = false;
		}
		return $bogus_img;
	}

	public function replace_bogus_image($html, $new_url, $title='', $ignore_size=true) {
		static $bogus_img = null, $bogus_img_size = null;
		if ($this->getting_bogus_image || empty($new_url))
			return $html;
		if (!isset($bogus_img)) {
			$bogus_img = $this->get_bogus_image_url();
			# Handle off-site images
			$re_host = 'https?://[^/]+/';
			$bogus_img = preg_replace("#$re_host#", '', $bogus_img);
			$bogus_img_size = sprintf(
				'#%s[^ \'"]*\Q%s',
				$re_host,
				preg_replace(
					'#\.png$#', '\E-(\d+x\d+)\.png[^ \'"]*#i', $bogus_img
				)
			);
			$bogus_img = sprintf(
				'#%s[^ \'"]*\Q%s\E[^ \'"]*#i', $re_host, $bogus_img
			);
		}
		$html = preg_replace(
			array(
				$bogus_img_size,
				$bogus_img,
				'#(\b(?:alt|title)=(["\'])).*?\2#',
				'#http://image.kite.ly/[^ "\']*[?&]size=(\d+x\d+)?[^ "\']*#',
			),
			array(
				$new_url . ($ignore_size ? '' : '\1'),
				$new_url . ($ignore_size ? '' : $this->get_option('product-image-size')),
				'\1' . $title . '\2',
				$new_url . ($ignore_size ? '' : '\1'),
			),
			$html
		);
		return $html;
	}

	public function sub_image_urls($html, $post_id) {
		static $post_data_cache = array();
		if (!isset($post_data_cache[$post_id]))
			$post_data_cache[$post_id] = $this->kite->post_id2data($post_id);
		$data =& $post_data_cache[$post_id];
		if (!isset($data) || !isset($data['product_image']))
			return $html;
		$new_url = (
			$this->get_option('image_switcher') ?
			$data['product_image'] :
			$data['product_single_image']
		);
		return $this->replace_bogus_image($html, $new_url, $data['title'], false);
	}

	public function image_tag($html, $post_id) {
		return $this->sub_image_urls($html, $post_id);
	}

	public function image_thumb($html, $attachment_id=null, $post_id=null, $img_class=null) {
		if (is_null($post_id))
			$post_id = get_the_ID();
		return $this->sub_image_urls($html, $post_id);
	}

	public function get_variation_title($var_id, $var=null) {
		$var_post = get_post($var_id);
		if (method_exists($var, 'get_id')) {
			// For WC 3.0+, we must do it this way
			$title = $var_post->post_title;
			$attribs = wc_get_product_variation_attributes($var->get_id());
		} else {
			// For WC<3.0, we can just use the data that's already here
			$title = $var_post->post_title;
			$attribs = $var->variation_data;
		}
		if ($attribs) {
			ksort($attribs);
			$title .= sprintf(' [%s]', implode(', ', array_values($attribs)));
		}
		return $title;
	}

	public function image_variation($params, $var_this, $var) {
		// $var_id = ... ? WC 3.0+ : WC<3.0
		$var_id = (method_exists($var, 'get_id') ? $var->get_id() : $var->variation_id);
		foreach (array('image_src', 'image_link', 'image_srcset') as $f)
			if (isset($params[$f]))
				$params[$f] = $this->sub_image_urls($params[$f], $var_id);
		if (isset($params['image']) && is_array($params['image']))
			// WC 3.0+
			foreach (array('url', 'src', 'image_src', 'srcset', 'full_src', 'thumb_src') as $f)
				if (isset($params['image'][$f]))
					$params['image'][$f] = $this->sub_image_urls($params['image'][$f], $var_id);
		$title = $this->get_variation_title($var_id, $var);
		foreach (array('image_title', 'image_alt') as $f)
			if (isset($params[$f]))
				$params[$f] = $title;
		if (isset($params['image']) && is_array($params['image'])) {
			// WC 3.0+
			foreach (array('title', 'alt') as $f)
				if (isset($params['image'][$f]))
					$params['image'][$f] = $title;
			$params['image']['caption'] = '';
		}
		if (isset($params['image_caption']))
			$params['image_caption'] = '';
		return $params;
	}

	public function image_post_thumb($html, $post_id, $post_thumbnail_id, $size, $attr) {
		return $this->sub_image_urls($html, $post_id);
	}

	public function image_cart_item_thumb($html, $cart_item, $cart_item_key) {
		return $this->sub_image_urls($html, $cart_item['variation_id']);
	}

	public function image_downsize($out, $id, $size) {
		global $post;
		static $already_here = false;
		$screen = get_current_screen();
		if (
			!$already_here &&
			isset($post) && $post && $post->ID &&
			isset($screen) && $screen->parent_base !== 'edit' &&
			$id == $this->get_bogus_image_id()
		) {
			$already_here = true;
			if (!$image = wp_get_attachment_image_src($id, $size)) {
				return $out;
			}
			$image_meta = wp_get_attachment_metadata($id);
			$image_src = $image[0];
			$size = array(
				absint($image[1]),
				absint($image[2])
			);
			$data = $this->kite->post_id2data($post->ID);
			if (!isset($data) || empty($data) || empty($data['product_image'])) return $out;
			$already_here = false;
			return array($data['product_image'] . implode('x', $size), $size[0], $size[1], false);
		}
		return $out;
	}

	public function woocommerce_before_subcategory_title($category) {
		if (!is_array($this->wc_categories_ids))
			$this->wc_categories_ids = array();
		$this->wc_categories_ids[] = (int)$category->term_id;
	}

	public function woocommerce_category_skip_empty_src($image) {
		// Skip category if it had its image removed.
		if (isset($this->wc_categories_ids) && is_array($this->wc_categories_ids) && $this->wc_categories_ids)
			array_shift($this->wc_categories_ids);
		return $image;
	}

	public function woocommerce_fix_category_src($image, $attachment_id, $size, $icon) {
		static $term2img = array();
		if (
			isset($this->wc_categories_ids) &&
			is_array($this->wc_categories_ids) &&
			$this->wc_categories_ids
		) {
			$term_id = array_shift($this->wc_categories_ids);
			if (empty($term_id)) return $image;
			$img = $term2img[$term_id];
			if (empty($img)) {
				$ids = array($term_id);
				foreach ($this->wc_categories_ids as $cat)
					$ids[] = (int)$cat->term_id;
				foreach ($this->api->product_ranges->get_ranges_by_cat($ids) as $range) {
					$id = (int)$range['category_id'];
					if (empty($term2img[$id]))
						$term2img[$id] = $range['image_url_preview'];
				}
				$img = $term2img[$term_id];
			}
			if (!empty($img))
				$image[0] = $this->replace_bogus_image($image[0], $img);
		}
		return $image;
	}

	public function woocommerce_product_cat_column($columns, $column, $id) {
		static $ranges = null;
		if ($column === 'thumb') {
			if (!isset($ranges)) {
				$ranges = array();
				foreach ($this->api->product_ranges->all_ranges() as $range)
					$ranges[(int)$range['category_id']] = $range['image_url_preview'];
			}
			$img = $ranges[(int)$id];
			if (!empty($img))
				$columns = $this->replace_bogus_image($columns, $img);
		}
		return $columns;
	}

	public function admin_head() {
		if ($this->load_stripe)
			printf(
				'<script type="text/javascript">Stripe.setPublishableKey("%s");</script>',
				$this->stripe_publishable_key
			);
		if ($this->load_frontend)
			wp_enqueue_style(
				'wookite-OpenSans-font',
				'https://fonts.googleapis.com/css?family=Open+Sans:400,500,600%7CMontserrat:400,700'
			);
	}

	public function plugin_menu() {
		add_menu_page(
			'Kite.ly Merch', 'Kite.ly Merch', 'manage_options', 'wookite-plugin',
			array($this, 'frontend'),
			$this->iconi16_src,
			57
		);
		add_submenu_page(
			'wookite-plugin',
			'Kite.ly Merch Products', 'Products', 'manage_options',
			'wookite-plugin', array($this, 'frontend')
		);
		$this->help->add(
			sprintf('settings-%s', $this->get_active_tab()),
			add_submenu_page(
				'wookite-plugin',
				'Kite.ly Merch Settings', 'Settings', 'manage_options',
				'wookite-settings', array($this, 'settings')
			)
		);
		$this->help->add(
			'tools',
			add_submenu_page(
				'wookite-plugin',
				'Kite.ly Merch Tools', 'Tools', 'manage_options',
				'wookite-tools', array($this, 'tools')
			)
		);
		if (defined('WOOKITE_DEV') && WOOKITE_DEV)
			$this->help->add(
				'test',
				add_submenu_page(
					'wookite-plugin',
					'WooKite Test', 'Test', 'manage_options',
					'wookite-test', array($this, 'test_page')
				)
			);
	}

	protected function head($title) {
		echo '<div class="wrap">';
		printf('<h1>%s Kite.ly Merch %s</h1>', $this->k_logo(), $title);
	}

	protected function foot() {
		echo "</div>\n";
	}

	public function get_order_item_meta($item) {
		return (isset($item['wookite']) ? $item['wookite'] : array());
	}

	public function update_order_item_meta($item_id, $kite_meta) {
		return wc_update_order_item_meta($item_id, 'wookite', $kite_meta);
	}

	public function get_order($order) {
		if ($order instanceof WC_Order)
			return $order;
		elseif (is_int($order))
			return new WC_Order($order);
		elseif (is_string($order))
			return new WC_Order((int)$order);
		else
			return false;
	}

	public function get_order_id($order) {
		if ($order instanceof WC_Order)
			return $this->get_wc_id($order);
		elseif (is_int($order))
			return $order;
		elseif (is_string($order))
			return (int)$order;
		else
			return false;
	}

	public function frontend() {
		if (isset($this->frontend))
			$this->frontend->run();
	}

	public function test_page() {
		$this->head("Test page");
		$this->foot();
	}

	public function special_pages() {
		$this->api->run();
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'wookite-tools' )
			$this->tools->run();
	}

	public function get_active_tab() {
		return ( isset($_GET['tab']) ? sanitize_title( $_GET['tab'] ) : 'general' );
	}

	public function admin_bar_menu($wp_admin_bar) {
		$args = array(
			'id'     => 'wookite_products',
			'title'  => __('Kite.ly Products', 'wookite'),
			'href'   => admin_url('admin.php?page=wookite-plugin'),
			'parent' => 'new-content',
		);
		$wp_admin_bar->add_node( $args );
	}

	public function register_settings() {
		/*
		Create the option because otherwise WP 4.6 will sanitize it twice
		which breaks the image size, shipping zones,...
		*/
		if (get_option('wookite_options') === false)
			add_option('wookite_options', array());
		register_setting('wookite_option_group', 'wookite_options', array($this, 'sanitize_options'));
		$active_tab = $this->get_active_tab();
		if ($active_tab === 'general') {
			add_settings_section(
				'wookite-settings-kite',
				'Kite ' . __('settings', 'wookite'),
				null,
				'wookite-settings-page'
			);
			add_settings_field(
				'kite-credit-card',
				__('Credit card info', 'wookite'),
				array($this, 'option_credit_card'),
				'wookite-settings-page',
				'wookite-settings-kite'
			);
			add_settings_field(
				'kite-live-mode',
				'Kite ' . __('live mode', 'wookite'),
				array($this, 'option_checkbox'),
				'wookite-settings-page',
				'wookite-settings-kite',
				'kite-live-mode'
			);
			/*
			// Future feature.
			add_settings_field(
				'default_markup',
				__('Markup', 'wookite'),
				array($this, 'option_percent'),
				'wookite-settings-page',
				'wookite-settings-kite',
				'default_markup'
			);
			*/
			add_settings_field(
				'default_published_status',
				__('Publish product status', 'wookite'),
				array($this, 'option_default_published_status'),
				'wookite-settings-page',
				'wookite-settings-kite'
			);
			add_settings_field(
				'fast_create_products',
				sprintf(
					'%s<div class="wookite_fineprint">%s</div>',
					__('Fast products publishing', 'wookite'),
					__('Turn this off in case of problems with adding products.', 'wookite')
				),
				array($this, 'option_checkbox'),
				'wookite-settings-page',
				'wookite-settings-kite',
				'fast_create_products'
			);
			add_settings_section(
				'wookite-settings-appearance',
				__('Appearance', 'wookite'),
				null,
				'wookite-settings-page'
			);
			add_settings_field(
				'product-image-size',
				__('Image size', 'wookite'),
				array($this, 'option_product_image_size'),
				'wookite-settings-page',
				'wookite-settings-appearance'
			);
			add_settings_field(
				'add_order_notes',
				__('Add processing notes to orders', 'wookite'),
				array($this, 'option_checkbox'),
				'wookite-settings-page',
				'wookite-settings-appearance',
				'add_order_notes'
			);
			add_settings_field(
				'image_switcher',
				sprintf(
					'%s<div class="wookite_fineprint">%s</div>',
					__('Image switcher', 'wookite'),
					__('Embeds a Front/Back view button for apparel products with double sided printing. Turn off and use Dual Image Style is your theme doesn\'t support this.', 'wookite')
				),
				array($this, 'option_checkbox'),
				'wookite-settings-page',
				'wookite-settings-appearance',
				'image_switcher'
			);
			add_settings_field(
				'dual_image_style',
				sprintf(
					'%s<div class="wookite_fineprint">%s</div>',
					__('Dual image style', 'wookite'),
					__('Alternative display styles for front + back printed apparel.', 'wookite')
				),
				array($this, 'option_dual_image_style'),
				'wookite-settings-page',
				'wookite-settings-appearance'
			);
			add_settings_field(
				'product_images_background',
				sprintf(
					'%s<div class="wookite_fineprint">%s</div>',
					__('Product images background', 'wookite'),
					__('Leave empty for transparent images', 'wookite')
				),
				array($this, 'product_images_background'),
				'wookite-settings-page',
				'wookite-settings-appearance'
			);
			add_settings_section(
				'wookite-settings-product-attributes',
				__('Product attributes', 'wookite'),
				null,
				'wookite-settings-page'
			);
			$idx = 0;
			foreach ((array)$this->get_config('custom_attributes') as $code=>$data) {
				$slug = wookite_slugify($code);
				add_settings_field(
					'product-attribute-' . ($idx++),
					__($data['name'], 'wookite'),
					array($this, 'option_checkbox'),
					'wookite-settings-page',
					'wookite-settings-product-attributes',
					"custom-attribute-$code"
				);
			}
		} elseif ($active_tab === 'shipping') {
			add_settings_section(
				'wookite-settings-defined-shipping-methods',
				sprintf(
					'%s <span id="%s" class="page-title-action">%s</span>',
					__('Defined shipping zones', 'wookite'),
					'autodetect_shipping_zones',
					__('Autodetect the missing ones', 'wookite')
				),
				array($this, 'settings_defined_shipping_zones'),
				'wookite-settings-page'
			);
			foreach ($this->shipping_zones(true) as $zid=>$zname) {
				$frms = $this->shipping_zone2frms($zid);
				foreach ($frms as $frm) {
					$frid = $frm->instance_id;
					$name = sprintf("%s<br />(%s)", $zname, $frm->title);
					add_settings_field(
						"product-defined-shipping-zone-$zid-$frid",
						$name,
						array($this, 'option_defined_shipping_zone'),
						'wookite-settings-page',
						'wookite-settings-defined-shipping-methods',
						array($zid, $zname, $frid)
					);
				}
			}
			add_settings_section(
				'wookite-settings-new-shipping-zones',
				sprintf(
					'%s <span id="%s" class="page-title-action">%s</span>',
					__('Define new shipping zones', 'wookite'),
					'suggest_new_shipping_zones',
					__('Suggest which to create', 'wookite')
				),
				null,
				'wookite-settings-page'
			);
			foreach ($this->kite_shipping_zones() as $zid=>$zname)
				if ($zid != $this->kite_rest_of_the_world())
					add_settings_field(
						"product-new-shipping-zone-$zid",
						__($zname, 'wookite'),
						array($this, 'option_new_shipping_zone'),
						'wookite-settings-page',
						'wookite-settings-new-shipping-zones',
						array($zid, $zname)
					);
		} elseif ($active_tab === 'categories') {
			add_settings_section(
				'wookite-settings-range-categories',
				__('Ranges\' categories', 'wookite'),
				array($this, 'section_range_categories'),
				'wookite-settings-page'
			);
			add_settings_field(
				'range-cat',
				__('Parent category', 'wookite'),
				array($this, 'option_range_category'),
				'wookite-settings-page',
				'wookite-settings-range-categories'
			);
			add_settings_section(
				'wookite-settings-product-categories',
				__('Products\' categories', 'wookite'),
				array($this, 'section_product_categories'),
				'wookite-settings-page'
			);
			$idx = 0;
			foreach ((array)$this->get_config('product_types') as $kite_name) {
				$slug = wookite_slugify($kite_name);
				add_settings_field(
					'product-cat-' . ($idx++),
					__($kite_name, 'wookite'),
					array($this, 'option_product_category'),
					'wookite-settings-page',
					'wookite-settings-product-categories',
					$kite_name
				);
			}
		}
	}

	public function settings_defined_shipping_zones() {
		printf(
			'<p>%s</p>',
			__(
				'Assign each of your pre-existing shipping rates (in ' .
				'the left column) to the most appropriate Kite shipping ' .
				'zone (in the right column).',
				'wookite'
			)
		);
	}

	public function section_range_categories() {
		echo '<p>Choose a category under which each new range\'s category will be automatically created.</p>';
	}

	public function section_product_categories() {
		$cats =
			array('' => __('Don\'t set', 'wookite'), '0' => '') +
			$this->flatten_categories(
				$this->get_woocommerce_categories()
			);
		echo '<table class="form-table"><tbody><tr><th scope="row">';
		_e('Undefined categories', 'wookite');
		printf(
			'</th><td><button id="wookite_cat_make_all" class="page-title-action">%s "%s"</button>',
			__('Set to', 'wookite'),
			__('Create on save', 'wookite')
		);
		printf(' %s: ', __('with parent', 'wookite'));
		echo $this->selection_field_str('cat_make_all_parent', '', $cats);
		echo ' or<br />';
		printf(
			'<button id="wookite_cat_autodetect" class="page-title-action">%s</button> (%s!)',
			__('Try to autodetect categories', 'wookite'),
			__('you\'ll still need to save afterwards', 'wookite')
		);
		echo '</td></tr></tbody></table>';
	}

	public function sc_tid2name($template_id) {
		return sprintf('Kite.ly Merch: %s', $template_id);
	}

	public function delete_shipping_classes($unusedOnly=true) {
		// FIXME - finish this (currently, it deletes all) and call it somewhere
		$terms = get_terms(array(
			'taxonomy' => 'product_shipping_class',
			'hide_empty' => false,
		));
		$prefix = $this->sc_tid2name('');
		$prefix_len = strlen($prefix);
		foreach ($terms as $term)
			if (substr($term->name, 0, $prefix_len) === $prefix)
				@wp_delete_term($term->term_id, 'product_shipping_class');
	}

	protected function kite_shipping_classes() {
		$wc_shipping = new WC_Shipping();
		$shipping_classes = $wc_shipping->get_shipping_classes();
		$shipping_classes_set = array();
		foreach ($shipping_classes as $sc)
			$shipping_classes_set[$sc->name] = true;
		$kite_scs = array();
		foreach ($this->get_config('shipping_classes') as $sc) {
			$tid = $sc['template_id'];
			$name = $this->sc_tid2name($tid);
			if (isset($shipping_classes_set[$name]))
				$terms = get_terms(array(
					'name' => $name,
					'taxonomy' => 'product_shipping_class',
					'hide_empty' => false,
				));
			else
				$terms = null;
			if (!isset($terms) || is_wp_error($terms) || empty($terms)) {
				wp_insert_term($name, 'product_shipping_class', array(
					'description' => sprintf(
						__('Product shipping class for Kite products with template "%s"', 'wookite'),
						$tid
					),
				));
				$terms = get_terms(array(
					'name' => $name,
					'taxonomy' => 'product_shipping_class',
					'hide_empty' => false,
				));
			}
			$term =& $terms[0];
			$sc = array(
				'term_id' => $term->term_id,
				'slug' => $term->slug,
			);
			$kite_scs[$tid] = $sc;
		}
		/*
		foreach ($this->get_config('tid2cost') as $tid=>$cost) {
			$name = $this->sc_tid2name($tid);
			if (isset($shipping_classes_set[$name]))
				$terms = get_terms(array(
					'name' => $name,
					'taxonomy' => 'product_shipping_class',
					'hide_empty' => false,
				));
			else
				$terms = null;
			if (!isset($terms) || is_wp_error($terms) || empty($terms)) {
				wp_insert_term($name, 'product_shipping_class', array(
					'description' => sprintf(
						__('Product shipping class for Kite products with template "%s"', 'wookite'),
						$tid
					),
				));
				$terms = get_terms(array(
					'name' => $name,
					'taxonomy' => 'product_shipping_class',
					'hide_empty' => false,
				));
			}
			$term =& $terms[0];
			$sc = array(
				'term_id' => $term->term_id,
				'slug' => $term->slug,
			);
			$kite_scs[$tid] = $sc;
		}
		*/
		return $kite_scs;
	}

	public function tpl2sc($template_id) {
		static $kite_scs = null;
		if (!isset($kite_scs))
			$kite_scs = $this->kite_shipping_classes();
		return @$kite_scs[$template_id]['slug'];
	}

	protected function sc2cost($sc, $zone_code, $tracked) {
		$min_amount = null;
		$min_price = null;
		for ($i = 0; $i < 2; $i++) {
			// Go for $tracked and, if not found, !$tracked
			foreach ($sc['prices'][$zone_code] as $price)
				if ($price['tracked'] === $tracked)
					if (is_null($min_amount) || $min_amount > $price['amount']) {
						$min_amount = $price['amount'];
						$min_price = $price;
					}
			if (!is_null($min_price))
				return $min_price;
			$tracked = !$tracked;
		}
		return null;
	}

	public function update_shipping_costs($zone_code, $flat_rate_id, $tracked) {
		static $kite_scs = null;
		if (!isset($kite_scs))
			$kite_scs = $this->kite_shipping_classes();
		$option_name = sprintf('woocommerce_flat_rate_%d_settings', $flat_rate_id);
		$fr_settings = get_option($option_name);
		if ($fr_settings === false)
			$fr_settings = array(
				'title' => 'Flat Rate',
				'tax_status' => 'taxable',
				'cost' => '0',
				'class_costs' => '',
				'no_class_cost' => '',
				'type'=> 'class',
			);
		foreach ($this->get_config('shipping_classes') as $sc) {
			$tid = $sc['template_id'];
			$cost = $this->sc2cost($sc, $zone_code, $tracked);
			if (!is_null($cost) && isset($cost['amount']))
				$fr_settings['class_cost_' . $kite_scs[$tid]['term_id']] =
					sprintf('%.2f*[qty]', (float)$cost['amount']);
		}
		update_option($option_name, $fr_settings);
	}

	// Get zone's flat rate methods
	public function shipping_zone2frms($zone) {
		if (is_int($zone))
			$zone = WC_Shipping_Zones::get_zone($zone);
		$res = array();
		foreach ($zone->get_shipping_methods() as $sm)
			if ($sm->id === 'flat_rate')
				$res[] = $sm;
		return $res;
	}

	// Get zone's flat rate methods' ids
	public function shipping_zone2frids($zone) {
		$res = array();
		if (is_int($zone))
			$zone = WC_Shipping_Zones::get_zone($zone);
		if ($zone)
			foreach ($this->shipping_zone2frms($zone) as $frm)
				$res[] = $frm->instance_id;
		return $res;
	}

	public function update_all_shipping_costs($zones=null) {
		if (empty($zones))
			$zones = $this->get_option('shipping-methods');
		if (empty($zones))
			return false;
		foreach ($zones as $frid=>$data) {
			$this->update_shipping_costs(
				$data['kite_zone_code'],
				$frid,
				$data['tracked']
			);
		}
		return true;
	}

	public function posts_autoset_product_images($reset_all=true, $ignore_variants=false) {
		global $wpdb;
		if ($reset_all)
			return $this->kite->get_all_posts_ids();
		return $wpdb->get_col(sprintf(
			'SELECT kite.post_id FROM (
				SELECT DISTINCT kite.post_id AS post_id
					FROM ' . WOOKITE_POSTMETA_TABLE_NAME . ' kite
				INNER JOIN ' . $wpdb->posts . ' wpp ON kite.post_id = wpp.ID
			) kite
			LEFT JOIN (
				SELECT * FROM ' . $wpdb->postmeta . '
					WHERE meta_key="_thumbnail_id"
			) wppm ON kite.post_id = wppm.post_id
			WHERE wppm.meta_id IS NULL%s',
			($ignore_variants? '' : ' OR wppm.meta_value=0')
		));
	}

	public function cnt_autoset_product_images($reset_all=true) {
		if ($reset_all)
			return count($this->kite->get_products_data(null, true));
		return count(
			$this->kite->get_related_products_ids(
				$this->posts_autoset_product_images($reset_all)
			)
		);
	}

	public function autoset_product_images($reset_all=true) {
		global $wpdb;
		$pids = implode(
			',',
			array_keys($this->kite->get_posts_data(null, true))
		);
		$bogus_img_id = $this->get_bogus_image_id();
		if ($pids) {
			if ($reset_all) {
				// Update existing.
				$wpdb->query(sprintf(
					'UPDATE ' . $wpdb->postmeta . ' SET meta_value=%1$d
					WHERE
						post_id IN (%2$s) AND
						meta_key="_thumbnail_id" AND meta_value<>%1$d',
					$bogus_img_id, $pids
				));
			} else {
				// Update variants' (they get zero if the image is removed).
				$wpdb->query(sprintf(
					'UPDATE ' . $wpdb->postmeta . ' SET meta_value=%1$d
					WHERE
						post_id IN (%2$s) AND
						meta_key="_thumbnail_id" AND meta_value=0',
					$bogus_img_id, $pids
				));
			}
		}
		// Add missing.
		$pids = $this->posts_autoset_product_images(false, true);
		if ($pids) {
			$values = array();
			foreach ($pids as $pid)
				$values[] = sprintf('(%d,"_thumbnail_id",%d)', (int)$pid, $bogus_img_id);
			$wpdb->query(sprintf(
				'INSERT INTO ' . $wpdb->postmeta . ' (post_id, meta_key, meta_value)
				VALUES %s',
				implode(',', $values)
			));
		}
	}

	public function tid2vid_autoset_shipping_classes($reset_all=true) {
		global $wpdb;
		$kite_scs = $this->kite_shipping_classes();
		$term_ids = wp_list_pluck($kite_scs, 'term_id');
		$oids = array();
		foreach ($wpdb->get_col(sprintf(
			'SELECT DISTINCT object_id FROM ' . $wpdb->term_relationships . '
			WHERE term_taxonomy_id IN (%s)',
			implode(',', $term_ids)
		)) as $oid)
			$oids[$oid] = true;
		$vtids = $this->kite->get_variants_data(null, 'template_id');
		if (!$vtids) return array();
		$tid2vid = array();
		foreach ($vtids as $vid=>$tid)
			if ($reset_all || !isset($oids[$vid]))
				if (isset($tid2vid[$tid]) && is_array($tid2vid[$tid]))
					$tid2vid[$tid][] = $vid;
				else
					$tid2vid[$tid] = array($vid);
		return $tid2vid;
	}

	public function cnt_autoset_shipping_classes($reset_all=true) {
		$kite_scs = $this->kite_shipping_classes();
		$tid2vid = $this->tid2vid_autoset_shipping_classes($reset_all);
		if (empty($tid2vid)) return 0;
		$all_vids = array();
		foreach ($tid2vid as $tid=>$vids)
			if (isset($kite_scs[$tid]))
				$all_vids = array_merge($all_vids, $vids);
		return count($this->kite->get_variants_products_ids($all_vids));
	}

	public function autoset_shipping_classes($reset_all=true) {
		global $wpdb;
		$kite_scs = $this->kite_shipping_classes();
		$tid2vid = $this->tid2vid_autoset_shipping_classes($reset_all);
		foreach ($tid2vid as $tid=>$vids)
			if (isset($kite_scs[$tid])) {
				$term_id = (int)$kite_scs[$tid]['term_id'];
				$values = array();
				foreach ($vids as $vid)
					$values[] = $wpdb->prepare('(%d, %d)', $vid, $term_id);
				if ($values) {
					if ($reset_all)
						$wpdb->query(sprintf(
							'DELETE FROM ' . $wpdb->term_relationships . '
							WHERE object_id NOT IN (%s) AND term_taxonomy_id=%d',
							implode(', ', $vids), $term_id
						));
					$wpdb->query(sprintf(
						'INSERT INTO ' . $wpdb->term_relationships . '
						(object_id, term_taxonomy_id) VALUES %s
						ON DUPLICATE KEY UPDATE term_taxonomy_id=%d',
						implode(', ', $values), $term_id
					));
					$cnt = $wpdb->get_var(sprintf(
						'SELECT COUNT(*) FROM ' . $wpdb->term_relationships . '
						WHERE term_taxonomy_id=%d',
						$term_id
					));
					$wpdb->update(
						$wpdb->term_taxonomy,
						array('count' => $cnt),
						array('term_id' => $term_id, 'taxonomy' => 'product_shipping_class'),
						array('count' => '%d')
					);
				}
			}
	}

	public function create_category($name, $parent_id=null, $for_range=false) {
		/*
		Creates category with name `$name` under the category with the ID
		`$parent_id` and returns its ID.
		If there already exists a category with the same name and parent,
		then:
		* if `$for_range` is `true`, the name is changed to `"$name $num"`
		for the first available `$num >= 2`;
		* if `$for_range` is `false`, the ID of the existing category is
		returned (without creating a new one).
		If `$parent_id` is given, but there is no category with such an ID,
		the function returns `false` without creating anything.
		*/
		$cats = $this->get_woocommerce_categories();
		$cat_id = $this->get_category_by_name($name, $parent_id);
		if (is_null($cat_id)) return false;
		if ($cat_id !== false)
			if ($for_range) {
				$num = 2;
				while (true) {
					$new_name = sprintf('%s %d', $name, $num++);
					$cat_id = $this->get_category_by_name($new_name, $parent_id);
					if ($cat_id === false) break;
				}
				$name = $new_name;
			} else
				return $cat_id;
		// Check if `$name` already exists under that parent.
		$params = array(
			'name' => $name,
		);
		if ((bool)$parent_id) {
			$parent = get_term((int)$parent_id, 'product_cat');
			$params['parent'] = $parent->term_id;
		}
		if ($for_range)
			$params['image'] = array(
				'id' => $this->get_bogus_image_id(),
			);
		if (!isset($this->categories_controler))
			$this->categories_controler = new WC_REST_Product_Categories_Controller();
		$wp_rest_request = new WP_REST_Request('POST');
		$wp_rest_request->set_body_params($params);
		$res = $this->categories_controler->create_item($wp_rest_request);
		if ($res instanceof WP_Error)
			return false;
		unset($this->wc_categories);
		return $res->data['id'];
		/*
		$term = wp_insert_term($name, 'product_cat', $params);
		if (is_wp_error($term))
			return false;
		else
			return $term['term_id'];
		*/
	}

	public function sanitize_options($input) {
		$res = $this->get_option();
		// Update shipping costs.
		$autoupdate_shipping_costs = false;
		if ($autoupdate_shipping_costs)
			$this->force_config_fetch = true;
		// Kite.
		if (!empty($input['stripeToken'])) {
			$resp = $this->kite->update_billing_card($input['stripeToken']);
			if ($resp === true)
				$this->api->me->update_me();
			else {
				// Error, so ignore the token.
			}
		}
		foreach (array('test', 'live') as $prefix)
			foreach (array('key', 'secret') as $type) {
				$name = "kite-$prefix-$type";
				if (isset($input[$name]))
					$res[$name] = preg_replace('#\W+#', '', $input[$name]);
			}
		if (isset($input['reset-kite-live-mode']))
			$res['kite-live-mode'] = !empty($input['kite-live-mode']);
		if (isset($input['default_markup']))
			$res['default_markup'] = (float)$input['default_markup'];
		if (isset($input['default_published_status']))
			$res['default_published_status'] = $input['default_published_status'];
		if (isset($input['dual_image_style']))
			$res['dual_image_style'] = $input['dual_image_style'];
		if (isset($input['reset-fast_create_products']))
			$res['fast_create_products'] = !empty($input['fast_create_products']);
		// Appearance.
		if (isset($input['image-width']) && isset($input['image-height'])) {
			$width = absint($input['image-width']);
			$height = absint($input['image-height']);
			if ($width >= 17 and $height >= 17)
				$res['product-image-size'] = "${width}x$height";
		}
		if (isset($input['reset-add_order_notes']))
			$res['add_order_notes'] = !empty($input['add_order_notes']);
		if (isset($input['reset-image_switcher']))
			$res['image_switcher'] = !empty($input['image_switcher']);
		if (isset($input['product_images_background']))
			$res['product_images_background'] = substr($input['product_images_background'], 0, 8);
		// Custom attributes.
		foreach ($this->get_config('custom_attributes') as $code=>$data) {
			$fname = "custom-attribute-$code";
			$rname = "reset-$fname";
			if (isset($input[$rname]) && $input[$rname])
				$res[$fname] = (bool)$input[$fname];
		}
		// Shipping zones.
		$kite_zones_data = $this->get_config('shipping_zones');
		if ($kite_zones_data) {
			$shipping_methods = array();
			if (
				isset($input['new-shipping-zones']) &&
				is_array($input['new-shipping-zones'])
			) {
				// Create new zones.
				foreach ($input['new-shipping-zones'] as $zname) {
					if (preg_match('#^(.*)-(standard|tracked|both)$#', $zname, $reOut)) {
						$zname = $reOut[1];
						$which = $reOut[2];
					} else
						continue;
					if (isset($kite_zones_data[$zname])) {
						$data =& $kite_zones_data[$zname];
						$zone = new WC_Shipping_Zone();
						$zone->set_zone_name(__($zname, 'wookite'));
						$zone->set_locations($data['locations']);
						$zone->save();
						$trackeds = array();
						if ($which === 'both' || $which === 'standard')
							$trackeds[] = false;
						if ($which === 'both' || $which === 'tracked')
							$trackeds[] = true;
						$code = $kite_zones_data[$zname]['code'];
						foreach ($trackeds as $tracked) {
							$frid = $zone->add_shipping_method('flat_rate');
							$zone_id = (
								method_exists($zone, 'get_id') ?
								$zone->get_id() : # WC 3.0+
								$zone->get_zone_id() # WC<3.0
							);
							$shipping_methods[$frid] = array(
								"kite_zone_code" => $code,
								"tracked" => $tracked,
							);
						}
					}
				}
			}
			// Assign existing zones.
			$existing_zones = $this->shipping_zones(true);
			$edited_some_zone = false;
			foreach ($existing_zones as $zid=>$zname) {
				$frids = $this->shipping_zone2frids($zid);
				if (!$frids) {
					$zone = new WC_Shipping_Zone($zid);
					$frids = array($zone->add_shipping_method('flat_rate'));
				}
				foreach ($frids as $frid) {
					if (isset($input["shipping_zone_${zid}_$frid"])) {
						$edited_some_zone = true;
						if (empty($input["shipping_zone_${zid}_$frid"])) {
							unset($shipping_methods[$frid]);
						} else {
							$kite_code = $input["shipping_zone_${zid}_$frid"];
							$tracked = (
								isset($input["shipping_zone_tracked_${zid}_$frid"]) &&
								$input["shipping_zone_tracked_${zid}_$frid"] === 'tracked'
							);
							$shipping_methods[$frid] = array(
								"kite_zone_code" => $kite_code,
								"tracked" => $tracked,
							);
						}
					}
				}
			}
			// Save data.
			if ($shipping_methods || $edited_some_zone) {
				$res['shipping-methods'] = $shipping_methods;
				$this->update_all_shipping_costs($shipping_methods);
			}
		}
		// Categories.
		if (isset($input['range_parent_cat']))
			$res['range_parent_cat'] = (
				$input['range_parent_cat'] === '' ?
				'' :
				(int)$input['range_parent_cat']
			);
		$pt2cat = array(); // product_type => category.
		$idx = 0;
		while (true) {
			$pt = @$input['product_type_'.$idx];
			$cat = @$input['category_'.$idx];
			if (!(isset($pt) || isset($cat)))
				break;
			if (isset($pt) && isset($cat)) {
				if ($cat === "") // Create new.
					$cat = $this->create_category($pt, $input['parent_'.$idx]);
				if ($cat) // "0" == "Ignore".
					$pt2cat[$pt] = (int)$cat;
			}
			$idx++;
		}
		if ($pt2cat)
			$res['pt2cat'] = $pt2cat;
		return $res;
	}

	public function input_field($name, $value, $size=40, $maxlength=null) {
		printf(
			'<input name="wookite_options[%1$s]" id="%1$s" value="%2$s" size="%3$d" %4$s/>',
			$name,
			(isset($value) ? $value : ''),
			$size,
			(isset($maxlength) ? "maxlength=\"$maxlength\" " : '')
		);
	}
	
	public function input_int_field($name, $value, $size=7, $min=null, $max=null) {
		printf(
			'<input name="wookite_options[%1$s]" id="%1$s" value="%2$s" size="%3$d" type="number"',
			$name, $value, $size
		);
		if (isset($min))
			printf(' min="%g"', $min);
		if (isset($max))
			printf(' max="%g"', $max);
		echo ' />';
	}

	public function option_credit_card() {
		$me = $this->me();
		if ($me === false)
			printf(
				'<div id="wookite_payment_errors">%s%s</div>',
				__('Synchronization with Kite has failed.<br />The most common cause of this problem is using the plug-in with an email address that is already registered to a Kite account. If this is the case, you should receive an email that will allow you to join the accounts via a confirmation link.', 'wookite'),
				(
					isset($_GET['done']) && $_GET['done'] === 'kite_reregister' ?
					'' :
					' ' . sprintf(
						__(
							' In case you didn\'t receive an e-mail, ' .
							'please <a href="%s">click here to have it ' .
							'resent to you</a>.',
							'wookite'
						),
						admin_url(
							'admin.php?page=wookite-tools&action=kite_rer' .
							'egister&redirect=admin.php%3Fpage%3Dwookite-' .
							'settings%26done%3Dkite_reregister&_wpnonce=' .
							esc_attr(wp_create_nonce('wookite-tools'))
						)
					)
				)
			);
		else {
			$has_card = (!empty($me['card']));
?>
  <div class="wookite-form-row wookite-notification">
    <p style="font-weight: normal;"><?php _e('Card details must be completed in order for us to fulfil your Kite orders. Kite does not store your credit card information, and will charge you only for the wholesale + shipping costs of your orders via Stripe.', 'wookite'); ?></p>
    <p style="font-weight: normal;"><?php _e('If you just want to test Kite.ly Merch Plugin for now, you can turn off Kite live mode below. Everything will work as it should (even without supplying credit card info), but you won’t be charged and your test orders will not be fulfilled.', 'wookite'); ?></p>
  </div>
<?php
			if ($has_card) {
?>
  <div id="wookite_payment_errors"></div>
  <div id="existing_card">
    <div class="wookite-form-row">
      <span><?php _e('Current card', 'wookite'); ?></span>
      <span class="wookite-value"><?php echo $me['card']; ?></span>
      <button id="revoke_card" data-wpnonce="<?php echo wp_create_nonce( 'wookite-frontend' ); ?>"><?php _e('Delete card', 'wookite'); ?></button>
    </div>
  </div>
<?php
			}
?>
  <div class="wookite"<?php if ($has_card) echo ' id="new_card"'; ?>>
    <div class="wookite-form-row">
      <label>
        <span><?php _e('Card Number', 'wookite'); ?></span>
        <input type="text" size="20" data-stripe="number">
      </label>
    </div>

    <div class="wookite-form-row">
      <label>
        <span><?php _e('Expiration (MM/YY)'); ?></span>
        <select data-stripe="exp_month">
          <option value="" selected="selected"></option>
<?php
			foreach (range(1,12) as $m)
				printf('        <option value="%1$02d">%1$02d</option>', $m);
?>
        </select>
      <span> / </span>
        <select data-stripe="exp_year">
          <option value="" selected="selected"></option>
<?php
			$y = (int)date('Y');
			foreach (range($y,$y+get_option('wookite-max-card-years', 100)-1) as $y)
				printf('        <option value="%d">%04d</option>', $y % 100, $y);
?>
        </select>
      </label>
    </div>

    <div class="wookite-form-row">
      <label>
        <span><?php _e('CVC', 'wookite'); ?></span>
        <input type="text" size="4" data-stripe="cvc">
      </label>
    </div>
  </div>
<?php
		}
	}

	public function option_checkbox($name, $value=null, $default=true) {
		printf(
			'<input name="wookite_options[reset-%1$s]" value="1" type="hidden" />' .
			'<input name="wookite_options[%1$s]" id="%1$s" value="1" type="checkbox"',
			$name
		);
		if (!isset($value))
			$value = $this->get_option($name);
		if ((isset($value) && $value) || (!isset($value) && $default))
			printf(' checked="checked"');
		echo ' />';
	}

	public function option_checkbox_false($name, $value=null, $default=false) {
		$this->option_checkbox($name, $value, $default);
	}

	public function shipping_zones($as_array=false) {
		if (!isset($this->all_zones)) {
			$this->all_zones = WC_Shipping_Zones::get_zones();
			$rotw = new WC_Shipping_Zone(0);
			$rotw_data = $rotw->get_data();
			$rotw_data['zone_id'] = $rotw->get_id();
			$rotw_data['formatted_zone_location'] = $rotw->get_formatted_location();
			$rotw_data['shipping_methods'] = $rotw->get_shipping_methods();
			$this->all_zones[$this->get_wc_id($rotw)] = $rotw_data;
		}
		if ($as_array) {
			$res = array();
			foreach ($this->all_zones as $zone)
				$res["$zone[zone_id]"] = $zone['zone_name'];
			return $res;
		}
		return $this->all_zones;
	}

	public function get_woocommerce_categories() {
		if (!isset($this->wc_categories)) {
			// Adapted from http://stackoverflow.com/a/21012252/1667018 .
			$taxonomy     = 'product_cat';
			$orderby      = 'name';  
			$show_count   = 0;      // 1 for yes, 0 for no.
			$pad_counts   = 0;      // 1 for yes, 0 for no.
			$hierarchical = 1;      // 1 for yes, 0 for no. 
			$title        = '';  
			$empty        = 0;

			$args = array(
				'taxonomy'     => $taxonomy,
				'orderby'      => $orderby,
				'show_count'   => $show_count,
				'pad_counts'   => $pad_counts,
				'hierarchical' => $hierarchical,
				'title_li'     => $title,
				'hide_empty'   => $empty
			);
			$categories = get_categories($args);
			$id2cat = array();
			for ($i = 0; $i < count($categories); $i++) {
				$categories[$i]->sub = array();
				$id2cat[$categories[$i]->term_id] = $categories[$i];
			}
			foreach ($id2cat as $id=>$cat)
				if ($cat->parent)
					$id2cat[$cat->parent]->sub[] =& $id2cat[$id];
			$res = array();
			foreach ($id2cat as $id=>$cat)
				if (!$cat->parent)
					$res[] = $cat;
			$this->wc_categories = $res;
		}
		return $this->wc_categories;
	}

	private function get_category_by_name_rec($name, &$cats, $parent_id) {
		foreach ((array)$cats as $cat) {
			if ($cat->term_id == $parent_id)
				if (is_array($cat->sub))
					return $this->get_category_by_name_in_list($name, $cat->sub);
				else
					return false;
			if (!empty($cat->sub)) {
				$res = $this->get_category_by_name_rec($name, $cat->sub, $parent_id);
				if ($res !== null) return $res;
			}
		}
		return null;
	}

	public function get_category_by_name_in_list($name, &$cats) {
		foreach ($cats as $cat)
			if ($cat->name == $name)
				return $cat->term_id;
		return false;
	}

	public function get_category_by_name($name, $parent_id=null) {
		/*
		Returns `term_id` of a category with name `$name` and parent with
		the ID `parent_id`. If `$name` was not found under the category
		with `term_id == $parent_id`, the function returns `false`.
		If there is no category with `term_id == $parent_id`, the function
		returns `null`.
		If `empty($parent_id)`, the top level is searched.
		*/
		$cats = $this->get_woocommerce_categories();
		if (empty($parent_id))
			return $this->get_category_by_name_in_list($name, $cats);
		return $this->get_category_by_name_rec($name, $cats, $parent_id);
	}

	public function flatten_categories($cats, $prefix='') {
		/*
		If you change `$insert_prefix` you must also update
		the regex in `var name =` line in the click handler
		for `$('#wookite_cat_autodetect')` in `script.js`!
		*/
		static $insert_prefix = '&nbsp;&nbsp;';
		$res = array();
		foreach ($cats as $cat) {
			$res[$cat->term_id] = $prefix . $cat->name;
			if (!empty($cat->sub))
				$res += $this->flatten_categories($cat->sub, $insert_prefix . $prefix);
		}
		return $res;
	}

	public function categories_select($product_type) {
		static $cats = null, $parent_cats = null;
		static $idx = 0;
		$value = $this->get_option('pt2cat');
		if (!isset($cats)) {
			$cats = $this->flatten_categories(
				$this->get_woocommerce_categories()
			);
			$parent_cats = array("0" => "") + $cats;
			$cats = array(
				'Kite.ly Merch' => array(
					"" => __('Create on save', 'wookite'),
					"0" => __('Ignore', 'wookite'),
				),
				'User defined' => $cats,
			);
		}
		$value = (isset($value[$product_type]) ? $value[$product_type] : "0");
		$esc_pt = esc_attr($product_type);
		printf(
			'<input type="hidden" name="wookite_options[product_type_%d]" value="%s" />',
			$idx, $esc_pt
		);
		$this->selection_field(
			'category_'.$idx, "$value", $cats, true,
			sprintf('class="select-cat" data-pt="%s"', $esc_pt)
		);
		printf(' %s: ', __('with parent', 'wookite'));
		$this->selection_field('parent_'.$idx, "0", $parent_cats, false);
		$idx++;
	}

	public function selection_field_str($name, $value, $values, $enabled=true, $extra_html=null) {
		$res = sprintf('<select name="wookite_options[%1$s]" id="wookite_%1$s"', $name);
		if (!$enabled)
			$res .= ' disabled="disabled"';
		if (!empty($extra_html))
			$res .= ' ' . $extra_html;
		$res .= '>';
		foreach ($values as $id=>$val)
			if (is_array($val)) {
				$res .= sprintf('<optgroup label="%s">', esc_attr($id));
				foreach ($val as $id2=>$val2)
					$res .= sprintf(
						'<option value="%s"%s>%s</option>',
						$id2,
						("$id2" === $value ? ' selected' : ''),
						$val2
					);
				$res .= '</optgroup>';
			} else
				$res .= sprintf(
					'<option value="%s"%s>%s</option>',
					$id,
					($id === $value ? ' selected' : ''),
					$val
				);
		$res .= '</select>';
		return $res;
	}

	public function selection_field($name, $value, $values, $enabled=true, $extra_html=null) {
		echo $this->selection_field_str($name, $value, $values, $enabled, $extra_html);
	}

	public function option_int($name) {
		$this->input_int_field($name, $this->get_option($name), 7, 0);
	}

	public function option_percent($name) {
		$this->input_int_field($name, $this->get_option($name), 3, 0, 999);
		echo ' %';
	}

	public function option_default_published_status() {
		$this->selection_field(
			'default_published_status',
			$this->get_option('default_published_status'),
			array('publish' => 'Published (public)', 'private' => 'Private')
		);
	}

	public function option_product_image_size() {
		$image_size = $this->get_option('product-image-size');
		if (
			isset($image_size) and
			preg_match('#^(\d+)x(\d+)$#i', $image_size, $reOut)
		) {
			$image_width = $reOut[1];
			$image_height = $reOut[2];
		} else {
			$image_width = 1200;
			$image_height = 1200;
		}
		$this->input_field('image-width', $image_width, 4, 4);
		echo "px<span style=\"font-size: 171%; vertical-align: bottom;\"> &#215; </span>";
		$this->input_field('image-height', $image_height, 4, 4);
		echo "px";
	}

	function option_dual_image_style() {
		$this->selection_field(
			'dual_image_style',
			$this->get_option('dual_image_style'),
			array(
				'bfsb' => 'Big front, small back',
				'bbsf' => 'Big back, small front',
				'fo' => 'Front only',
				'bo' => 'Back only',
			)
		);
	}

	function product_images_background() {
		$value = $this->get_option('product_images_background');
		echo '#';
		$this->input_field('product_images_background', $value, $size=9, $maxlength=8);
	}

	public function option_defined_shipping_zone($zone_data) {
		list($zid, $zname, $frid) = $zone_data;
		$shipping_methods = $this->get_option('shipping-methods');
		$kite_zones = $this->kite_shipping_zones(true);
		$guess = "";
		$guess_tracked = 'standard';
		if (!isset($this->shipping_zone_guesses))
			$this->shipping_zone_guesses = array();
		if (isset($shipping_methods[$frid]['kite_zone_code'])) {
			$value = $shipping_methods[$frid]['kite_zone_code'];
			$trValue = $shipping_methods[$frid]['tracked'];
			$this->shipping_zone_guesses[$value] = true;
		} else {
			$value = "";
			$trValue = false;
			$zname = __($zname, 'wookite');
			$sm = new WC_Shipping_Flat_Rate($frid);
			foreach (array('tracked', __('tracked', 'wookite')) as $word)
				if (preg_match('#^(?:.*?)\W*' . $word . '\W*(?:.*?)$#i', $sm->title, $reOut)) {
					$guess_tracked = 'tracked';
					break;
				}
			if (empty($guess))
				foreach ($kite_zones as $id=>$name)
					if ($zname === $name) {
						$guess = $id;
						break;
					}
			if (empty($guess)) {
				$zname = strtolower($zname);
				foreach ($kite_zones as $name)
					if ($zname === strtolower($name)) {
						$guess = $id;
						break;
					}
			}
			if (empty($guess)) {
				$zname = preg_replace('#\W+#', '', $zname);
				foreach ($kite_zones as $name)
					if ($zname === preg_replace('#\W+#', '', strtolower($name))) {
						$guess = $id;
						break;
					}
			}
			if (empty($guess))
				$guess = $this->kite_rest_of_the_world();
		}
		$data_span = (!empty($guess) && !empty($guess_tracked));
		if ($data_span)
			printf(
				'<span data-guess="%s" data-guess-tracked="%s" ' .
				'class="guessable_shipping_zone">',
				$guess,
				$guess_tracked
			);
		$this->selection_field(
			"shipping_zone_${zid}_$frid",
			$value,
			$kite_zones,
			true
		);
		echo " ", __('Prefer tracking', 'wookite'), '? ';
		$this->selection_field(
			"shipping_zone_tracked_${zid}_$frid",
			($trValue ? "tracked" : "standard"),
			array("standard" => "No", "tracked" => "Yes"),
			true
		);
		if (!empty($guess)) {
			$this->shipping_zone_guesses[$guess] = true;
			printf(
				'<span class="%s" data-select="%s">%s</span>',
				'wookite_shipping_zone_autodetect button',
				"wookite_shipping_zone_$zid",
				__('Autodetect', 'wookite')
			);
		}
		if ($data_span) echo '</span>';
	}

	public function option_new_shipping_zone($zone_data) {
		list($zid, $zname) = $zone_data;
		printf(
			'<select name="wookite_options[new-shipping-zones][]" ' .
			'id="new-shipping-zone-%1$s" class="new_shipping_zone" ' .
			'data-suggested="%2$s"><option value="">%3$s</option>' .
			'<option value="%1$s-standard">%4$s</option>' .
			'<option value="%1$s-tracked">%5$s</option>' .
			'<option value="%1$s-both">%6$s</option>' .
			'</select>',
			$zname,
			(isset($this->shipping_zone_guesses[$zid]) ? '' : "$zname-standard"),
			__('Don\'t create', 'wookite'),
			__('Create untracked', 'wookite'),
			__('Create tracked', 'wookite'),
			__('Create both', 'wookite')
		);
	}

	public function option_range_category($product_type) {
		$cats = array(
			"" => "Don't autocreate ranges' categories",
			"0" => "Top level",
		) + $this->flatten_categories(
			$this->get_woocommerce_categories(), '&nbsp;&nbsp;'
		);
		$this->selection_field('range_parent_cat', $this->get_option('range_parent_cat'), $cats);
	}

	public function option_product_category($product_type) {
		$this->categories_select($product_type);
	}

	public function settings() {
		#$this->options = get_option('wookite_options');
		$this->head("Settings");
		$active_tab = (isset($_GET['tab']) ? sanitize_title( $_GET['tab'] ) : 'general');
?>
		<h2 class="nav-tab-wrapper">
			<a href="?page=wookite-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General', 'wookite'); ?></a>
			<a href="?page=wookite-settings&tab=shipping" class="nav-tab <?php echo $active_tab == 'shipping' ? 'nav-tab-active' : ''; ?>"><?php _e('Shipping', 'wookite'); ?></a>
			<a href="?page=wookite-settings&tab=categories" class="nav-tab <?php echo $active_tab == 'categories' ? 'nav-tab-active' : ''; ?>"><?php _e('Categories', 'wookite'); ?></a>
		</h2>
<?php
		echo '<form method="post" action="options.php" class="wookite" id="wookite_settings">';
		settings_fields('wookite_option_group');
		do_settings_sections('wookite-settings-page');
		submit_button("Save settings", "primary", "submitBtn");
		echo "</form>\n";
		$this->foot();
	}

	public function tools() {
		$this->head('Tools');
		$this->tools->frontend();
		$this->foot();
	}

	public function pids2pids($pids) {
		global $wpdb;
		/*
		Get only the IDs of the actual posts
		(should be the same as the above, but one can never be sure)
		*/
		if ($pids)
			$pids = $wpdb->get_col(sprintf(
				'SELECT ID FROM ' . $wpdb->posts . ' WHERE ID IN (%s)',
				implode(',', (array)$pids)
			));
		if ($pids) {
			// Add their children, grandchildren, etc.
			$old_pids_cnt = 0;
			while ($old_pids_cnt < count($pids)) {
				$old_pids_cnt = count($pids);
				$pids = $wpdb->get_col(sprintf(
					'SELECT ID FROM ' . $wpdb->posts . ' WHERE ' .
					'ID IN (%1$s) OR post_parent IN (%1$s)',
					implode(',', $pids)
				));
			}
		}
		return $pids;
	}

	protected function remove_blog_data() {
		global $wpdb;
		$tables_with_posts = array(
			$wpdb->posts => 'ID',
			$wpdb->postmeta => 'post_id',
			$wpdb->comments => 'comment_post_ID',
			$wpdb->term_relationships => 'object_id',
		);
		$tables_with_terms = array(
			$wpdb->termmeta, $wpdb->terms, $wpdb->term_taxonomy
		);

		// Get the IDs of the posts to be deleted.
		$pids = $this->pids2pids($wpdb->get_col(
			'SELECT DISTINCT post_id FROM ' . WOOKITE_POSTMETA_TABLE_NAME
		));
		$oids = $this->pids2pids($wpdb->get_col(
			'SELECT DISTINCT order_id FROM ' . WOOKITE_ORDERS_TABLE_NAME
		));
		$options_where = array();
		if ($pids || $oids) {
			$commented_posts_ids = array();
			foreach ($pids as $pid)
				$commented_posts_ids[(int)$pid] = true;
			foreach ($oids as $oid)
				$commented_posts_ids[(int)$oid] = true;
			$commented_posts_ids = array_keys($commented_posts_ids);
			foreach ($commented_posts_ids as $cpid)
				$options_where[] = "option_name LIKE \"_transient_%_$cpid\"";
			$commented_posts_ids = implode(',', $commented_posts_ids);
			// Get the IDs of the comments to be deleted.
			$cids = $wpdb->get_col(sprintf(
				'SELECT DISTINCT comment_ID FROM ' . $wpdb->comments .
				'WHERE comment_post_ID IN (%s)',
				$commented_posts_ids
			));
			if ($cids) {
				// Delete those comments' meta.
				$wpdb->query(sprintf(
					'DELETE FROM ' . $wpdb->commentmeta . ' WHERE ID in (%s)',
					implode(',', $cids)
				));
			}
			// Delete posts and their data.
			foreach ($tables_with_posts as $table=>$id_field)
				$wpdb->query(sprintf(
					'DELETE FROM %s WHERE %s in (%s)',
					$table, $id_field, $commented_posts_ids
				));
		}
		// Delete images (bogus image, products)
		foreach (array('', '-product') as $type) {
			$bogus_img_posts = get_posts(array(
				'numberposts'	=> -1,
				'post_type'		=> 'attachment',
				'meta_key'		=> "wookite$type",
				'meta_value'	=> 'yes'
			));
			foreach ($bogus_img_posts as $post) {
				// Delete the media file.
				wp_delete_attachment($post->ID, true);
				/*
				In case the previous failed (maybe the files were deleted,
				but the post was not), delete the post
				*/
				wp_delete_post($post->ID, true);
			}
		}
		/*
		Get the IDs of the WooKite-related terms
		(dynamically created categories and shipping classes)
		*/
		$tids = array();
		foreach ($wpdb->get_col(
			'SELECT DISTINCT category_id FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME
		) as $cat_id)
			$tids[(int)$cat_id] = true;
		foreach ($wpdb->get_col(
			'SELECT DISTINCT term_id FROM ' . $wpdb->terms . ' WHERE slug LIKE "wookite%"'
		) as $term_id)
			$tids[(int)$term_id] = true;
		if ($tids) {
			$tids = implode(',', array_keys($tids));
			// Get those terms' taxonomy IDs.
			$ttids = $wpdb->get_col(sprintf(
				'SELECT DISTINCT term_taxonomy_id FROM ' . $wpdb->term_taxonomy . '
				WHERE term_id IN (%s)',
				$tids
			));
			// Delete everything related to those terms.
			foreach ($tables_with_terms as $table)
				$wpdb->query(sprintf(
					'DELETE FROM ' . $table . ' WHERE term_id IN (%s)',
					$tids
				));
			if ($ttids)
				$wpdb->query(sprintf(
					'DELETE FROM ' . $wpdb->term_relationships . ' WHERE term_taxonomy_id IN (%s)',
					implode(',', $ttids)
				));
		}
		// Delete WooKite's posts' meta and options.
		$wpdb->query('DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key LIKE "wookite%"');
		$options_where[] = 'option_name LIKE "wookite%"';
		$wpdb->query('DELETE FROM ' . $wpdb->options . ' WHERE ' . implode(' OR ', $options_where));
		// Remove shipping classes
		$this->delete_shipping_classes();
	}

	public function uninstall($deactivate_plugin=false) {
		global $wpdb;
		// Disable the shutdown action that relies on WooKite's tables.
		remove_action('shutdown', array($this->kite, 'shutdown'), 0);

		// Code adapted from http://wordpress.stackexchange.com/a/80351/102034 .
		if (is_multisite()) {
			global $wpdb;
			$blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
			if ($blog_ids) {
				$this->set_max_execution_time(61 * (1 + count($blog_ids)));
				$original_blog_id = get_current_blog_id();
				foreach ($blog_ids as $blog_id) {
					switch_to_blog($blog_id);
					$this->remove_blog_data();
				}
				switch_to_blog( $original_blog_id );
			}
		}

		// Drop all WooKite's tables.
		$this->remove_blog_data();
		foreach ($wpdb->get_col('SHOW TABLES IN ' . DB_NAME . ' LIKE "%_wookite_%"') as $table)
			$wpdb->query("DROP TABLE IF EXISTS $table");

		// If needed, deactivate the plugin.
		if ($deactivate_plugin)
			deactivate_plugins(dirname(__FILE__) . '/kite-print-and-dropshipping-on-demand.php');
	}

}

function wookite_mb_strtolower($str, $encoding = null) {
	if (extension_loaded('mbstring')) {
		if (null === $encoding)
			$encoding = mb_internal_encoding();
		return mb_strtolower($str, $encoding);
	} else
		return strtolower($str);
}

function wookite_mb_strcasecmp($str1, $str2, $encoding = null) {
	if (extension_loaded('mbstring')) {
		/*
		By chris at cmbuckley dot co dot uk
		http://php.net/manual/en/function.strcasecmp.php
		*/
		if (null === $encoding)
			$encoding = mb_internal_encoding();
		return strcmp(mb_strtoupper($str1, $encoding), mb_strtoupper($str2, $encoding));
	} else
		return strcmp(strtoupper($str1), strtoupper($str2));
}

function wookite_zones_cmp($a, $b) {
	if (empty($a['locations']))
		return (empty($b['locations']) ? 0 : 1);
	elseif (empty($b['locations']))
		return -1;
	return wookite_mb_strcasecmp($a['name'], $b['name']);
}

?>
