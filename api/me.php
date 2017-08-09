<?php

/*
Handled URLs:

Get Kite user details:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=me
Returns: JSON with Kite user details

Revoke the credit card:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=me&action=revoke-card

Link to the Kite account IDed by an email address and authorised by a token:
URI: /wp-admin/admin.php?page=wookite-plugin&endpoint=me&action=relink&email=<email>&token=<token>
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class WooKiteEndpointMe extends WooKiteEndpoint {


	static $endpoint = 'me';

	public function __construct( $plugin ) {
		parent::__construct( $plugin );
		$this->kite =& $plugin->kite;
	}

	public function handle_url() {
		$action = (isset( $_GET['action'] ) ? $_GET['action'] : null);
		if ( $action === 'revoke-card' ) {
			$resp = $this->kite->delete_billing_card();
			if ( $resp === true ) {
				$this->update_me();
				wookite_exit();
			}
			wookite_json_error( $resp['code'], $resp['message'] );
		}
		if ( $action === 'relink' ) {
			$this->update_me();
			header(
				sprintf(
					'Location: %s',
					admin_url( 'admin.php?page=wookite-settings' )
				)
			);
			wookite_exit();
		}
		wookite_json_output( $this->get_me() );
	}

	public function restricted() {
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'relink' ) {
			return false;
		}
		return self::$restricted;
	}

	public function update_me() {
		$me = $this->kite->fetch_me();
		if ( $me['registered'] ) {
			update_option( 'wookite_me', $me );
		}
		return $me;
	}

	public function autoregister( $email = null ) {
		$me = $this->kite->autoregister( $email );
		update_option( 'wookite_me', $me );
		return $me;
	}

	public function get_me( $reload = false ) {
		// $reload = null --> do not do automatic update
		if ( isset( $this->me ) ) {
			return $this->me;
		}
		$me = get_option( 'wookite_me', false );
		if ( ! (bool) $me ) {
			$me = $this->autoregister();
		} elseif ( (bool) $reload
			|| ! ((bool) $me['registered'] || is_null( $reload ))
		) {
			$me = $this->update_me();
		}
		if ( ! (bool) $me['registered'] ) {
			return false;
		}
		$balance = (
		 @is_array( $me['amount_outstanding'] ) ?
		 $me['amount_outstanding'] :
		 array(
		  'amount' => 0,
		  'currency' => get_woocommerce_currency(),
		  'formatted' => wc_price(
			  0.00, array( 'currency' => get_woocommerce_currency() )
		  ),
		 )
		);
		$res = array(
		 'kite_sign_in_required' => false,
		 'balance' => $balance,
		 'card' => (
		  isset( $me['account']['card_number'] ) ?
		  $me['account']['card_number'] :
		  ''
		 ),
		 'app_name' => 'Kite.ly',
		 'kite_username' => (
		  isset( $me['account']['username'] ) ?
		  $me['account']['username'] :
		  ''
		 ),
		 'kite_live_publishable_key' => (
		  isset( $me['account']['live_publishable_key'] ) ?
		  $me['account']['live_publishable_key'] :
		  ''
		 ),
		 'kite_live_secret_key' => (
		  isset( $me['account']['live_secret_key'] ) ?
		  $me['account']['live_secret_key'] :
		  ''
		 ),
		 'kite_test_publishable_key' => (
		  isset( $me['account']['test_publishable_key'] ) ?
		  $me['account']['test_publishable_key'] :
		  ''
		 ),
		 'kite_test_secret_key' => (
		  isset( $me['account']['test_secret_key'] ) ?
		  $me['account']['test_secret_key'] :
		  ''
		 ),
		 'kite_sign_in_required' => false,
		 'shop_owner_name' => (
		  isset( $me['account']['user']['full_name'] ) ?
		  $me['account']['user']['full_name'] :
		  ''
		 ),
		 'shop_owner_email' => (
		  isset( $me['account']['user']['email'] ) ?
		  $me['account']['user']['email'] :
		  ''
		 ),
		 'shop_owner_phone_number' => (
		  isset( $me['account']['phone_number'] ) ?
		  $me['account']['phone_number'] :
		  ''
		 ),
		 'shop_owner_company_name' => (
		  isset( $me['account']['store']['name'] ) ?
		  $me['account']['store']['name'] :
		  ''
		 ),
		 'shop_url' => $this->plugin->shop_url(),
		 'currency' => get_woocommerce_currency(),
		 'vat_liable' => (
		  isset( $me['account']['liable_for_vat'] ) ?
		  $me['account']['liable_for_vat'] :
		  true
		 ),
		);
		$this->me = $res;
		return $res;
	}

}


