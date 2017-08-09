(function($) {
	$(function() {
		// Remove `done=...` part from Tools' pages
		var url = window.location.toString();
		if (url.includes( 'page=wookite-tools' )) {
			url_new = url.replace( /&done=[^&]*/, '' );
			if (url != url_new) {
				history.replaceState( '', '', url_new );
			}
		}
		// Link objects in Settings > Categories
		var idx = 0;
		while (true) {
			var cat = $( '#wookite_category_' + idx );
			var par = $( '#wookite_parent_' + idx );
			if ( ! (par.length && cat.length)) { break;
			}
			cat[0].par = par;
			cat.change(
				function(e) {
					wookite_cat_change( e.target );
				}
			);
			wookite_cat_change( cat[0] );
			idx++;
		}
		// Click event for the `Set to "Create on save"` button in Settings > Categories
		$( '#wookite_cat_make_all' ).click(
			function(e) {
				var common_par = $( '#wookite_cat_make_all_parent' ).val();
				var idx = 0;
				while (true) {
					var cat = $( '#wookite_category_' + idx );
					if ( ! cat.length) { break;
					}
					if (cat.val() === '' || cat.val() === '0') {
						cat.val( '' );
						wookite_cat_change( cat[0] );
						if (common_par != '') {
							var par = $( '#wookite_parent_' + idx );
							if (par.length) {
								par.val( common_par );
							}
						}
					}
					idx++;
				}
				e.preventDefault();
			}
		);
		// Click event for the `Try to autodetect...` button in Settings > Categories
		$( '#wookite_cat_autodetect' ).click(
			function(e) {
				$( '.select-cat' ).each(
					function() {
						var pt = $( '<textarea />' ).html( $( this ).data( 'pt' ) ).text();
						var pts = pt + 's';
						var ptes = pt + 'es';
						var sel = this;
						var sel_val = $( sel ).val();
						if (sel_val === '' || sel_val === '0') {
							$( this ).find( 'optgroup:nth-child(2)' ).find( 'option' ).each(
								function() {
									var name = $( this ).text().replace( /^\s*/, '' );
									var names = name + 's';
									var namees = name + 'es';
									if (name === pt || name === pts || name === ptes
										|| names === pt || names === ptes
										|| namees === pt || namees === pts
									) {
										$( sel ).val( $( this ).val() );
										return false;
									}
								}
							);
						}
					}
				);
				e.preventDefault();
			}
		);
		// Shipping zones' buttons
		var $guessable_shipping_zones = $( '.guessable_shipping_zone' );
		var guess_shipping = function($span) {
			var $select = $span.children( 'select' ).eq(0);
			var $select_tracked = $span.children( 'select' ).eq(1);
			if ( ! $select.val() ) {
				var val = $span.data( 'guess' );
				if (val) $select.val( val );
				var val = $span.data( 'guess-tracked' );
				if (val) $select_tracked.val( val );
			}
		}
		if ($guessable_shipping_zones.length) {
			$( '#autodetect_shipping_zones' ).
				css( 'display', 'inline-block' ).
				click(function(e) {
					$guessable_shipping_zones.each(
						function() {
							guess_shipping($(this));
						}
					);
				});
			$guessable_shipping_zones.
				each(function(idx, el) {
					$(el).
						find( '.wookite_shipping_zone_autodetect.button' ).
						click(function(e) {
							guess_shipping($(el));
						});
				});
		}
		$( '#suggest_new_shipping_zones' ).click(
			function(e) {
				$( '.new_shipping_zone' ).each(
					function() {
						$( this ).val( $( this ).data( 'suggested' ) );
					}
				);
			}
		);
		// Click event for the "Submit order"/"Update status" buttons in orders' pages
		$( '#wookite_order_status_button' ).click(
			function(e) {
				var btn = this;
				$( '#wookite_order_status' ).html( $( this ).data( 'working-text' ) + '...' );
				// $(this).css('display', 'none');
				$( this ).hide();
				$.ajax(
					{
						url: $( this ).data( 'url' ),
					}
				).done(
					function(data) {
						$( '#wookite_order_status' ).html( data );
						var url2 = $( btn ).data( 'url2' );
						var wt2 = $( btn ).data( 'wt2' );
						var desc2 = $( btn ).data( 'desc2' );
						if (url2 && wt2 && desc2) {
							$( btn ).data( 'url', url2 );
							$( btn ).data( 'working-text', wt2 );
							$( btn ).html( desc2 );
							$( btn ).data( 'url2', '' );
							$( btn ).data( 'wt2', '' );
							$( btn ).data( 'desc2', '' );
						}
						// $(btn).css('display', 'block');
						$( btn ).show();
					}
				).fail(
					function(jqXHR, textStatus, errorThrown) {
						$( '#wookite_order_status' ).html( 'Error: ' + textStatus );
					}
				);
				e.preventDefault();
			}
		);
		// Credit cards stuff
		var $form = $( 'form#wookite_settings' );
		if ($form.length) {
			$( 'button#revoke_card' ).click(
				function() {
					if ( ! confirm( 'Are you sure that you want to revoke this card?' )) {
						return false;
					}
					$.ajax(
						{
							url: 'admin.php?page=wookite-plugin&endpoint=me&action=revoke-card&wpnonce=' + $( 'button#revoke_card' ).data( 'wpnonce' ),
						}
					).done(
						function(data) {
							$( 'div#existing_card' ).hide();
							$( 'div#new_card' ).show();
						}
					).fail(
						function(jqXHR, textStatus, errorThrown) {
							$( '#wookite_payment_errors' ).html(
								'<b>Error:</b> ' + (textStatus === 'error' ? 'Failed removing the card info' : textStatus)
							);
						}
					);
					return false;
				}
			);
			$form.submit(
				function(event) {
					 // var $form = $('form#wookite_settings');
					 // Disable the submit button to prevent repeated clicks:
					 $form.find( '.submit' ).prop( 'disabled', true );
					 var $ccnum = $( 'input[data-stripe=number]', $form );
					if ($ccnum.val()) {
						// Request a token from Stripe:
						Stripe.card.createToken(
							$form, function(status, response) {
								if (response.error) { // Problem!
									// Show the errors on the form:
									$form.find( '#wookite_payment_errors' ).html( '<b>Error:</b> ' + response.error.message );
									$form.find( '#submit' ).prop( 'disabled', false ); // Re-enable submission
									$( 'html, body' ).animate(
										{
											scrollTop: $( '#wookite_payment_errors' ).offset().top - $( window ).height() / 7
										}, 173
									);
								} else { // Token was created!
									// Get the token ID:
									var token = response.id;
									// Insert the token ID into the form so it gets submitted to the server:
									$form.append( $( '<input type="hidden" name="wookite_options[stripeToken]">' ).val( token ) );
									// Submit the form:
									$form.get( 0 ).submit();
								}
							}
						);
						// Prevent the form from being submitted:
						return false;
					}
					 return true;
				}
			);
		}
		// Submit actions for tools buttons
		$( 'span.wookite-tools-button' ).click(
			function(e) {
				var form = $( this ).closest( 'form' ).get( 0 );
				var msg = $( form ).data( 'confirmation_text' );
				if (msg && ! confirm( msg )) { return;
				}
				form.submit();
			}
		);
	});

	function wookite_cat_change(cat) {
		var par = cat.par;
		if (cat && par) {
			$( par ).prop( 'disabled', ($( cat ).val() != '') );
		}
	}

	window.wookite_trigger_cron = function (url, data) {
		// Make an asynchronous request; intended for automatically
		// dispatching orders and updating their statuses
		if (data === undefined) {
			$.get(url);
		} else {
			$.ajax({
				url: url,
				type: 'POST',
				data: JSON.stringify(data),
				contentType : 'application/json',
			});
		}
	};

})(jQuery);

