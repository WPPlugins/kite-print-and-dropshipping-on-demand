<?php

defined('ABSPATH') or die('No script kiddies please!');

class WooKiteShipmentTracking {

	public function __construct($plugin) {
        $this->plugin = $plugin;
        # Support for WooCommerce Shipment Tracking plugin
        # https://woocommerce.com/products/shipment-tracking/
        if (class_exists('WC_Shipment_Tracking_Actions') && function_exists('wc_st_add_tracking_number'))
            add_filter('wookite_fetched_kite_data', array($this, 'handle_data_wc_shipment_tracking'), 10, 3);
    }

    public function handle_data_wc_shipment_tracking($data, $order_id, $kite_id) {
        $st = WC_Shipment_Tracking_Actions::get_instance();
        if (isset($data['jobs']) && is_array($data['jobs'])) {
            $tis = $st->get_tracking_items($order_id);
            $tns = array();
            # Collect the data we're already aware of
            foreach ($tis as $item) {
                $provider = sanitize_title(
                    empty($item['tracking_provider']) ?
                    $item['custom_tracking_provider'] :
                    $item['tracking_provider']
                );
                if (empty($provider)) continue;
                $tracking_number = $item['tracking_number'];
                if (is_array($tns[$provider]))
                    $tns[$provider][$tracking_number] = true;
                else
                    $tns[$provider] = array($tracking_number=>true);
            }
            # Look for new data and, if found, add it
            foreach ($data['jobs'] as $job) {
                if (isset($job['carrier_tracking_url']) && ! empty($job['carrier_tracking_url'])) {
                    $carrier_tracking_url = $job['carrier_tracking_url'];
                    $provider = $job['carrier_name'];
                    if (!empty($provider))
                        $provider = apply_filters('wookite_shipment_carrier_name', $provider);
                    $tracking_number = apply_filters('wookite_shipment_tracking_number', $job['carrier_tracking']);
                    if (!(
                        empty($provider) ||
                        empty($tracking_number) ||
                        empty($carrier_tracking_url)
                    )) {
                        # Job has shipping tracking info
                        $tn_exists = (
                            isset($tns[sanitize_title($provider)][$tracking_number]) ?
                            $tns[sanitize_title($provider)][$tracking_number] :
                            false
                        );
                        if (!$tn_exists) {
                            # We haven't processed this info yet
                            wc_st_add_tracking_number(
                                $order_id,
                                $tracking_number,
                                $provider,
                                null,
                                $carrier_tracking_url
                            );
                        }
                    }
                }
            }
        }
        return $data;
    }

}

?>
