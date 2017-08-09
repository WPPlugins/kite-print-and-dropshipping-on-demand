=== Kite Print and Dropshipping on Demand ===
Contributors: deonbotha, vsego
Donate link: 
Tags: print on demand, dropshipping, t-shirt, apparel, mug, stickers, posters, kite, kite.ly, woocommerce
Requires at least: 4.6
Tested up to: 4.8
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Fully automated print on demand and dropshipping plugin for WooCommerce

== Description ==

= Add custom merch to your WooCommerce store in 30 seconds =

* Sell your designs on demand. No minimum order or upfront costs.
* We will automatically print and dropship the products directly to your customers.
* Worldwide fulfilment and white labelled solution.
* The fastest way to start selling your designs on products.

Kite enables Artists, Musicians, Brands, Influencers all over the world to turn their designs into quality customised merchandise. We have helped partners in e-commerce, social media, and bricks-and-mortar businesses generate revenue streams from their designs.

We work with only the best print partners who operate fulfilment centres worldwide. This means high quality products delivered swiftly to your customers' door.

Here's what our partners think of us..

> "It's been a pleasure working with Kite to provide a seamless print solution to our millions of creators on PicCollage. Their platform and support have made our entry into the physical product market so easy and fun!"

> -- **Ching-Mei Chen** (Co-Founder, Head of Product) - PicCollage (110 million users)

= Made on demand - No minimum order =

Upload your artwork, choose your products and you're ready to go! It's that simple.

Print your designs on

* **Apparel**: T Shirts, Hoodies, Tank Tops
* **Accessories:** Tote Bags, Flip Flops
* **Homeware:** Cushions, Mugs, Towels
* **Wall Art:** Posters, Canvases, Fine Art Prints
* **Phone & Tablet cases:** iPhone & Android range
* **Print Products:** Greetings Cards, Magnets
* **More products:** Coming soon!

Sit back and relax! Every order will be manufactured and then delivered to your customers without you without having to lift a finger.

= Your brand, not ours =

Kite is your silent partner. Your customers will never hear from or about us.

= How it works (3 Easy Steps) =

1. **Install this plugin** - Set up a few basic settings
2. **Upload your artwork** - Upload one or more artworks to create collections of products. Once uploaded we will populate your artwork across the entire product range, including prices, descriptions and more. You can then tweak the artwork to match your own taste before publishing your store. The cost of the product and shipping will be clearly stated.
3. **Click publish and relax!** - Your work is done. We'll start fulfilling the orders of your linked products as they come in.

= Need more assistance? =

Unsure about anything? Chat to us using the live chat within the plugin or drop us an email at **[support@kite.ly](mailto:support@kite.ly)**. We'll be happy to help!

== Data sent to our servers ==

We keep this to a minimum, basically only things we need to provide an automated drop shipping service to you:

