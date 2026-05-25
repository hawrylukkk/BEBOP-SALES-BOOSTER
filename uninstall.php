<?php
/**
 * Remove plugin options when uninstalled.
 *
 * @package bebop-sales-booster
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bebop_sales_booster' );
