<?php

/*
Handled URLs:

Initiate product creation:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=publish_product
POST: JSON [ {"image_url": image_url, "image_url_preview": image_url_preview } ] (list of images' URLs)
Returns: JSON {"code": new_job_code_string }

Product publishing:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=publish_product&job=$job_code
POST: JSON { "create": list_of_product_ranges }
Returns: nothing

Product updating:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=publish_product&job=$job_code
POST: JSON { "update": list_of_products }
Returns: nothing

Get job progress:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=publish_product&job=<job_code>
Returns: A JSON dictionary describing the job (you want the "progress" and "last_added" fields)
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'WOOKITE_JOBS_TABLE_NAME', $wpdb->prefix . 'wookite_jobs' );

class WooKiteEndpointPublishProduct extends WooKiteEndpoint {

	static $endpoint = 'publish_product';

	public function __construct( $plugin ) {
		parent::__construct( $plugin );
		dbDelta('CREATE TABLE ' . WOOKITE_JOBS_TABLE_NAME . ' (
            id bigint(20) unsigned NOT NULL auto_increment,
            time_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            code varchar(39) DEFAULT "",
            progress TINYINT(1) UNSIGNED DEFAULT 0,
            last_added varchar(255) DEFAULT "",
            PRIMARY KEY  (id)
        )');
	}

	public function handle_url() {
		$this->job_code = (isset( $_GET['job'] ) ? $_GET['job'] : null);
		if ( ! empty( $this->post ) ) {
			if ( ! empty( $this->post['update'] ) ) {
				wookite_json_output( $this->update( $this->post['update'] ) );
			}
			if ( ! empty( $this->post['create'] ) ) {
				wookite_json_output( $this->new_job() );
			}
			if ( ! empty( $this->post['publish'] ) ) {
				wookite_json_output( $this->create( $this->post['publish'] ) );
			}
			wookite_json_error( 'E00', 'POST requests confuse you, do they? :-P' );
		}
		if ( empty( $this->job_code ) ) {
			wookite_json_error( 'E01', 'Missing job code' );
		} else { $this->output_job_data();
		}
	}

	protected function new_job() {
		global $wpdb;
		$kfj = get_option( 'wookite_keep_finished_job', '1 day' );
		$kuj = get_option( 'wookite_keep_unfinished_job', '1 month' );
		$wpdb->query('DELETE FROM ' . WOOKITE_JOBS_TABLE_NAME . " WHERE
            (code IS NULL or code = \"\") or
            (progress = 100 and time_updated + INTERVAL $kfj < NOW()) or
            (progress < 100 and time_updated + INTERVAL $kuj < NOW())
        ");
		$wpdb->insert(
			WOOKITE_JOBS_TABLE_NAME,
			array(
				'code' => '',
			)
		);
		$job_id = $wpdb->insert_id;
		$code = implode( '-', str_split( md5( 'ǝʇı⋊ooM' . $job_id ), 4 ) );
		$wpdb->update(
			WOOKITE_JOBS_TABLE_NAME,
			array( 'code' => $code ),
			array( 'id' => $job_id )
		);
		$this->current_job_step = 0;
		return array( 'code' => $code );
	}

	protected function job_step( $name ) {
		global $wpdb;
		$this->current_job_step++;
		if ( $this->job_steps_total ) {
			$wpdb->update(
				WOOKITE_JOBS_TABLE_NAME,
				array(
					'progress' => round( 100 * $this->current_job_step / $this->job_steps_total ),
					'last_added' => $name,
				),
				array( 'code' => $this->job_code ),
				array( 'progress' => '%d', 'last_added' => '%s' )
			);
		}
	}

	protected function hsc( $str ) {
		return wp_kses( $str, 'post' );
		// return htmlspecialchars($str, ENT_COMPAT | ENT_HTML401, ini_get("default_charset"), false);
	}

	protected function post_sql(
		$site_url, $post_type, $post_title, $post_content, $post_status,
		$comment_status, $post_author, $post_date, $post_date_gmt,
		$post_parent = 0, $menu_order = 0
	) {
		global $wpdb;
		$post_name = sanitize_title( $post_title );
		if ( $post_name && isset( $this->post_names[ $post_name ] ) ) {
			$base_post_name = $post_name;
			$num = 2;
			while ( isset($this->post_names[
				$post_name = sprintf( '%s-%d', $base_post_name, $num++ )
			]) ) {}
		}
		$this->post_names[ $post_name ] = true;
		$guid = "$site_url/$post_type/$post_name/";
		return $wpdb->prepare(
			'(%s,%s,%s,%s,%s,"",%s,"%s","closed","",%s,"","",%s,%s,"",%s,%s,%s,%s,"",0)',
			$post_author, $post_date, $post_date_gmt, $this->hsc( $post_content ),
			ucwords( $this->hsc( $post_title ) ), $post_status, $comment_status,
			$post_name, $post_date, $post_date_gmt, $post_parent, $guid,
			$menu_order, $post_type
		);
	}

	public function get_woocommerce_product_type_term_id( $product_type = 'variable' ) {
		global $wpdb;
		return $wpdb->get_var($wpdb->prepare(
			'SELECT terms.term_id FROM ' . $wpdb->terms . ' terms
            INNER JOIN ' . $wpdb->term_taxonomy . ' term_taxonomy
            ON terms.term_id = term_taxonomy.term_id
            WHERE
                term_taxonomy.taxonomy = "product_type" AND
                terms.name = "%s"
            ', $product_type
		));
	}

	protected function post_attrib( $post_id, $attr ) {
		global $wpdb;
		return $wpdb->prepare(
			'(%d,%s,%s),',
			$post_id,
			'attribute_' . sanitize_title( $attr['name'] ),
			$attr['option']
		);
	}

	protected function create_products_fast( $products ) {
		global $wpdb, $woocommerce;
		$uid = get_current_user_id();
		$site_url = get_site_url();
		$now = time();
		$post_date = date( 'y-m-d H:i:s', $now );
		$post_date_gmt = gmdate( 'y-m-d H:i:s', $now );
		$wc_variable_id = $this->get_woocommerce_product_type_term_id();
		if ( empty( $wc_variable_id ) ) { $wc_variable_id = 0; // for testing purposes only
		}
		$wc_ver = $woocommerce->version;
		$bogus_img = $this->plugin->get_bogus_image_id();
		$res = array();
		$res_pidx = 0;

		// Begin
		$wpdb->query( 'START TRANSACTION' );

		// Cache terms
		$terms = array();
		foreach ( $wpdb->get_results(
			'SELECT term_id, slug FROM ' . $wpdb->terms,
			ARRAY_A
		) as $row ) {
			$terms[ $row['slug'] ] = (int) $row['term_id'];
		}

		// Cache post names (those need to be unique)
		$this->post_names = array();
		foreach ( $wpdb->get_col( "SELECT post_name FROM $wpdb->posts" ) as $pn ) {
			$this->post_names[ $pn ] = true;
		}

		// Get existing post names that might be relevant for our new products
		$product_slugs = array();
		$cnt = count( $products );
		for ( $i = 0; $i < $cnt; $i++ ) {
			$products[ $i ]['slug'] = sanitize_title( $products[ $i ]['name'] );
			$product_slugs[ $products[ $i ]['slug'] ] = true;
		}
		$where = array();
		foreach ( array_keys( $product_slugs ) as $slug ) {
			$where[] = "post_name LIKE \"$slug%\"";
		}
		$product_slugs = array();
		foreach ( $wpdb->get_col(sprintf(
			'SELECT post_name FROM %s WHERE %s',
			$wpdb->posts,
			implode( ' OR ', $where )
		)) as $slug ) {
			$product_slugs[ $slug ] = true;
		}
		// Prepare new post names that won't collide with the old ones
		for ( $i = 0; $i < $cnt; $i++ ) {
			$slug = $products[ $i ]['slug'];
			if ( isset( $product_slugs[ $slug ] ) ) {
				$idx = 1;
				while ( isset(
					$product_slugs[ $new_slug = sprintf( '%s-%d', $slug, $idx ) ]
				) ) {$idx++;
				}
				$products[ $i ]['slug'] = $slug = $new_slug;
			}
			$product_slugs[ $slug ] = true;
		}

		// Prepare SQL for inserting posts (needed for products and variants)
		$post_insert_sql = "INSERT INTO $wpdb->posts (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES ";

		// Insert the main products (no metadata)
		$sql = $post_insert_sql;
		$first = true;
		$delimiter = '';
		foreach ( $products as $product ) {
			$sql .= $delimiter . $this->post_sql(
				$site_url,
				$product['post_type'],
				$product['name'],
				$product['description'],
				$product['status'],
				'open',
				$uid,
				$post_date,
				$post_date_gmt,
				0,
				0
			);
			if ( $first ) {
				$first = false;
				$delimiter = ',';
			}
		}
		$wpdb->query( $sql );
		$first_post_id = $wpdb->insert_id;

		// Insert variations (no metadata)
		$pidx = 0;
		$post_id = $first_post_id;
		$sql = $post_insert_sql;
		$first = true;
		$delimiter = '';
		foreach ( $products as $product ) {
			$mo = 0;
			$vidx = 0;
			foreach ( $product['variations'] as $variation ) {
				$var_title = '';
				foreach ( $variation['attributes'] as $attr ) {
					$var_title .= (empty( $var_title ) ? '' : ', ') . $attr['option'];
				}
				$post_type = (
					! isset( $variation['enabled'] ) || $variation['enabled'] ?
					'product_variation' :
					'wookite_hid_var'
				);
				$sql .= $delimiter . $this->post_sql(
					$site_url,
					$post_type,
					// "Product #$post_id Variation",
					"$product[name] &ndash; " . $var_title,
					// $product['description'],
					'',
					'publish',
					'closed',
					$uid,
					$post_date,
					$post_date_gmt,
					$post_id,
					$mo++
				);
				if ( $first ) {
					$first = false;
					$delimiter = ',';
				}
				$vidx++;
			}
			$post_id++;
			$pidx++;
		}
		$wpdb->query( $sql );
		$first_variation_id = $wpdb->insert_id;

		$prod_post_id = $first_post_id;
		$var_post_id = $first_variation_id;
		foreach ( $products as $product ) {
			$variations = array();
			foreach ( $product['variations'] as $variation ) {
				$variations[] = array(
					'id' => $var_post_id++,
				);
			}
			$res[] = array(
				'id' => $prod_post_id++,
				'variations' => $variations,
			);
		}

		// Handle product's metadata
		$taxonomy_sql = array();
		$pidx = 0;
		$var_post_id = $first_variation_id;
		$sql = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES ";
		$terms_relationships_sql =
			"INSERT IGNORE INTO $wpdb->term_relationships (object_id, term_taxonomy_id) VALUES ";
		$delimiter = '';
		$first_term = true;
		foreach ( $products as $product ) {
			$post_id = $res[ $pidx ]['id'];
			if ( $first_term ) {
				$first_term = false;
			} else { $terms_relationships_sql .= ',';
			}
			$terms_relationships_sql .= "($post_id,$wc_variable_id)";
			foreach ( (array) $product['categories'] as $category ) {
				$terms_relationships_sql .= ",($post_id,$category[id])";
			}
			$product_attributes = array();
			foreach ( $product['attributes'] as $attr ) {
				$product_attributes[ sanitize_title( $attr['name'] ) ] = array(
					'name' => $attr['name'],
					'value' => implode( ' | ', $attr['options'] ),
					'position' => 0,
					'is_visible' => (int) $attr['visible'],
					'is_variation' => (int) $attr['variation'],
					'is_taxonomy' => 0,
				);
			}
			$min_price_id = $min_price = null;
			$max_price_id = $max_price = null;
			foreach ( $product['variations'] as $variation ) {
				if ( is_null( $min_price ) or $min_price > $variation['regular_price'] ) {
					$min_price = $variation['regular_price'];
					$min_price_id = $var_post_id;
				}
				if ( is_null( $max_price ) or $max_price < $variation['regular_price'] ) {
					$max_price = $variation['regular_price'];
					$max_price_id = $var_post_id;
				}
				$sql .=
					$delimiter .
					"($var_post_id,\"_thumbnail_id\",\"$bogus_img\")," .
					"($var_post_id,\"_manage_stock\",\"no\")," .
					"($var_post_id,\"_stock_status\",\"instock\")," .
					"($var_post_id,\"_regular_price\",\"$variation[regular_price]\")," .
					"($var_post_id,\"_sale_price\",\"\")," .
					"($var_post_id,\"_sale_price_dates_from\",\"\")," .
					"($var_post_id,\"_sale_price_dates_to\",\"\")," .
					"($var_post_id,\"_price\",\"$variation[regular_price]\")," .
					"($var_post_id,\"_download_limit\",\"\")," .
					"($var_post_id,\"_download_expiry\",\"\")," .
					"($var_post_id,\"_downloadable_files\",\"\")," .
					"($var_post_id,\"_tax_status\",\"taxable\")," .
					"($var_post_id,\"_backorders\",\"no\")," .
					"($var_post_id,\"_sold_individually\",\"no\")," .
					"($var_post_id,\"_upsell_ids\",\"a:0:{}\")," .
					"($var_post_id,\"_crosssell_ids\",\"a:0:{}\")," .
					"($var_post_id,\"_virtual\",\"no\")," .
					"($var_post_id,\"_downloadable\",\"no\")," .
					"($var_post_id,\"_download_limit\",\"-1\")," .
					"($var_post_id,\"_download_expiry\",\"-1\")," .
					"($var_post_id,\"_wc_rating_count\",\"a:0:{}\")," .
					"($var_post_id,\"_product_version\",\"$wc_ver\")," .
					"($var_post_id,\"_variation_description\",\"\"),";
				if (
					isset( $variation['shipping_class'] ) &&
					isset( $terms[ $variation['shipping_class'] ] ) &&
					is_int( $terms[ $variation['shipping_class'] ] )
				) {
					$terms_relationships_sql .= sprintf(
						',(%d,%d)',
						$var_post_id,
						$terms[ $variation['shipping_class'] ]
					);
				}
				foreach ( $variation['attributes'] as $attr ) {
					$sql .= $this->post_attrib( $var_post_id, $attr );
				}
				$var_post_id++;
				$delimiter = '';
			}
			// $sql .= $this->post_attrib($post_id, $attr);
			$default_attribs = array();
			foreach ( $product['default_attributes'] as $attr ) {
				$default_attribs[ sanitize_title( $attr['name'] ) ] = $attr['option'];
			}
			$product_attributes = $product_attributes;
			$sql .=
				"($post_id,\"_default_attributes\",\"" .
					str_replace( '"', '\\"', maybe_serialize( $default_attribs ) ) .
				'"),' .
				"($post_id,\"_thumbnail_id\",\"$bogus_img\")," .
				"($post_id,\"_product_attributes\",\"" . str_replace( '"', '\\"', maybe_serialize( $product_attributes ) ) . '"),' .
				"($post_id,\"_downloadable\",\"no\")," .
				"($post_id,\"_downloadable_files\",\"a:0:{}\")," .
				#"($post_id,\"_download_limit\",\"-1\")," .
				#"($post_id,\"_download_expiry\",\"-1\")," .
				"($post_id,\"_stock_status\",\"instock\")," .
				"($post_id,\"_wc_rating_count\",\"a:0:{}\")," .
				"($post_id,\"_virtual\",\"no\")," .
				"($post_id,\"_min_variation_price\",\"$min_price\")," .
				"($post_id,\"_max_variation_price\",\"$max_price\")," .
				"($post_id,\"_min_price_variation_id\",\"$min_price_id\")," .
				"($post_id,\"_max_price_variation_id\",\"$max_price_id\")," .
				"($post_id,\"_min_variation_regular_price\",\"$min_price\")," .
				"($post_id,\"_max_variation_regular_price\",\"$max_price\")," .
				"($post_id,\"_min_regular_price_variation_id\",\"$min_price_id\")," .
				"($post_id,\"_max_regular_price_variation_id\",\"$max_price_id\")," .
				"($post_id,\"_min_variation_sale_price\",null)," .
				"($post_id,\"_max_variation_sale_price\",null)," .
				"($post_id,\"_min_sale_price_variation_id\",null)," .
				"($post_id,\"_max_sale_price_variation_id\",null)," .
				"($post_id,\"_default_attributes\",\"a:0:{}\")," .
				"($post_id,\"_visibility\",\"visible\")," .
				"($post_id,\"total_sales\",\"0\")," .
				"($post_id,\"_tax_status\",\"taxable\")," .
				"($post_id,\"_tax_class\",\"\")," .
				"($post_id,\"_purchase_note\",\"\")," .
				// "($post_id,\"_featured\",\"no\")," .
				"($post_id,\"_weight\",\"\")," .
				"($post_id,\"_length\",\"\")," .
				"($post_id,\"_width\",\"\")," .
				"($post_id,\"_height\",\"\")," .
				"($post_id,\"_sku\",\"\")," .
				"($post_id,\"_regular_price\",\"\")," .
				"($post_id,\"_sale_price\",\"\")," .
				"($post_id,\"_sale_price_dates_from\",\"\")," .
				"($post_id,\"_sale_price_dates_to\",\"\")," .
				"($post_id,\"_sold_individually\",\"no\")," .
				"($post_id,\"_manage_stock\",\"no\")," .
				"($post_id,\"_backorders\",\"no\")," .
				"($post_id,\"_stock\",\"\")," .
				"($post_id,\"_upsell_ids\",\"a:0:{}\")," .
				"($post_id,\"_crosssell_ids\",\"a:0:{}\")," .
				"($post_id,\"_price\",\"$min_price\")," .
				"($post_id,\"_price\",\"$max_price\")," .
				"($post_id,\"_product_version\",\"$wc_ver\")," .
				"($post_id,\"_product_image_gallery\",\"\")";
			$delimiter = ',';
			$pidx++;
		}
		$wpdb->query( $sql );
		$wpdb->query( $terms_relationships_sql );

		// FINISH
		$wpdb->query( 'COMMIT' );

		return $res;
	}

	protected function create_item( $rest_request ) {
		if ( ! isset( $rest_request['status'] ) ) {
			$rest_request['status'] = $this->plugin->get_option( 'default_published_status' );
		}
		if ( ! isset( $this->products_controler ) ) {
			$this->products_controler = new WC_REST_Products_Controller();
		}
		$wp_rest_request = new WP_REST_Request( 'POST' );
		$wp_rest_request->set_body_params( $rest_request );
		$res = $this->products_controler->create_item( $wp_rest_request );
		$res = $res->data;
		// The created product must have variations
		// If it doesn't, it's the new WC3+ API which forces us to build those manually
		if ( ! isset( $res['variations'] ) )
			$res['variations'] = array();
		if ( count( $res['variations'] ) == 0 && count( $rest_request['variations'] ) > 0 ) {
			if ( ! isset( $this->variations_controler ) ) {
				$this->variations_controler = new WC_REST_Product_Variations_Controller();
			}
			foreach ( $rest_request['variations'] as $variation ) {
				$wp_rest_request = new WP_REST_Request( 'POST' );
				$variation_rest = array(
					'product_id' => $res['id'],
					'regular_price' => $variation['regular_price'],
					'image' => array( 'id' => $variation['image'][0]['id'], ),
					'attributes' => $variation['attributes'],
				);
				$wp_rest_request->set_body_params( $variation_rest );
				$new_variation = $this->variations_controler->create_item( $wp_rest_request );
				$res['variations'][] = $new_variation->data;
			}
		}
		return $res;
	}

	protected function get_job_data( $job_code = null ) {
		global $wpdb;
		$job_data = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM ' . WOOKITE_JOBS_TABLE_NAME . ' WHERE code=%s',
			(isset( $job_code ) ? $job_code : $this->job_code)
		), ARRAY_A);
		return array(
			'progress' => (int) $job_data['progress'],
			'last_added' => $job_data['last_added'],
		);
	}

	protected function custom_attributes() {
		static $custom_attributes = null;
		if ( ! isset( $custom_attributes ) ) {
			$custom_attributes = $this->plugin->get_config( 'custom_attributes' );
		}
		return $custom_attributes;
	}

	protected function custom_attribute( $data, $value ) {
		if ( ! isset( $data['translate'] ) || $data['translate'] ) {
			$value = __( $value, 'wookite' );
		}
		return array(
			'name' => __( $data['name'], 'wookite' ),
			'type' => 'text',
			'options' => array( $value ),
			'visible' => true,
			'variation' => false,
		);
	}

	protected function product2rest( $product_range, $parent_id, $product, $image_url ) {
		/* Prepare attributes */
		$product_options = $product['options'];
		$product_attributes = array();
		for ( $i = 0; $i < count( $product_options ); $i++ ) {
			$options_set = array();
			foreach ( $product['variants'] as $variant ) {
				$options_set[ $variant['options'][ $i ] ] = true;
			}
			$product_attributes[] = array(
				'name' => $product_options[ $i ],
				'options' => array_keys( $options_set ),
				'visible' => false,
				'variation' => true,
			);
		}
		foreach ( $this->custom_attributes() as $code => $attribute_data ) {
			if ( $this->plugin->get_option( "custom-attribute-$code" ) && ! empty( $product[ $code ] ) ) {
				$product_attributes[] = $this->custom_attribute( $attribute_data, $product[ $code ] );
			}
		}
		/* Prepare variants */
		$product_images = array(
			array(
				'id' => $this->plugin->get_bogus_image_id(),
				'position' => 0,
			),
		);
		$product_variations = array();
		foreach ( $product['variants'] as $vid => $variant ) {
			$template_id = $variant['template_id'];
			$product_variation = array(
				'regular_price' => $variant['retail_price']['amount'],
				'attributes' => array(),
				'image' => $product_images,
				'shipping_class' => $this->plugin->tpl2sc( $template_id ),
				'enabled' => ! isset( $variant['enabled'] ) || $variant['enabled'],
			);
			$cnt = min( count( $product_options ), count( $variant['options'] ) );
			for ( $i = 0; $i < $cnt; $i++ ) {
				$product_variation['attributes'][] = array(
					'name' => $product_options[ $i ],
					'option' => $variant['options'][ $i ],
				);
			}
			$product_variations[] = $product_variation;
		}
		$default_attributes = $product_variations[0]['attributes'];
		$product_type = $product['product_type'];
		$categories = array();
		if ( isset( $product_type ) ) {
			if ( ! isset( $this->pt2cat ) ) {
				$this->pt2cat = $this->plugin->get_option( 'pt2cat' );
			}
			if ( ! empty( $this->pt2cat ) ) {
				$category = $this->pt2cat[ $product_type ];
				if ( ! empty( $category ) && $category > 0 ) {
					$categories = array(
						array( 'id' => (int) $category ),
					);
				}
			}
		}
		if ( ! empty( $product_range['category_id'] ) ) {
			$categories[] = array( 'id' => (int) $product_range['category_id'] );
		}
		$name = __( $product['title'], 'wookite' );
		if ( ! empty( $product_range['title'] ) ) {
			$name = sprintf( '%s %s', $product_range['title'], $name );
		}
		if ( ! isset( $product['enabled'] ) || $product['enabled'] ) {
			$status = $this->plugin->get_option( 'default_published_status' );
			$post_type = 'product';
		} else {
			$status = 'draft';
			$post_type = 'wookite_hid_prod';
		}
		$product_rest = array(
			'attributes' => $product_attributes,
			'catalog_visibility' => 'visible',
			'categories' => $categories,
			'default_attributes' => $default_attributes,
			'description' => $product['description'],
			'images' => $product_images,
			'name' => $name,
			'status' => $status,
			'post_type' => $post_type,
			'type' => 'variable',
			'variations' => $product_variations,
		);
		if ( isset( $parent_id ) ) {
			$product_rest['parent_id'] = $parent_id;
		}
		return $product_rest;
	}

	protected function output_job_data() {
		$job_data = $this->get_job_data();
		if ( isset( $job_data ) ) {
			unset( $job_data['id'] );
			wookite_json_output( $job_data );
		} else {
			wookite_json_error( 'E001', 'Missing or incorrect `ranges_data` argument.' );
		}
	}

	public function create( $ranges_data ) {
		$fast_creation = (bool) $this->plugin->get_option( 'fast_create_products' );
		if ( $fast_creation ) {
			$this->job_steps_total += count( $ranges_data );
		} else {
			$this->job_steps_total = 0;
			foreach ( $ranges_data as $data ) {
				$this->job_steps_total += count( $data['products'] );
			}
		}
		$this->plugin->set_max_execution_time( 60 * $this->job_steps_total );
		$disabled_product_ids = array();
		$disabled_variant_ids = array();
		$delete_transient_ids = array();
		foreach ( $ranges_data as $data ) {
			$result = array();
			// Create product range
			$product_range = $this->api->product_ranges->create(
				$data['image_url'],
				$data['image_url_preview'],
				$data['title']
			);
			// For grouped products, in case WooCommerce ever gets them to work with variant products:
			$parent_id = null;
			// Create products
			$products =& $data['products'];
			if ( $fast_creation ) {
				// Fast, but might break if WC changes how it saves the data
				$rest_products = array();
				foreach ( $products as $product ) {
					$rest_products[] = $this->product2rest( $product_range, $parent_id, $product, $data['image_url'] );
				}
				$result = $this->create_products_fast( $rest_products );
				$this->job_step( $data['title'] );
			} else {
				// Slow, but more resilient to WC changes
				foreach ( $products as $product ) {
					$product_rest = $this->product2rest( $product_range, $parent_id, $product, $data['image_url'] );
					$result[] = $this->create_item( $product_rest );
					$this->job_step( $product_rest['name'] );
				}
			}

			// Save additional Kite data for all the variations
			$pcnt = min( count( $result ), count( $products ) );
			for ( $pi = 0; $pi < $pcnt; $pi++ ) {
				$vcnt = min( count( $result[ $pi ]['variations'] ), count( $products[ $pi ]['variants'] ) );
				for ( $vi = 0; $vi < $vcnt; $vi++ ) {
					$products[ $pi ]['variants'][ $vi ]['product_id'] = $result[ $pi ]['id'];
					$this->plugin->kite->update_variant_data(
						$result[ $pi ]['variations'][ $vi ]['id'],
						$products[ $pi ]['variants'][ $vi ]
					);
					if ( ! ($fast_creation || $products[ $pi ]['variants'][ $vi ]['enabled']) ) {
						$disabled_variant_ids[ (int) $result[ $pi ]['variations'][ $vi ]['id'] ] = true;
						$delete_transient_ids[ (int) $result[ $pi ]['id'] ] = true;
					}
				}
				unset( $products[ $pi ]['variants'] );
				$products[ $pi ]['range_id'] = $product_range['id'];
				$products[ $pi ]['published'] = true;
				$this->plugin->kite->update_product_data(
					$result[ $pi ]['id'],
					$products[ $pi ]
				);
				if ( ! ($fast_creation || $products[ $pi ]['enabled']) ) {
					$disabled_product_ids[ (int) $result[ $pi ]['id'] ] = true;
				}
			}
			// wookite_json_output($result);
		}
		foreach ( $disabled_product_ids as $pid => $true ) {
			wp_update_post(array(
				'ID' => $pid,
				'post_type' => 'wookite_hid_prod',
			));
		}
		foreach ( $disabled_variant_ids as $vid => $true ) {
			wp_update_post(array(
				'ID' => $vid,
				'post_type' => 'wookite_hid_var',
			));
		}
		foreach ( $delete_transient_ids as $pid => $true ) {
			foreach ( array(
				'wc_related',
			'wc_product_children',
			'wc_var_prices',
			) as $transient_name ) {
				delete_transient( sprintf( '%s_%d', $transient_name, $pid ) );
			}
		}
		return null;
	}

	protected function update_extract_data( $data ) {
		$result = $data;
		unset( $result['variants'] );
		return $result;
	}

	protected function update_is_enabled( $data ) {
		return ! (isset( $data['enabled'] ) && $data['enabled'] === false);
	}

	protected function update_get_changed_enabled( $old_data, $new_data ) {
		$res = array(
			true => array(),
			false => array(),
		);
		foreach ( $new_data as $id => $is_enabled ) {
			if ( ! isset( $old_data[ $id ] ) || $old_data[ $id ] !== $is_enabled ) {
				$res[ $is_enabled ][] = $id;
			}
		}
		return $res;
	}

	protected function update( $update_data ) {
		global $wpdb;
		/* Prepare the list of products */
		$products = array();
		foreach ( $update_data as $product_data ) {
			$product =& $product_data['product'];
			$products[ (int) $product['id'] ] =& $product;
		}
        // Create array( $range_id => array of its product ids )
        $rid2pids = $this->plugin->kite->get_products_ranges_ids(
            array_keys($products)
        );
        $ranges = $this->api->product_ranges->get_ranges(
            array_keys( $rid2pids ), true
        );
        $pid2range = array();
		$id2frozen = array();
		$hasFrozen = false;
        foreach ( $rid2pids as $rid=>$pids )
            foreach ( $pids as $pid )
                $pid2range[ $pid ] =& $ranges[ $rid ];
		/* Collect the new data */
		$vid2pid = array();
		$new_kite_data = array();
		$new_enabled_products = array();
		$new_enabled_variants = array();
		$new_variant_prices = array();
		$new_product_min_prices = array();
		$new_product_max_prices = array();
		foreach ( $products as $product ) {
            $isFrozen = ($pid2range[ $product['id'] ]['frozen'] > 0);
			$id2frozen[ $product['id'] ] = $isFrozen;
			if ( $isFrozen )
				$hasFrozen = true;
			$new_kite_data[ $product['id'] ] = $this->update_extract_data( $product );
			$new_enabled_products[ $product['id'] ] = $this->update_is_enabled( $product );
			$new_min_price = $new_max_price = null;
			$new_min_price_id = $new_max_price_id = null;
			foreach ( (array) $product['variants'] as $variant ) {
				$vid2pid[ $variant['id'] ] = $product['id'];
				$id2frozen[ $variant['id'] ] = $isFrozen;
				$new_kite_data[ $variant['id'] ] = $this->update_extract_data( $variant );
				$new_enabled_variants[ $variant['id'] ] = $enabled = $this->update_is_enabled( $variant );
				if ( $enabled ) {
					$new_price = $variant['retail_price']['amount'];
					$new_variant_prices[ $variant['id'] ] = $new_price;
					if ( ! isset( $new_min_price ) || $new_price < $new_min_price ) {
						$new_min_price = $new_price;
						$new_min_price_id = $variant['id'];
					}
					if ( ! isset( $new_max_price ) || $new_price < $new_max_price ) {
						$new_max_price = $new_price;
						$new_max_price_id = $variant['id'];
					}
				}
			}
			$new_product_min_prices[ $product['id'] ] = array( $new_min_price_id, $new_min_price );
			$new_product_max_prices[ $product['id'] ] = array( $new_max_price_id, $new_max_price );
		}
		$all_ids = array_keys( $new_kite_data );
		$product_ids = array_keys( $new_enabled_products );
		$variant_ids = array_keys( $new_enabled_variants );
		/* Get the old data */
		$old_kite_data = $this->plugin->kite->get_posts_data( $all_ids );
		$old_enabled_products = array();
		foreach ( $product_ids as $id ) {
			$old_enabled_products[ $id ] = $old_kite_data[ $id ]['enabled'];
		}
		$old_enabled_variants = array();
		foreach ( $variant_ids as $id ) {
			$old_enabled_variants[ $id ] = $old_kite_data[ $id ]['enabled'];
		}
		$old_variant_prices = array();
		foreach ( $variant_ids as $id ) {
			$old_variant_prices[ $id ] = $old_kite_data[ $id ]['retail_price']['amount'];
		}
		/* Prepare data for updating */
		$update_kite_data = array();
		foreach ( $new_kite_data as $id => &$new_data ) {
			if ( ! isset( $old_kite_data[ $id ] ) )
				$old_kite_data[ $id ] = array();
			if ( $id2frozen[ $id ] && $old_kite_data[ $id ] !== $new_data ) {
				$tmp = $new_data;
				$new_data = $old_kite_data[ $id ];
				if ( isset( $tmp['enabled'] ) )
					$new_data['enabled'] = $tmp['enabled'];
				if ( isset( $tmp['retail_price'] ) )
					$new_data['retail_price'] = $tmp['retail_price'];
			}
			if ( $old_kite_data[ $id ] !== $new_data ) {
				$update_kite_data[ $id ] = maybe_serialize( $new_data );
			}
		}
		$update_enabled_products = $this->update_get_changed_enabled( $old_enabled_products, $new_enabled_products );
		$update_enabled_variants = $this->update_get_changed_enabled( $old_enabled_variants, $new_enabled_variants );
		$update_variant_prices = array();
		$product_ids_with_changed_prices = array();
		$variant_ids_with_changed_prices = array();
		foreach ( $new_variant_prices as $id => $new_price ) {
			$old_price = $old_variant_prices[ $id ];
			if ( ! isset( $old_price ) || $old_price !== $new_price ) {
				$product_ids_with_changed_prices[ $vid2pid[ $id ] ] = true;
				$variant_ids_with_changed_prices[] = $id;
				if ( @is_array( $update_variant_prices[ $new_price ] ) ) {
					$update_variant_prices[ $new_price ][] = $id;
				} else { $update_variant_prices[ $new_price ] = array( $id );
				}
			}
		}
		foreach ( $product_ids_with_changed_prices as $id => $true ) {
			$update_product_min_prices[ $id ] = $new_product_min_prices[ $id ];
			$update_product_max_prices[ $id ] = $new_product_max_prices[ $id ];
		}
		/* Save changes */
		$delete_transients = '';
		// Update Kite data
		foreach ( $update_kite_data as $id => $new_data ) {
			$wpdb->update(
				WOOKITE_POSTMETA_TABLE_NAME,
				array( 'kite_data' => $new_data ),
				array( 'post_id' => $id )
			);
		}
		// Update enabled/disabled
		foreach ( array(
			array(
				'posts' => &$update_enabled_products,
				'visible_status' => $this->plugin->get_option( 'default_published_status' ),
				'hidden_status' => 'draft',
				'visible_type' => 'product',
				'hidden_type' => 'wookite_hid_prod',
			),
			// Must be last in the array, as we use `$update_enabled_variants`
			// after the loop.
			array(
				'posts' => &$update_enabled_variants,
				'visible_status' => 'publish',
				'hidden_status' => 'private',
				'visible_type' => 'product_variation',
				'hidden_type' => 'wookite_hid_var',
			),
		) as $posts_data ) {
			$update_enabled_posts =& $posts_data['posts'];
			$visible_status = $posts_data['visible_status'];
			$hidden_status = $posts_data['hidden_status'];
			$visible_type = $posts_data['visible_type'];
			$hidden_type = $posts_data['hidden_type'];
			$update_enabled_posts_ids = array_merge(
				$update_enabled_posts[ false ],
				$update_enabled_posts[ true ]
			);
			if ( $update_enabled_posts[ true ] ) {
				if ( $update_enabled_posts[ false ] ) {
					$wpdb->query(sprintf(
						"UPDATE $wpdb->posts " .
						'SET post_status=IF(ID IN (%1$s), "%2$s", "%4$s"), post_type=IF(ID IN (%1$s), "%3$s", "%5$s") WHERE ID IN (%6$s)',
						implode( ',', $update_enabled_posts[ true ] ),
						$visible_status, $visible_type,
						$hidden_status, $hidden_type,
						implode( ',', $update_enabled_posts_ids )
					));
				} else { 					$wpdb->query(sprintf(
					"UPDATE $wpdb->posts " .
							'SET post_status="%s", post_type="%s" WHERE ID IN (%s)',
					$visible_status, $visible_type,
					implode( ',', $update_enabled_posts[ true ] )
				));
				}
			} elseif ( $update_enabled_posts[ false ] ) {
				$wpdb->query(sprintf(
					"UPDATE $wpdb->posts " .
						'SET post_status="%s", post_type="%s" WHERE ID IN (%s)',
					$hidden_status, $hidden_type,
					implode( ',', $update_enabled_posts[ false ] )
				));
			}
		}
		$products_changed_enabled = array();
		foreach ( array_merge(
			$update_enabled_variants[ true ],
			$update_enabled_variants[ false ]
		) as $id ) {
			$products_changed_enabled[ $vid2pid[ $id ] ] = true;
		}
		$new_defaults = '';
		$products_changed_enabled_ids = array_keys( $products_changed_enabled );
		foreach ( $products_changed_enabled_ids as $id ) {
			foreach ( (array) $product['variants'] as $variant ) {
				if ( $new_enabled_variants[ $variant['id'] ] ) {
					$default_attribs = array();
					$cnt = min( count( $product['options'] ), count( $variant['options'] ) );
					for ( $i = 0; $i < $cnt; $i++ ) {
						$default_attribs[ sanitize_title( $product['options'][ $i ] ) ] = $variant['options'][ $i ];
					}
					$new_defaults .= $wpdb->prepare(
						'WHEN %d THEN %s ',
						$id,
						maybe_serialize( $default_attribs )
					);
					break;
				}
			}
			if ( ! empty( $delete_transients ) ) {
				$delete_transients .= ',';
			}
			$delete_transients .=
				"'_transient_wc_related_$id','_transient_timeout_wc_related_$id'," .
				"'_transient_wc_product_children_$id','_transient_timeout_wc_product_children_$id'," .
				"'_transient_wc_var_prices_$id','_transient_timeout_wc_var_prices_$id'";
		}
		if ( ! empty( $new_defaults ) ) {
			$wpdb->query($tmp = sprintf(
				'UPDATE %s
                    SET meta_value = CASE post_id %sELSE "" END
                    WHERE post_id IN (%s) AND meta_key="_default_attributes"',
				$wpdb->postmeta,
				$new_defaults,
				implode( ',', $products_changed_enabled_ids )
			));
		}
		if ( $hasFrozen && ( $update_enabled_products[true] || $update_enabled_variants[true] ) ) {
			$ranges_ids_to_freeze = array();
			foreach ( array_merge( $update_enabled_products[true], $update_enabled_variants[true] ) as $id )
				if ( $id2frozen[ $id ] ) {
					$pid = (
						isset( $vid2pid[ $id ] ) ?
						$vid2pid[ $id ] :
						$id
					);
					$rid = $pid2range[ $pid ]['id'];
					$ranges_ids_to_freeze[ $rid ] = true;
				}
			$ranges_ids_to_freeze = array_keys($ranges_ids_to_freeze);
			foreach ( $ranges_ids_to_freeze as $rid )
				$this->plugin->api->product_ranges->update_range(
					$rid,
					array(
						'freeze_in_progress' => 'f',
						'total_images' => 0,
					)
				);
		}
		// Update prices
		if ( $update_variant_prices ) {
			$sql = '';
			$variant_ids = array();
			foreach ( $update_variant_prices as $price => $ids ) {
				foreach ( $ids as $id ) {
					$variant_ids[] = $id;
					if ( ! empty( $sql ) ) {
						$sql .= ',';
					}
					$sql .= sprintf(
						'(%1$d,"_price","%2$g"),' .
						'(%1$d,"_regular_price","%2$g")',
						$id,
						$price
					);
				}
			}
			if ( $product_ids_with_changed_prices ) {
				foreach ( $product_ids_with_changed_prices as $id => $true ) {
					if ( ! empty( $sql ) ) { $sql .= ',';
					}
					$sql .= sprintf(
						'(%1$d,"_min_variation_price","%2$g"),' .
						'(%1$d,"_max_variation_price","%4$g"),' .
						'(%1$d,"_min_price_variation_id","%3$g"),' .
						'(%1$d,"_max_price_variation_id","%5$g"),' .
						'(%1$d,"_min_variation_regular_price","%2$g"),' .
						'(%1$d,"_max_variation_regular_price","%4$g"),' .
						'(%1$d,"_min_regular_price_variation_id","%3$g"),' .
						'(%1$d,"_max_regular_price_variation_id","%5$g")',
						$id,
						$update_product_min_prices[ $id ][0],
						$update_product_min_prices[ $id ][1],
						$update_product_max_prices[ $id ][0],
						$update_product_max_prices[ $id ][1]
					);
				}
			}
			if ( ! empty( $sql ) ) {
				$sql = "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES $sql";
				$ids = array_merge(
					$variant_ids,
					array_keys( $product_ids_with_changed_prices )
				);
				$wpdb->query(sprintf(
					"DELETE FROM $wpdb->postmeta WHERE
                        post_id IN (%s) AND
                        meta_key IN (
                            '_price', '_regular_price',
                            '_min_variation_price', '_max_variation_price',
                            '_min_price_variation_id', '_max_price_variation_id',
                            '_min_variation_regular_price',
                            '_max_variation_regular_price',
                            '_min_regular_price_variation_id',
                            '_max_regular_price_variation_id'
                        )",
					implode( ',', $ids )
				));
				$wpdb->query( $sql );
				foreach ( $product_ids_with_changed_prices as $id => $true ) {
					WC_Product_Variable::sync( $id );
				}
			}
			foreach ( $product_ids_with_changed_prices as $id => $true ) {
				$changed_enabled = (
					isset( $products_changed_enabled[ $id ] ) ?
					$products_changed_enabled[ $id ] :
					false
				);
				if ( ! $changed_enabled ) {
					if ( ! empty( $delete_transients ) ) {
						$delete_transients .= ',';
					}
					$delete_transients .=
					"'_transient_wc_var_prices_$id','_transient_timeout_wc_var_prices_$id'";
				}
			}
		}
		if ( ! empty( $delete_transients ) ) {
			$wpdb->query(
				"DELETE FROM $wpdb->options WHERE option_name IN ($delete_transients)"
			);
		}
	}

}