* We query our servers to get details on our full product range, this includes up to date pricing information, etc. This query does not include any of your information outside of your currency preference.
* Images that you upload in the plugin to create products are uploaded to our servers. This is so that we can optimise rendering of the product photography whilst still having access to the raw uncompressed uploaded image that we'll use to print on your products.
* Due to the nature of this plugin, any WooCommerce orders containing items that we (Kite) need to fulfil on your behalf need to get sent to our server (otherwise we can't print and ship them to your customers). This means we need to send the customer shipping address & product details for printing. Orders that don't contain Kite items are not sent to our servers. 
* In order to use this plugin you need a Kite.ly account. For the best user experience we will automatically create you one as part of the plugin usage - this requires your email address. 
* We have a live chat widget in the plugin to provide support, the widget is provided by intercom.io. Based on our experience launching similar plugins on other e-commerce platforms, we typically receive hundreds of customer service queries per day. Intercom provides a live chat widget that appears on the page that we use to handle this customer service in a scalable manner and also provide the best experience to our users. If you don't want customer support feel free to comment out or remove the script tags associated with intercom.io - they’re called out appropriately in index.php.

You can find our privacy policy [here](https://kite.uservoice.com/knowledgebase/articles/468234-privacy-policy).

== Installation ==

1. Upload `kite-print-and-dropshipping-on-demand/` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

== Frequently asked questions ==

= Do I need to have WooCommerce installed to use the plugin? =

Yes, WooCommerce is required for the plugin to work. 

= Problems with displaying product images =

Below are two technical answers on how to get your theme working, but you can also "freeze" your products, which downloads their images to your Media library (thus removing the reliance on themes to replace these images on the fly).

Please note that product images of "frozen" products cannot be edited unless the product is "unfrozen". Also, "freezing" takes quite a bit of time (usually around 10 minutes or more, depending on your network connection, for the full range of products).

= The page for adding products doesn't show in my Safari =

There is a known bug in older Safaris. Please upgrade to version 10+.

= My theme or some plugin overrides product images back to placeholder image and I found where this happens. How do I fix this? =

There is a convenience function `wookite_process_image($html, $post_id)` which accepts either HTML code or an image URL as the first argument and the variant or product's `post_id` as the second, and returns the version of the HTML/URL with Kite's placeholder image URL replaced with proper image links.

= Why are my product images not showing in Oxygen theme? =

Oxygen forces local thumbnails with no filters/actions to latch to, so our plugin cannot override it. A simple fix is to open `oxygen/woocommerce/single-product/add-to-cart/variable.php` and replace

    $image = wp_get_attachment_image_src( get_post_thumbnail_id( $variation['variation_id'] ), 'shop-thumb-4' );

with

    $image = wookite_process_image(
        wp_get_attachment_image_src( get_post_thumbnail_id( $variation['variation_id'] ), 'shop-thumb-4' ),
        $variation['variation_id']
    );

A similar fix can work on other themes, as long as you can provide the original image URL (here generated by the `wp_get_attachment_image_src` call) and variation's ID (here given as `$variation['variation_id']`).

= Why are my product images showing Kite logo instead of the actual image? =

This used to be issue until version 1.0.3. This answer is left here for anyone needing it for older versions.

That image is used as a placeholder that is substituted for the actual image, in order to significantly improve the performance of the plugin. However, it also relies on a properly written theme.

Specifically, for storefront product images, in the file `woocommerce/single-product/product-image.php in your theme's directory, there must be a call similar to this one:

    echo apply_filters(
        'woocommerce_single_product_image_html',
        sprintf(
            '<a href="%s" itemprop="image" class="woocommerce-main-image zoom" title="%s" data-rel="prettyPhoto%s">%s</a>',
            esc_url( $props['url'] ),
            esc_attr( $props['caption'] ),
            $gallery,
            $image
        ),
        $post->ID
    );

If there isn't one, you can find the part that is generating the image (in the above code, it is the whole `sprintf(...)` part, but it can also be just `<img src="..." ... />` or something similar) and enclose it in the `echo apply_filters('woocommerce_single_product_image_html', ...)` call.

For other images, there should be similar calls to `apply_filters` in your theme's `woocommerce` directory:

* filter `woocommerce_single_product_image_thumbnail_html` in `single-product/product-thumbnails.php` for product thumbnails,

* filter `woocommerce_cart_item_thumbnail` for product images in carts, in `cart/cart.php`,

* filter `woocommerce_before_subcategory_title` for range category images in `content-product_cat.php`.

== Screenshots ==

1. Upload an image(s) to create some products that we will drop ship for you

2. A preview of the product range that you can edit and publish to your store front

3. Editing a sublimation t-shirt product in detail

4. The products outputted to the storefront for users to browse and purchase

== Changelog ==

= 1.1.4 =
* Fix for slow products publishing method

= 1.1.3 =
* Fix for mixed orders for WooCommerce 3.0

= 1.1.2 =
* Updated tote bags info
* Adapted for changes in the server backend
* Fix for hidden products and variants post types

= 1.1.1 =
* Removed Facebook tracking

= 1.1.0 =
* Added support for multiple shipping methods (tracked and untracked shipping)
* Added support for [WooCommerce Shipment Tracking plugin](https://woocommerce.com/products/shipment-tracking/)
* Adapted "not fast" adding of products to accommodate for the changes in WooCommerce 3.0.0+
* Added "freezing" of products (adding their images to Media library)
* Added support for [Yoast SEO plugin](https://yoast.com/wordpress/plugins/seo/)
* Added support for custom background colors in product images
* Some further code cleanup

= 1.0.15 =
* Code cleanup to remove notices popping up in WP debug mode

= 1.0.14 =
* Compatibility with WooCommerce 3.0.4+ (WC 3.0.3 and lower have a problem that we cannot work around) and Twenty Seventeen theme 1.2
* Replaced Kite placeholder image with WC's one; if you want to replace the old one in your existing install, remove it from your Media Library (the plugin will readd the new one) and use "Kite.ly Merch" > "Tools" > "Add Kite Images" to attach it to your existing products.

= 1.0.13 =
* Local loading of Kite's products marker image, to avoid fetching problems with some hosting providers
* More WooCommerce 3.0+ fixes

= 1.0.12 =
* Added kids apparel support

= 1.0.11 =
* Fix of an error caused by WooCommerce 3.0+
* Default WooCommerce theme doesn't display product images properly; waiting for the [fix](https://wordpress.org/support/topic/post-was-called-incorrectly-product-properties-should-not-be-accessed-directly/) to be released.

= 1.0.10 =
* Fix in Kite orders requests (some were not going through as expected)

= 1.0.9 =
* Help system overhaul
* Minor fix in products' image links

= 1.0.8 =
* Adapted for Oxygen theme

= 1.0.7 =
* Products page images now take default product attributes into account

= 1.0.6 =
* Configuration fix

= 1.0.5 =
* WP JetPack compatibility
* Facebook conversion tracking pixel

= 1.0.4 =
* Conformity with MySQL older than 5.6.5
* Workaround for PHP unserialize bug with multibyte strings resulting in errors with data with non-ASCII characters
* Fix to avoid to low res images (introduced in 1.0.3)

= 1.0.3 =
* Removed dependency on theme having the proper WooCommerce filter calls
* Minor bugfix of product adding interface

= 1.0.2 =
* Replaced function dereferencing (incompatible with PHP prior 5.4.0)

= 1.0.1 =
* Fix for the issue that left some customers hanging.
* Uninstall script fix.

= 1.0 =
* The first version

== Upgrade notice ==

= 1.1 =
* Support for multiple shipping methods
* WooCommerce 3.0 compatible release

= 1.0 =
* The first version

