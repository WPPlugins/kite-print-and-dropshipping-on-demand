<?php

/*
Handled URLs:

Get all product ranges:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=product_range
Returns: JSON list of product ranges' dictionaries

Get product range:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=product_range&id=<id>
Returns: JSON dictionary with data for product range identified by ID

Note: Product ranges are created with `create` method, but it is not invoked
through a URL, but from `WooKiteEndpointPublishProduct::run`.
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

define( 'WOOKITE_PRODUCT_RANGES_TABLE_NAME', $wpdb->prefix . 'wookite_product_ranges' );

class WooKiteEndpointProductRanges extends WooKiteEndpoint {

	static $endpoint = 'product_range';

	public function __construct( $plugin ) {
		dbDelta('CREATE TABLE ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' (
            id BIGINT(20) UNSIGNED NOT NULL auto_increment,
            image_url VARCHAR(255) DEFAULT "",
            image_url_preview VARCHAR(255) DEFAULT "",
            title VARCHAR(255) DEFAULT "",
            category_id BIGINT(20) UNSIGNED DEFAULT NULL,
            frozen SMALLINT(5) DEFAULT 0,
            total_images SMALLINT(5) UNSIGNED DEFAULT 0,
			time_imgs_updated TIMESTAMP DEFAULT "0000-00-00 00:00:00",
			freeze_in_progress CHAR(1) NOT NULL DEFAULT "",
            PRIMARY KEY  (id)
        )');
		$this->sql_public_fields = 'id, image_url, image_url_preview, ' .
			'title, category_id, frozen, total_images, time_imgs_updated, ' .
			'freeze_in_progress';
		$this->range_product_separator = get_option( 'wookite_rp_sep', ' ' );
		parent::__construct( $plugin );
	}

	public function handle_url() {
		if ( ! empty( $this->post ) ) {
			if ( ! empty( $this->post['update'] ) ) {
				wookite_json_output( $this->update( $this->post['update'] ) );
			}
			if ( ! empty( $this->post['delete'] ) ) {
				wookite_json_output( $this->delete_ranges( $this->post['delete'] ) );
			}
			if ( isset( $this->post['freeze'] ) && ! empty( $this->post['freeze'] ) ) {
				wookite_json_output( $this->toggle_freeze( (int) $this->post['freeze'], true ) );
			}
			if ( isset( $this->post['unfreeze'] ) && ! empty( $this->post['unfreeze'] ) ) {
				wookite_json_output( $this->toggle_freeze( (int) $this->post['unfreeze'], false ) );
			}
			wookite_json_error( 'E00', 'These POST requests are really, REALLY hard to use, aren\'t they? :-P' );
		}
		if ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) {
			$id = (int) $_GET['id'];
			if ( $id > 0 ) {
				wookite_json_output(
					$this->get_range( $id ),
					array( 'error' => 'no data with the given ID' )
				);
			}
		}
		if ( isset( $_GET['products'] ) && ! empty( $_GET['products'] ) ) {
			$products = (int) $_GET['products'];
			if ( $products > 0 ) {
				wookite_json_output(
					$this->get_products( $products ),
					array( 'error' => 'no data with the given ID' )
				);
			}
		}
		wookite_json_output( $this->list_ranges() );
	}

	public function create( $image_url, $image_url_preview, $title = null ) {
		global $wpdb;
		$data = array(
			'image_url' => $image_url,
			'image_url_preview' => $image_url_preview,
			'title' => $title,
		);
		if ( ! empty( $title ) ) {
			$parent_cat_id = $this->plugin->get_option( 'range_parent_cat' );
			if ( is_int( $parent_cat_id ) ) {
				$cat_id = $this->plugin->create_category( $title, $parent_cat_id, true );
				if ( $cat_id ) { $data['category_id'] = $cat_id;
				}
			}
		}
		$wpdb->insert( WOOKITE_PRODUCT_RANGES_TABLE_NAME, $data );
		$data['id'] = $wpdb->insert_id;
		$data['published'] = true;
		if ( $data['id'] ) { return $data;
		}
		return null;
	}

	public function delete_category( $cat_id, $prod_ids = null ) {
		if ( ! empty( $prod_ids ) ) {
			foreach ( $prod_ids as $id ) {
				wp_remove_object_terms( $id, array( $cat_id ), 'product_cat' );
			}
		}
		if ( ! isset( $this->categories_controler ) ) {
			$this->categories_controler = new WC_REST_Product_Categories_Controller();
		}
		$wp_rest_request = new WP_REST_Request( 'DELETE' );
		$wp_rest_request->set_body_params( array( 'id' => $cat_id ) );
		$res = $this->categories_controler->create_item( $wp_rest_request );
	}

	public function update( $new_values ) {
		global $wpdb;
		$range_id = $new_values['id'];
		$old_values = $this->get_range( $range_id );
		$old_title = (empty( $old_values['title'] ) ? '' : $old_values['title']);
		$new_title = (empty( $new_values['title'] ) ? '' : $new_values['title']);
		if ( $old_title !== $new_title ) {
			$new_range_title = $new_title;
			if ( ! empty( $old_title ) ) {
				$old_title .= $this->range_product_separator;
			}
			if ( ! empty( $new_title ) ) {
				$new_title .= $this->range_product_separator;
			}
			$old_len = strlen( $old_title );
			$products = $this->plugin->kite->get_products_by_range( $range_id );
			foreach ( $products as $id => $prod ) {
				$post = get_post( $id );
				$title = $post->post_title;
				if ( $old_len and substr( $title, 0, $old_len ) !== $old_title ) {
					continue;
				}
				$post->post_title =
					$new_title . ($old_len ? substr( $title, $old_len ) : $title);
				if ( $title !== $post->post_title ) {
					wp_update_post( $post );
				}
			}
			$update_values = array( 'title' => $new_range_title );
			$cat_id = $old_values['category_id'];
			if ( empty( $new_title ) ) {
				if ( ! empty( $cat_id ) ) {
					$this->delete_category( $cat_id, array_keys( $products ) );
					$update_values['id'] = null;
				}
			} else {
				if ( empty( $cat_id ) ) {
					$parent_cat_id = $this->plugin->get_option( 'range_parent_cat' );
					if ( is_int( $parent_cat_id ) ) {
						$cat_id = $this->plugin->create_category( $new_title, $parent_cat_id, true );
						$update_values['category_id'] = $cat_id;
						foreach ( $products as $id => $prod ) {
							wp_set_object_terms( $id, array( $cat_id ), 'product_cat', true );
						}
					}
				} else { 					wp_update_term( $cat_id, 'product_cat', array( 'name' => $new_title ) );
				}
			}
			$wpdb->update(
				WOOKITE_PRODUCT_RANGES_TABLE_NAME,
				$update_values,
				array( 'id' => $new_values['id'] )
			);
		}
		return null;
	}

	public function clean_range_data( $data ) {
		$data['id'] = (int) $data['id'];
		$data['category_id'] = (int) $data['category_id'];
		$data['frozen'] = (int) $data['frozen'];
		$data['total_images'] = (int) $data['total_images'];
		$time_imgs_updated = strtotime( $data['time_imgs_updated'] );
		$data['time_imgs_updated'] = $time_imgs_updated;
		$data['can_freeze'] = (
			$data['freeze_in_progress'] === '' &&
			$data['frozen'] == 0
		);
		$data['can_unfreeze'] = (
			$data['freeze_in_progress'] === '' &&
			$data['frozen'] > 0 && $data['frozen'] == $data['total_images']
		);
		return $data;
	}

	public function clean_range_datae( $data ) {
		$res = array();
		foreach ( $data as $range ) {
			$res[] = $this->clean_range_data( $range );
		}
		return $res;
	}

	public function ranges2assoc( $ranges ) {
		$res = array();
		foreach ( $ranges as $range )
			$res[ $range['id'] ] = $range;
		return $res;
	}

	public function get_range( $id ) {
		global $wpdb;
		return $this->clean_range_data($wpdb->get_row($wpdb->prepare(
			'SELECT ' . $this->sql_public_fields . ' '.
            'FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' ' .
            'WHERE id=%d',
			$id
		), ARRAY_A));
	}

	public function get_ranges( $ids, $assoc=false ) {
		global $wpdb;
		$res = $this->clean_range_datae($wpdb->get_results(sprintf(
			'SELECT ' . $this->sql_public_fields . ' '.
            'FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' ' .
            'WHERE id in (%s)',
			implode( ', ', $ids )
		), ARRAY_A));
		if ( $assoc )
			$res = $this->ranges2assoc( $res );
		return $res;
	}

	public function get_range_by_cat( $id ) {
		global $wpdb;
		return $this->clean_range_data($wpdb->get_row($wpdb->prepare(
			'SELECT ' . $this->sql_public_fields . ' '.
            'FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' ' .
            'WHERE category_id=%d',
			$id
		), ARRAY_A));
	}

	public function get_ranges_by_cat( $ids, $assoc=false ) {
		global $wpdb;
		$res = $this->clean_range_datae($wpdb->get_results(sprintf(
			'SELECT ' . $this->sql_public_fields . ' '.
            'FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' ' .
            'WHERE category_id in (%s)',
			implode( ', ', $ids )
		), ARRAY_A));
		if ( $assoc )
			$res = $this->ranges2assoc( $res );
		return $res;
	}

	public function get_products( $id ) {
		$res = array();
		$products = $this->plugin->kite->get_products_by_range( (int) $id );
		foreach ( $products as $id => $prod ) {
			$prod['id'] = $id;
			$post_status = get_post_status( $id );
			$prod['enabled'] = ($post_status === 'publish' || $post_status === 'private');
			$variants = array();
			foreach ( $this->plugin->kite->get_variants_by_product( $id ) as $vid => $var ) {
				$var['id'] = $vid;
				$variants[] = $var;
			}
			$prod['variants'] = $variants;
			$res[] = $prod;
		}
		return $res;
	}

	public function all_ranges() {
		global $wpdb;
		static $ppp = 17;
		$offset = 0;
		$prids = $this->plugin->kite->get_all_ranges_ids();
		if ( $prids ) {
			return $this->clean_range_datae($wpdb->get_results(sprintf(
				'SELECT * FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' WHERE id IN (%s)',
				implode( ',', $prids )
			), ARRAY_A));
		} else {
			return array();
		}
	}

	public function list_ranges() {
		$results = array();
		foreach ( $this->all_ranges() as $product_range ) {
			$product_range['published'] = true;
			$results[] = $product_range;
		}
		return $results;
	}

	public function delete_ranges( $range_ids ) {
		global $wpdb;
		// Prepare IDs (convert to `int` and eliminate any doubles)
		$ids = array();
		foreach ( (array) $range_ids as $id ) {
			$ids[ (int) $id ] = true;
		}
		$range_ids = array_keys( $ids );
		// Get all child posts (products and their variants)
		$post_ids = $this->plugin->kite->get_post_children_ids( $range_ids );
		// Get ranges' dynamic categories
		$cat_ids = array();
		foreach ( $this->get_ranges( $range_ids ) as $data ) {
			$cat_id = $data['category_id'];
			if ( ! empty( $cat_id ) ) {
				$cat_id = (int) $cat_id;
				if ( $cat_id > 0 ) { $cat_ids[ $cat_id ] = true;
				}
			}
			$cat_ids = array_keys( $cat_ids );
		}
		$str_cat_ids = implode( ', ', $cat_ids );
		$str_post_ids = implode( ', ', $post_ids );
		if ( ! empty( $cat_ids ) ) {
			// Fast delete dynamic range categories
			$res = $wpdb->query(sprintf(
				'DELETE FROM ' . $wpdb->term_relationships . ' WHERE object_id IN (%s)',
				$str_post_ids
			));
			foreach ( array( 'termmeta', 'term_taxonomy', 'terms' ) as $table ) {
				if ( $res === false ) {
					break;
				} else { $res = $wpdb->query(sprintf(
					'DELETE FROM ' . $wpdb->$table . ' WHERE term_id IN (%s)',
					$str_cat_ids
				));
				}
			}
			// Fallback in case the above failed
			if ( $res === false ) {
				foreach ( $cat_ids as $id ) {
					$this->delete_category( $id, $prod_ids );
				}
			}
		}
		// Delete Kite's metadata for products and their variants
		$this->plugin->kite->delete_posts_data( $post_ids );
		// Delete Kite's ranges
		$wpdb->query(sprintf(
			'DELETE FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' WHERE id IN (%s)',
			implode( ',', $range_ids )
		));
		// Fast delete WP posts and meta
		$res = $wpdb->query(sprintf(
			'DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id IN (%s)',
			$str_post_ids
		));
		if ( $res !== false ) {
			$res = $wpdb->query(sprintf(
				'DELETE FROM ' . $wpdb->posts . ' WHERE ID IN (%s)',
				$str_post_ids
			));
		}
		// Fallback in case the above failed (might take a while)
		if ( $res === false ) {
			$this->plugin->set_max_execution_time( 3 * count( $post_ids ) );
			foreach ( $post_ids as $id ) {
				wp_delete_post( $id, true );
			}
		}
		return null;
	}

	public function update_range( $where, $data ) {
		global $wpdb;
		if ( !is_array( $where ) )
			$where = array( 'id' => (int)$where );
		return $wpdb->update( WOOKITE_PRODUCT_RANGES_TABLE_NAME, $data, $where );
	}

	public function new_attachment($att_id){
		$this->last_attachment_id = $att_id;
	}

	public function wp_handle_sideload_prefilter( $file ) {
		if ( isset( $file['name'] ) && isset( $this->file_name ) ) {
			$file['name'] = $this->file_name;
			unset( $this->file_name );
		}
		return $file;
	}

	protected function _set_vids_thumbnails( $mid, $vids ) {
		global $wpdb;
		$wpdb->query( sprintf(
			'UPDATE ' . $wpdb->postmeta . ' ' .
			'SET meta_value=%d ' .
			'WHERE meta_key="_thumbnail_id" AND ' .
				'post_id IN (%s)',
			$mid,
			implode( ',', $vids )
		) );
	}

	public function toggle_freeze( $range_id, $freeze=true ) {
		global $wpdb;
		$time = time();
		$range = $this->get_range($range_id);
		if (is_null($range)) wookite_exit(1);
		if ($freeze && !$range['can_freeze'] && $range['freeze_in_progress'] != 'f')
			wookite_exit(1);
		if (! $freeze && !$range['can_unfreeze'] && $range['freeze_in_progress'] != 'u')
			wookite_exit(1);
		$this->update_range(
			$range_id,
			array(
				'freeze_in_progress' => ($freeze ? 'f' : 'u'),
				'time_imgs_updated' => current_time('mysql'),
			)
		);
		$vids = $this->plugin->kite->get_post_children_ids($range_id);
		$vid2mid = array();
		$vids_frozen = array();
		$vids_unfrozen = array();
		$bogus_img_id = $this->plugin->get_bogus_image_id();
		$url2vids = array();
		$current_meta = $wpdb->get_results(
			sprintf(
				'SELECT post_id, meta_value FROM ' . $wpdb->postmeta . ' ' .
				'WHERE meta_key="_thumbnail_id" AND post_id IN (%s)',
				implode(',', $vids)
			),
			ARRAY_A
		);
		$current_mids = array();
		foreach ($current_meta as $row) {
			$vid = (int)$row['post_id'];
			$mid = (int)$row['meta_value'];
			$vid2mid[$vid] = $mid;
			$current_mids[$mid] = true;
		}
		$current_mids = array_keys($current_mids);
		$current_urls = $wpdb->get_results(
			$sql = sprintf(
				'SELECT post_id, url FROM ' . WOOKITE_MEDIA_URLS_TABLE_NAME . ' ' .
				'WHERE range_id=%d AND post_id IN (%s)',
				$range_id,
				implode(',', $vids)
			),
			ARRAY_A
		);
		$url2mid = array();
		foreach ($current_urls as $row) {
			$url = $row['url'];
			$mid = (int)$row['post_id'];
			$url2mid[$url] = $mid;
		}
		$need_to_upload = array();
		$need_to_set = array();
		foreach ($vid2mid as $vid=>$mid)
			if ($mid === $bogus_img_id) {
				// We need to set this one's thumbnail
				$data = $this->plugin->kite->post_id2data($vid);
				if ($data['enabled']) {
					$url = $data['product_single_image'];
					if (!isset($url2mid[$url])) {
						// Not yet uploaded
						$need_to_upload[$url] = true;
					}
					if (isset($need_to_set[$url])) {
						$need_to_set[$url][$vid] = true;
					} else {
						$need_to_set[$url] = array($vid=>true);
					}
				}
			}
		$need_to_upload = array_keys($need_to_upload);
		$progress = count($url2mid);
		$total_images = $progress + count($need_to_upload);

		if (empty($need_to_set) && !$freeze) {
			// All variants are frozen; let's unfreeze them
			$this->update_range(
				$range_id,
				array(
					'freeze_in_progress' => 'u',
					'time_imgs_updated' => current_time('mysql'),
				)
			);
			$progress = $total_images;
			$this->_set_vids_thumbnails(
				$bogus_img_id,
				array_keys($vid2mid)
			);
			foreach ($current_mids as $mid) {
				wp_delete_attachment($mid, true);
			}
			$wpdb->query(sprintf(
				'DELETE FROM ' . WOOKITE_MEDIA_URLS_TABLE_NAME . ' ' .
				'WHERE range_id=%d',
				$range_id
			));
			$this->update_range(
				$range_id,
				array(
					'frozen' => 0,
					'freeze_in_progress' => '',
					'time_imgs_updated' => current_time('mysql'),
				)
			);
		} elseif (!empty($need_to_set) && $freeze) {
			// There are some variants which are not frozen;
			// let's freeze them now
			if (!empty($need_to_upload)) {
				// Upload missing images
				$this->plugin->set_max_execution_time(17 * count($need_to_upload));
				add_action('add_attachment', array($this, 'new_attachment'));
				add_filter('wp_handle_sideload_prefilter', array($this, 'wp_handle_sideload_prefilter'));
				foreach ($need_to_upload as $url) {
					$updated_rows_cnt = $this->update_range(
						$range_id,
						array(
							'frozen' => ++$progress,
							'total_images' => $total_images,
							'freeze_in_progress' => 'f',
							'time_imgs_updated' => current_time('mysql'),
						)
					);
					if (!$updated_rows_cnt)
						wookite_json_error(2, 'Error updating range data');
					$vids = array_keys($need_to_set[$url]);
					$this->file_name = "kite-image-variant-$vids[0].png";
					$media_sideload_res = media_sideload_image($url, $vids[0]);
					if ($media_sideload_res instanceof WP_Error)
						wookite_json_output(
							null,
							array(
								'error' => array(
									'message' => sprintf('Error fetching "%s"', $url),
									'code' => 1,
									'wp_error' => $media_sideload_res,
								)
							)
						);
					$media_post_id = $this->last_attachment_id;
					$url2mid[$url] = $media_post_id;
					add_post_meta($media_post_id, 'wookite-product', 'yes', true);
					$wpdb->query($wpdb->prepare(
						'INSERT INTO ' . WOOKITE_MEDIA_URLS_TABLE_NAME . ' ' .
						'(range_id, post_id, url) VALUES (%d, %d, %s) ' .
						'ON DUPLICATE KEY UPDATE url=%s',
						$range_id,
						$media_post_id,
						$url, $url
					));
					$this->_set_vids_thumbnails($media_post_id, $vids);
					unset($need_to_set[$url]);
				}
				remove_filter('wp_handle_sideload_prefilter', array($this, 'wp_handle_sideload_prefilter'));
				remove_action('add_attachment', array($this, 'new_attachment'));
			}
			// Set thumbnail meta if there's any left (there shouldn't be)
			foreach ($need_to_set as $url=>$vids) {
				$this->_set_vids_thumbnails($url2mid[$url], array_keys($vids));
			}
			// All done! Let's make sure the data's not out of sync.
			$this->update_range(
				$range_id,
				array(
					'frozen' => $total_images,
					'total_images' => $total_images,
					'freeze_in_progress' => '',
					'time_imgs_updated' => current_time('mysql'),
				)
			);
		} else {
			$this->update_range(
				$range_id,
				array(
					'freeze_in_progress' => '',
					'time_imgs_updated' => current_time('mysql'),
				)
			);
		}
		return array('done' => true);
	}

	public function freeze_broken_ranges_ids() {
		global $wpdb;
		$res = array();
		foreach ($wpdb->get_results(sprintf(
			'SELECT id FROM ' . WOOKITE_PRODUCT_RANGES_TABLE_NAME . ' ' .
			'WHERE freeze_in_progress != "" AND ' .
				'UNIX_TIMESTAMP("%s") - UNIX_TIMESTAMP(time_imgs_updated) > 60',
			current_time('mysql')
		), ARRAY_A) as $row)
			//$res[] = (int) $row['id'];
			$res[] = (int) $row['id'];
		return $res;
	}

}


