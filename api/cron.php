<?php

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class WooKiteEndpointCron extends WooKiteEndpoint {


	static $restricted = false;
	static $endpoint = 'cron';

	public function restricted() {
		return false;
	}

	public function handle_url() {
		$this->plugin->kite->cron_now();
	}

}
