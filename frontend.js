var WooKiteAPI = function($http, $q, API_VERSION) {

	this.$http = $http;
	this.$q = $q;
	this.API_VERSION = API_VERSION;

	this.productRange = function( rangeId ) {
		var $q = this.$q;
		return this.$http.get(
			"admin.php?page=wookite-plugin&endpoint=product_range" +
            "&id=" + parseInt( rangeId ) + "&wpnonce=" + wpnonce
		).then(
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.productRanges = function() {
		var $q = this.$q;
		return this.$http.get(
			"admin.php?page=wookite-plugin&endpoint=product_range&wpnonce=" + wpnonce
		).then(
			function(response) {
				return $q.resolve( response.data );
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.productsForProductRange = function(productRangeId) {
		var $q = this.$q;
		return this.$http.get(
			"admin.php?page=wookite-plugin&endpoint=product_range&products=" + productRangeId + "&wpnonce=" + wpnonce
		).then(
			function(response) {
				return $q.resolve( response.data );
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.publishProductRanges = function(productRanges) {
		var $q = this.$q;
		var $http = this.$http;
		var ranges = new Array();
		for (var i = 0; i < productRanges.length; i++) {
			ranges.push({
				image_url: productRanges[i].image_url,
				image_url_preview: productRanges[i].image_url_preview,
				title: productRanges[i].title,
			});
		}
		return this.$http.post(
			"admin.php?page=wookite-plugin&endpoint=publish_product&wpnonce=" + wpnonce,
			{create: ranges}
		).then(
			function(response) {
				var job_id = response.data['code'];
				$http.post(
					"admin.php?page=wookite-plugin&endpoint=publish_product&job=" + job_id + "&wpnonce=" + wpnonce,
					{publish: productRanges}
				);
				return job_id;
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.publishProgress = function(queryProgressKey) {
		var $q = this.$q;
		return this.$http.get(
			"admin.php?page=wookite-plugin&endpoint=publish_product&job=" + queryProgressKey + "&wpnonce=" + wpnonce
		).then(
			function(response) {
				// TODO - remove `.progress` when we support it.
				return $q.resolve( response.data.progress );
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.deleteProductRange = function(productRange) {
		var $q = this.$q;
		return this.$http.post(
			"admin.php?page=wookite-plugin&endpoint=product_range&wpnonce=" + wpnonce,
			{'delete': [productRange.id]}
		).then(
			function(response) {
				return $q.resolve();
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.saveProductRange = function(productRange) {
		var $q = this.$q;
		return this.$http.post(
			"admin.php?page=wookite-plugin&endpoint=product_range&wpnonce=" + wpnonce,
			{update: productRange}
		).then(
			function(response) {
				return $q.resolve();
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.saveProduct = function(product) {
		var $q = this.$q;
		return this.$http.post(
			"admin.php?page=wookite-plugin&endpoint=publish_product&wpnonce=" + wpnonce,
			{update: [{product: product}]}
		).then(
			function(response) {
				return $q.resolve();
			},
			function(response) {
				return $q.resolve( response.data );
			}
		);
	};

	this.me = function() {
		var $q = this.$q;
		return $q.resolve({
			"balance": {
				"amount": 55.0,
				"currency": "GBP",
				"formatted": "\u00a355.00"
			},
			"card": {
				"expiry": null,
				"obfuscated_number": null,
				"setup_required": true
			},
			"currency": "USD",
			"kite_live_publishable_key": "6a071320f6050d7664be77112ecb1963196f3c3c",
			"kite_live_secret_key": "118f57eaf218dfba70ec6db9d7d0f517e619f047",
			"kite_sign_in_required": false,
			"kite_username": "deon@kite.ly",
			"shipping_costs": {
				"currency": "USD",
				"symbol": "$",
				"zones": [
					{
						"amount": 3.05,
						"countries": "United Kingdom",
						"name": "Kite.ly UK"
				},
					{
						"amount": 6.11,
						"countries": "Europe",
						"name": "Kite.ly EU"
				},
					{
						"amount": 7.33,
						"countries": "Rest of world",
						"name": "Kite.ly ROW"
				}
				]
			},
			"shop_owner_company_name": "Kite Dev Store",
			"shop_owner_email": "deon@kite.ly",
			"shop_owner_name": "Deon Botha",
			"shop_owner_phone_number": "07842975272",
			"shop_url": "kite-dev-store.myshopify.com",
			"vat_liable": true
		});
	};

    this.freezeProductRange = function(rangeId) {
		return this.$http.post(
            'admin.php?page=wookite-plugin&endpoint=product_range' +
            '&wpnonce=' + wpnonce,
            {freeze: rangeId}
		);
    };

    this.unfreezeProductRange = function(rangeId) {
		return this.$http.post(
            'admin.php?page=wookite-plugin&endpoint=product_range' +
            '&wpnonce=' + wpnonce,
            {unfreeze: rangeId}
		);
    };

};
