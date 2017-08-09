<?php

/*
Adapted from WooCommerce's
includes/admin/views/html-admin-page-status-tools.php
*/

if ( ! defined( 'ABSPATH' ) ) {
	wookite_exit();
}

?>
<div class="wrap woocommerce">
<table class="wc_status_table widefat" cellspacing="0">
	<tbody class="tools">
	<?php foreach ( $tools as $action => $tool ) : ?>
	<?php
				$info = null;
	if ( ! empty( $tool['info_text'] ) ) {
		$info_method = "info_$action";
		if ( method_exists( $this, $info_method ) ) {
			$info = $this->$info_method();
		}
	}
	?>
			<tr class="<?php echo sanitize_html_class( $action ); ?>">
				<td><?php echo esc_html( $tool['name'] ); ?></td>
				<td>
					<p><form method="GET" action="<?php echo admin_url( 'admin.php' ); ?>"<?php if ( @$tool['confirmation_text'] ) { echo ' data-confirmation_text="' . esc_attr( $tool['confirmation_text'] ) . '"';
} ?>><input type="hidden" name="page" value="wookite-tools" /><input type="hidden" name="action" value="<?php echo $action; ?>" /><?php wp_nonce_field( 'wookite-tools' ); ?><?php if ( ! empty( $tool['pre-button'] ) ) { echo $tool['pre-button'];
} ?><span class="wookite-tools-button button <?php echo esc_attr( $action ), ((bool) @$tool['kite_live_matters'] ? $kite_button_style : ''), (empty( $tool['button_style'] ) ? '' : " $tool[button_style]"); ?>"<?php if ( (bool) @$tool['kite_live_matters'] && ! $kite_live ) { printf( ' title="%s"', __( 'Kite is in test mode', 'wookite' ) );
} ?>><?php echo esc_html( $tool['button'] ); ?></span><?php if ( ! empty( $tool['post-button'] ) ) { echo $tool['post-button'];
} if ( isset( $info ) ) { printf( '<span class="wookite-tool-info">%s</span>', sprintf( __( $tool['info_text'], 'wookite' ), $info ) );
} ?></form></p>
					<p><span class="description"><?php echo wp_kses_post( $tool['desc'] ); ?></span></p>
				</td>
			</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
