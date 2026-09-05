<?php
defined( 'ABSPATH' ) || exit;

function tgo_can_configure() {
	return current_user_can( 'manage_woocommerce' );
}

function tgo_presets() {
	return array(
		'enjoy' => array(
			'title'  => '享物後台',
			'labels' => array(
				'wc-on-hold'                   => '保留中（尚未出貨）',
				'wc-processing'                => '處理中（出貨中）',
				'wc-completed'                 => '已完成（出貨完成）',
				'wc-cancelled'                 => '已取消（未出即退）',
				'wc-tgo-returned-unrefunded'   => '已退貨／未退款（已收到退貨可退款）',
				'wc-tgo-accounting-refunded'   => '已退款（退款完成）',
				'wc-tgo-accounting-collected'  => '已收款（金流核對完成）',
			),
			'custom' => array(
				'returned-unrefunded'  => '已退貨／未退款（已收到退貨可退款）',
				'accounting-refunded'  => '已退款（退款完成）',
				'accounting-collected' => '已收款（金流核對完成）',
			),
			'shipping'   => array( 'wc-on-hold', 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-tgo-returned-unrefunded' ),
			'accounting' => array( 'wc-tgo-accounting-refunded', 'wc-tgo-accounting-collected' ),
			'invoice'    => array( 'confirmed' => '確認狀態' ),
		),
		'lc' => array(
			'title'  => 'LC 後台',
			'labels' => array(
				'wc-on-hold'                 => '保留中（尚未出貨）',
				'wc-tgo-lc-processed'        => '已處理（一般出貨完成）',
				'wc-tgo-lc-display-processed'=> '展備品已處理（展備出貨完成）',
				'wc-tgo-lc-returned'         => '已退貨，未退款（已收到退貨）',
				'wc-cancelled'               => '已取消（未出即退）',
				'wc-tgo-lc-reconciling'      => '對帳中（業務結算）',
				'wc-tgo-lc-sample-recovered' => '樣品已回收（展品、樣品回收）',
				'wc-tgo-lc-invoiced'         => '已開發票（已提供對帳單）',
				'wc-tgo-lc-collected'        => '已收款（確認收到款項）',
				'wc-tgo-lc-refunded'         => '已退款（已扣入下一期款項／匯款給對方）',
			),
			'custom' => array(
				'lc-processed'        => '已處理（一般出貨完成）',
				'lc-display-processed'=> '展備品已處理（展備出貨完成）',
				'lc-returned'         => '已退貨，未退款（已收到退貨）',
				'lc-reconciling'      => '對帳中（業務結算）',
				'lc-sample-recovered' => '樣品已回收（展品、樣品回收）',
				'lc-invoiced'         => '已開發票（已提供對帳單）',
				'lc-collected'        => '已收款（確認收到款項）',
				'lc-refunded'         => '已退款（已扣入下一期款項／匯款給對方）',
			),
			'shipping'   => array( 'wc-on-hold', 'wc-tgo-lc-processed', 'wc-tgo-lc-display-processed', 'wc-tgo-lc-returned', 'wc-cancelled', 'wc-tgo-lc-reconciling', 'wc-tgo-lc-sample-recovered' ),
			'accounting' => array( 'wc-tgo-lc-invoiced', 'wc-tgo-lc-collected', 'wc-tgo-lc-refunded' ),
			'invoice'    => array( 'issued' => '已開立', 'posted' => '已過帳' ),
		),
	);
}

function tgo_selectable_statuses() {
	$statuses = wc_get_order_statuses();
	$settings = TGO_Order_Permissions_Statuses::settings();
	$statuses = array_diff_key( $statuses, array_flip( $settings['hidden_statuses'] ) );
	$role = TGO_Order_Permissions_Statuses::assigned_role();
	if ( in_array( $role, array( 'shipping', 'accounting' ), true ) ) {
		$allowed = $settings['allowed_statuses'][ $role ];
		return array_intersect_key( $statuses, array_flip( $allowed ) );
	}
	return $statuses;
}
