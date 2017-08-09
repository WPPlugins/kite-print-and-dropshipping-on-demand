<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once dirname( __FILE__ ) . '/tools.php';

class WooKiteHelp {

	function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function get_help( $slug ) {
		static $all_help = null;
		if ( is_null( $all_help ) ) {
			$all_help = array(
				'settings-general' => array(
					array(
						'title' => __( 'General', 'wookite' ),
						'content' => __( '<p>Here you can find the settings related to the general functioning of the Kite.ly Merch Plugin. Please see the individual subsection tabs for more detail on the available settings.</p>', 'wookite' ),
					),
					array(
						'title' => __( 'Kite settings', 'wookite' ),
						'content' =>
						__( '<p>These settings affect the way the main Kite server interacts with your store.</p><dl><dt>Credit card info</dt><dd>The details for the credit card you will use to pay your Kite invoices. A valid credit card needs to be registered so that we can bill you for the wholesale and shipping costs of any Kite products ordered. Orders cannot be processed until this field is complete. </dd><dt>Kite live mode</dt><dd>Unchecking this box will set Kite to work in test mode, meaning that you can place test orders not intended for fulfilment.<br />We suggest that you only use test mode when your site is offline to prevent any genuine customer orders being overlooked. However, if you were to receive real orders in test mode, it would be possible to switch back to live mode later on and manually resend your test orders as live ones.</dd><dt>Publish product status</dt><dd>This determines the default status of Kite products when published in your store. They can either be "published", making them visible to all of your customers, or "private", making them visible only to admin. This works in a similar way to WordPress\' <a href="https://en.support.wordpress.com/post-visibility/">post visibility settings</a>.</dd><dt>Fast products publishing</dt><dd>Having Fast Product Publishing turned on will significantly speed up the process of adding new products. If you\'re experiencing problems however, uncheck the box to return to the ordinary version.</dd></dl>', 'wookite' ),
					),
					array(
						'title' => __( 'Appearance', 'wookite' ),
						'content' => __( '<p>These settings describe how Kite.ly Merch Plugin influences the look of your store and your WooCommerce orders section (don\'t worry, we\'re not too intrusive &#x1F603;).</p><dl><dt>Image size</dt><dd>In general, WordPress themes will automatically determine the size of displayed images, including those of Kite products and their variants. If this does not happen, these dimensions will be used as the default values.</dd><dt>Add processing notes to orders</dt><dd>Kite.ly Merch will by default add order notes to any orders containing Kite products as it progresses through our system. If you do not wish to receive these updates, uncheck the box to switch them off.</dd><dt>Image switcher</dt><dd>Image switcher: Certain products (i.e. apparel) can have different designs on the front and back. Enabling this will embed an image switcher into the relevant product pages on your site, allowing your customers to switch between front and back views.<br />Note that all themes allow Kite.ly Merch to embed the Front/Back switcher. It will also only work with images hosted on the Kite server (i.e., only for not frozen products). Use dual product images as a local alternative.</dd><dt>Dual image style</dt><dd>If you cannot embed the front/back switcher, or prefer to display both or only one of the views as a single image, deselect the Image Switcher and use the Dual Image dropdown to select your preferred style.<br />Note that this will be the default view of any products that have been frozen.</dd><dt>Product images background</dt><dd>Set a background colour for your product images by entering a 6 or 8 digit hexadecimal code. Leave blank to retain a transparent background.<br />Note that 6-digit (opaque colour) codes will turn product images into JPEGs (as opposed to PNGs), resulting in quicker loading times.<br />Changes will be applied to all live images, and can be readjusted as many times as necessary. Once a product has been frozen however, a change in this field will not affect its images unless you unfreeze and refreeze the product again.</dd></dl>', 'wookite' ),
					),
					array(
						'title' => __( 'Product attributes', 'wookite' ),
						'content' => __( '<p>Choose which additional product information you want displayed alongside your Kite products.</p><p>Changing this option will only set the course for newly created products. Existing products will need to be edited individually in Products &gt; Edit Product &gt; Product Data &gt; Attributes. </p>', 'wookite' ),
					),
				),
				'settings-shipping' => array(
					array(
						'title' => __( 'Shipping', 'wookite' ),
						'content' => __( '<p>Shipping costs for Kite orders vary by product type and destination country. Kite.ly Merch Plug-in can create new shipping classes to properly set up item\'s shipping costs, so that you don\'t accidentally leave yourself out-of-pocket.</p><p>The first section, "Defined shipping zones", displays any shipping zones and flat rate methods you have already set up in WooCommerce. You can attach the Kite equivalent to each of those zones using the dropdown beside them.</p><p>The second section, "Define new shipping zones" gives you the option to have Kite.ly Merch create new WooCommerce shipping zones that correspond with its own (USA, UK, Europe, Rest of World). Note: This is useful if you don\'t already have these zones defined, but should not be used if it causes overlap with existing zones, as this can confuse WooCommerce.</p><p>Note that shipping classes will be set to products variations, not the products themselves.</p>', 'wookite' ),
					),
					array(
						'title' => __( 'Tracked Shipping', 'wookite' ),
						'content' => __( '<p>Not all products and destinations support tracked shipping. Select “Prefer tracked” to make shipping tracked wherever possible.</p><p>For more information on Tracked shipping availability and pricing, visit our <a href="http://help.kite.ly/woocommerce-faq/which-products-can-be-shipped-tracked">Help Center</a>.</p>', 'wookite' ),
					),
				),
				'settings-categories' => array(
					array(
						'title' => __( 'Categories', 'wookite' ),
						'content' => __( '<p>To streamline the publishing process and make site navigation easier, Kite can automatically add new products to your <a href="https://docs.woocommerce.com/document/managing-product-taxonomies/">categories</a> and even dynamically create new ones. </p>', 'wookite' ),
					),
					array(
						'title' => __( 'Ranges\' categories', 'wookite' ),
						'content' => __( '<p>If you want a dynamically created category for each titled product range, choose a parent category under which these will be added.</p>', 'wookite' ),
					),
					array(
						'title' => __( 'Products\' categories', 'wookite' ),
						'content' => __( '<p>Here you can define which of your own categories are the best fit for the products belonging to the listed products categories.</p><p>There are also two special settings: "Create on save", which will create a corresponding category (under the parent category of your choosing) when you click "Save", and "Ignore", which means that the products of this product type will not get assigned a category.</p><p>The "Undefined categories" section makes it easier to set up these options, by setting all unset ones to "Create on save" (with the parent of your choosing), or by trying to autodetect which of your existing categories would be a best fit for any product type which you haven\'t already set up.</p>', 'wookite' ),
					),
				),
				'tools' => array(
					array(
						'title' => __( 'Intro', 'wookite' ),
						'content' => sprintf(
							'<p>%s</p>',
							__( 'These tools perform various maintenance tasks. Activating them from here is not usually necessary, as Kite.ly Merch should take care of these things automatically. The tools below allow you to:</p><p>Refresh certain maintenance functions (which would normally be updated periodically)</p><p>Restore default Kite.ly Merch settings if they have been changed.</p><p>Please note that, depending on the number of the affected items, some of these may take a long time to finish.', 'wookite' )
						),
					),
				),
				'test' => array(
					array(
						'title' => __( 'Intro', 'wookite' ),
						'content' => sprintf(
							'<p>%s</p>',
							__( 'If you\'re here, you\'re not supposed to need help. :-P', 'wookite' )
					    ),
					),
				),
			);
			foreach ( $this->plugin->tools->get_tools() as $tool ) {
				$all_help['tools'][] = array(
					'title' => preg_replace(
						'#^\W*(.*?)\W*$#', '\1',
						isset( $tool['help_title'] ) ? $tool['help_title'] : $tool['name']
					),
					'content' => $tool['help'],
				);
			}
		}
		if ( isset( $all_help[ $slug ] ) && is_array( $all_help[ $slug ] ) ) {
			$help = $all_help[ $slug ];
			foreach ( $help as $idx => &$tab ) {
				$tab['id'] = "wookite-help-$slug-$idx";
			}
		} else
			$help = null;
		return $help;
	}

	public function add( $slug, $hook ) {
		$help = $this->get_help( $slug );
		if ( isset( $help ) ) {
			$func = "help_$slug";
			add_action( "load-$hook", array( $this, $func ) );
		}
	}

	/* Help */

	public function __call( $func, $params ) {
		if ( substr( $func, 0, 5 ) === 'help_' ) {
			$slug = substr( $func, 5 );
			$help = $this->get_help( $slug );
			if ( ! empty( $help ) ) {
				$screen = get_current_screen();
				foreach ( $help as $tab ) {
					$screen->add_help_tab(
						apply_filters( 'wookite_help_tab', $tab, $func )
					);
				}
			}
		}
	}
}

