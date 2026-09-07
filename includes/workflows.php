<?php
defined( 'ABSPATH' ) || exit;

function tgo_can_configure() {
	return current_user_can( 'manage_woocommerce' );
}

function tgo_selectable_statuses() {
	$statuses = wc_get_order_statuses();
	$settings = TGO_Order_Permissions_Statuses::settings();
	$statuses = array_diff_key( $statuses, array_flip( $settings['hidden_statuses'] ) );
	$role     = TGO_Order_Permissions_Statuses::assigned_role();
	if ( in_array( $role, array( 'shipping', 'accounting' ), true ) ) {
		$allowed = $settings['allowed_statuses'][ $role ];
		return array_intersect_key( $statuses, array_flip( $allowed ) );
	}
	return $statuses;
}
