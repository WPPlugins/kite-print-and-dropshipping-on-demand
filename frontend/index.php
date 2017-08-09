<?php

class WooKiteFrontend extends WooKiteFrontendBase {
    

    public $scripts = array('/static/gen/packed.min.js');
    public $styles = array('/static/gen/packed.min.css');

    public function run() {
        parent::run();
?>

<div class="kite" ng-app="kite-shopify">
    <div ng-view></div>
</div>

<!-- We use Stripe (stripe.com) in the Settings page to allow customers to add a payment card that
will be charged for orders that they (or more likely their customers) place with us. -->
<script type="text/javascript" src="https://js.stripe.com/v2/"></script>

<script>
    Stripe.setPublishableKey("pk_live_o1egYds0rWu43ln7FjEyOU5E");
    var wpnonce = "<?php echo esc_attr(wp_create_nonce('wookite-frontend')); ?>";

    angular.module("kite-shopify")
            .constant("SHOP_URL", "<?php echo $this->shop_front_url; ?>")
            .constant("APP_THEME", "kite")
            .constant("APP_EMAIL", "hello@kite.ly")
            .constant("APP_NAME", "Kite.ly Merch")
            .constant("CURRENCY_CODE", "<?php echo $this->currency; ?>")
            .constant("VAT_LIABLE", true)
            .constant("DEBUG", false)
            .constant("PLATFORM", "wordpress")
            .constant("BASE_URL", "<?php echo $this->base_url; ?>")
            .constant("IMAGE_GENERATOR_ENDPOINT", "https://image.kite.ly/")
            .constant("BASE_URL", "<?php echo $this->base_url; ?>")
            .run(function ($rootScope, BASE_URL) {
                $rootScope.BASE_URL = BASE_URL;
            });
</script>

<!-- START INTERCOM CHAT WIDGET -->
<!-- The following script is used to provide customer support to our users. This is facilitated
 through the use of a third party service called Intercom (available at intercom.io). Based on
 our experience launching similar plugins on other e-commerce platforms, we typically receive
 hundreds of customer service queries per day. Intercom provides a live chat widget that appears on the
 page that we use to handle this customer service in a scalable manner and also provide the best
 experience to our users. If you don't want live chat customer support feel free to comment out or
 remove the below script tags. -->
<script>
    window.intercomSettings = {
        app_id: "ei6d07z4",
        name: "<?php echo $this->name; ?>",
        email: "<?php echo $this->email; ?>",
        created_at: <?php echo $this->created_at; ?>,
        shop_url: "<?php echo $this->shop_url; ?>"
    };
</script>
<script>(function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',intercomSettings);}else{var d=document;var i=function(){i.c(arguments)};i.q=[];i.c=function(args){i.q.push(args)};w.Intercom=i;function l(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/ei6d07z4';var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);}if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}}})()</script>
<!-- END INTERCOM CHAT WIDGET -->

<?php
    }
}

?>
