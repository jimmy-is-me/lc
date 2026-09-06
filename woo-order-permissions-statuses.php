<?php
/**
 * Plugin Name: 訂單權限與狀態管理
 * Description: 管理 WooCommerce 訂單狀態名稱、可操作狀態與帳號權限。
 * Version: 1.0.0
 * Author: Custom Development
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/workflows.php';

final class TGO_Order_Permissions_Statuses {
	const OPTION = 'tgo_order_permissions_settings';
	const NONCE = 'tgo_settings_nonce';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_custom_statuses' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'filter_order_statuses' ), 999 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'prevent_order_deletion' ), 20, 4 );
		add_action( 'woocommerce_before_trash_order', array( __CLASS__, 'block_order_delete' ), 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'block_order_delete' ), 1 );
		add_action( 'wp_ajax_woocommerce_delete_order_note', array( __CLASS__, 'block_order_note_deletion' ), 0 );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_user_permissions_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_user_permissions_column' ), 10, 3 );
		add_action( 'woocommerce_order_actions_start', array( __CLASS__, 'order_extra_fields' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_order_extra_fields' ) );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'enforce_allowed_status' ), 1, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_confirmation_field' ) );
		add_action( 'wp_ajax_tgo_toggle_order_confirmation', array( __CLASS__, 'toggle_order_confirmation' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_confirmation_column' ), 30 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_confirmation_column' ), 30, 2 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_order_confirmation_column' ), 30 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_hpos_order_confirmation_column' ), 30, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'filter_order_bulk_actions' ), 999 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'filter_order_bulk_actions' ), 999 );
		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_refunded_order' ) as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'maybe_disable_status_email' ), 20, 2 );
		}
	}

	public static function defaults() {
		return array(
			'accounts' => array( 'shipping' => array(), 'accounting' => array(), 'owner' => array() ),
			'allowed_statuses' => array( 'shipping' => array(), 'accounting' => array() ),
			'status_labels' => array(),
			'hidden_statuses' => array(),
			'custom_statuses' => array(),
			'invoice_statuses' => array( 'issued' => '已開立', 'posted' => '已過帳' ),
			'email_disabled_statuses' => array(),
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		foreach ( array( 'shipping', 'accounting', 'owner' ) as $role ) {
			$settings['accounts'][ $role ] = array_values( array_filter( array_map( 'absint', (array) ( $settings['accounts'][ $role ] ?? array() ) ) ) );
		}
		foreach ( array( 'shipping', 'accounting' ) as $role ) {
			$settings['allowed_statuses'][ $role ] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['allowed_statuses'][ $role ] ?? array() ) ) ) );
		}
		$settings['status_labels'] = is_array( $settings['status_labels'] ) ? $settings['status_labels'] : array();
		$settings['hidden_statuses'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['hidden_statuses'] ?? array() ) ) ) );
		$settings['custom_statuses'] = is_array( $settings['custom_statuses'] ) ? $settings['custom_statuses'] : array();
		$settings['invoice_statuses'] = is_array( $settings['invoice_statuses'] ) ? $settings['invoice_statuses'] : self::defaults()['invoice_statuses'];
		$settings['email_disabled_statuses'] = array_values( array_filter( array_map( 'sanitize_key', (array) ( $settings['email_disabled_statuses'] ?? array() ) ) ) );
		return $settings;
	}

	public static function is_settings_request() {
		return is_admin() && isset( $_REQUEST['page'] ) && 'tgo-order-permissions' === sanitize_key( wp_unslash( $_REQUEST['page'] ) );
	}

	public static function register_custom_statuses() {
		foreach ( self::settings()['custom_statuses'] as $slug => $label ) {
			register_post_status( 'wc-tgo-' . sanitize_key( $slug ), array(
				'label' => sanitize_text_field( $label ), 'public' => true, 'exclude_from_search' => false,
				'show_in_admin_all_list' => true, 'show_in_admin_status_list' => true,
				'label_count' => _n_noop( sanitize_text_field( $label ) . ' <span class="count">(%s)</span>', sanitize_text_field( $label ) . ' <span class="count">(%s)</span>' ),
			) );
		}
	}

	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	public static function woocommerce_notice() {
		if ( self::woocommerce_active() || ! current_user_can( 'activate_plugins' ) ) return;
		echo '<div class="notice notice-warning"><p><strong>訂單權限與狀態管理：</strong>請先啟用 WooCommerce。</p></div>';
	}

	public static function admin_menu() {
		add_menu_page( '訂單權限與狀態管理', '訂單權限管理', 'manage_woocommerce', 'tgo-order-permissions', array( __CLASS__, 'settings_page' ), 'dashicons-shield-alt', 56 );
	}

	public static function admin_assets() {
		$screen = get_current_screen();
		if ( ! $screen || ( 'toplevel_page_tgo-order-permissions' !== $screen->id && 'users' !== $screen->id && false === strpos( $screen->id, 'shop_order' ) && false === strpos( $screen->id, 'wc-orders' ) ) ) return;
		wp_enqueue_style( 'tgo-order-permissions-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), '1.0.0' );
		wp_enqueue_script( 'tgo-order-permissions-admin', plugins_url( 'assets/admin.js', __FILE__ ), array( 'jquery' ), '1.0.0', true );
		wp_localize_script( 'tgo-order-permissions-admin', 'tgoOrderPermissions', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'tgo_confirmation' ) ) );
	}

	/** Return every status WooCommerce currently provides, including other plugins' custom statuses. */
	public static function current_statuses() {
		return self::woocommerce_active() ? wc_get_order_statuses() : array();
	}

	public static function save_settings() {
		if ( ! isset( $_POST['tgo_save_settings'] ) && ! isset( $_POST['tgo_preset'] ) ) return;
		if ( ! tgo_can_configure() ) wp_die( '無此權限' );
		check_admin_referer( self::NONCE );

		$current_statuses = self::current_statuses();
		$posted = wp_unslash( $_POST );
		$settings = self::defaults();
		$preset_key = isset( $posted['tgo_preset'] ) ? sanitize_key( $posted['tgo_preset'] ) : '';
		$presets = tgo_presets();
		if ( isset( $presets[ $preset_key ] ) ) {
			$preset = $presets[ $preset_key ];
			$settings['custom_statuses'] = $preset['custom'];
			$settings['status_labels'] = $preset['labels'];
			$settings['allowed_statuses']['shipping'] = $preset['shipping'];
			$settings['allowed_statuses']['accounting'] = $preset['accounting'];
			$settings['invoice_statuses'] = $preset['invoice'];
		}
		foreach ( array( 'shipping', 'accounting', 'owner' ) as $role ) {
			$settings['accounts'][ $role ] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $posted['accounts'][ $role ] ?? array() ) ) ) ) );
		}
		foreach ( array( 'shipping', 'accounting' ) as $role ) {
			$selected = array_map( 'sanitize_key', (array) ( $posted['allowed_statuses'][ $role ] ?? array() ) );
			$settings['allowed_statuses'][ $role ] = array_values( array_intersect( $selected, array_keys( $current_statuses ) ) );
		}
		$hidden = array_map( 'sanitize_key', (array) ( $posted['hidden_statuses'] ?? array() ) );
		$settings['hidden_statuses'] = array_values( array_intersect( $hidden, array_keys( $current_statuses ) ) );
		$email_disabled = array_map( 'sanitize_key', (array) ( $posted['email_disabled_statuses'] ?? array() ) );
		$settings['email_disabled_statuses'] = array_values( array_intersect( $email_disabled, array_keys( $current_statuses ) ) );
		$settings['custom_statuses'] = isset( $settings['custom_statuses'] ) && $settings['custom_statuses'] ? $settings['custom_statuses'] : self::settings()['custom_statuses'];
		$new_slug = isset( $posted['new_status_slug'] ) ? sanitize_title( $posted['new_status_slug'] ) : '';
		$new_label = isset( $posted['new_status_label'] ) ? sanitize_text_field( $posted['new_status_label'] ) : '';
		if ( $new_slug && $new_label && ! isset( $settings['custom_statuses'][ $new_slug ] ) ) $settings['custom_statuses'][ $new_slug ] = $new_label;
		$settings['invoice_statuses'] = array();
		foreach ( (array) ( $posted['invoice_statuses'] ?? array() ) as $invoice_key => $label ) {
			$invoice_key = sanitize_key( $invoice_key );
			$label = sanitize_text_field( $label );
			if ( $invoice_key && '' !== $label ) $settings['invoice_statuses'][ $invoice_key ] = $label;
		}
		$new_invoice_slug = isset( $posted['new_invoice_slug'] ) ? sanitize_title( $posted['new_invoice_slug'] ) : '';
		$new_invoice_label = isset( $posted['new_invoice_label'] ) ? sanitize_text_field( $posted['new_invoice_label'] ) : '';
		if ( $new_invoice_slug && $new_invoice_label && ! isset( $settings['invoice_statuses'][ $new_invoice_slug ] ) ) $settings['invoice_statuses'][ $new_invoice_slug ] = $new_invoice_label;
		foreach ( $current_statuses as $status_key => $old_label ) {
			$label = isset( $posted['status_labels'][ $status_key ] ) ? sanitize_text_field( $posted['status_labels'][ $status_key ] ) : '';
			if ( '' === $label ) {
				unset( $settings['status_labels'][ $status_key ] );
			} else {
				$settings['status_labels'][ $status_key ] = $label;
			}
		}
		if ( isset( $presets[ $preset_key ] ) ) {
			$preset = $presets[ $preset_key ];
			$settings['custom_statuses'] = $preset['custom'];
			$settings['status_labels'] = $preset['labels'];
			$settings['allowed_statuses']['shipping'] = $preset['shipping'];
			$settings['allowed_statuses']['accounting'] = $preset['accounting'];
			$settings['invoice_statuses'] = $preset['invoice'];
		}
		update_option( self::OPTION, $settings );
		wp_safe_redirect( add_query_arg( array( 'page' => 'tgo-order-permissions', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Apply renamed labels everywhere WooCommerce asks for order-status labels. */
	public static function filter_order_statuses( $statuses ) {
		$settings = self::settings();
		foreach ( $settings['custom_statuses'] as $slug => $label ) {
			$statuses['wc-tgo-' . sanitize_key( $slug )] = sanitize_text_field( $label );
		}
		foreach ( $settings['status_labels'] as $status_key => $label ) {
			if ( isset( $statuses[ $status_key ] ) && '' !== $label ) $statuses[ $status_key ] = $label;
		}
		return $statuses;
	}

	public static function assigned_role( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$accounts = self::settings()['accounts'];
		if ( in_array( (int) $user_id, $accounts['owner'], true ) ) return 'owner';
		if ( in_array( (int) $user_id, $accounts['shipping'], true ) ) return 'shipping';
		if ( in_array( (int) $user_id, $accounts['accounting'], true ) ) return 'accounting';
		return '';
	}

	public static function status_names( $keys, $statuses ) {
		$labels = array();
		$renamed = self::settings()['status_labels'];
		foreach ( (array) $keys as $key ) $labels[] = $renamed[ $key ] ?? ( $statuses[ $key ] ?? $key );
		return implode( '、', $labels );
	}

	public static function status_email_info( $status_key ) {
		$map = array(
			'wc-processing' => array( 'customer_processing_order', '訂單處理中通知' ),
			'wc-on-hold' => array( 'customer_on_hold_order', '訂單保留通知' ),
			'wc-completed' => array( 'customer_completed_order', '訂單完成通知' ),
			'wc-refunded' => array( 'customer_refunded_order', '訂單退款通知' ),
		);
		return $map[ $status_key ] ?? array( '', '此狀態沒有 WooCommerce 預設客戶信件' );
	}

	public static function maybe_disable_status_email( $enabled, $order ) {
		if ( ! $order || ! is_callable( array( $order, 'get_status' ) ) ) return $enabled;
		$status_key = 'wc-' . $order->get_status();
		return in_array( $status_key, self::settings()['email_disabled_statuses'], true ) ? false : $enabled;
	}

	/** Keep legacy and HPOS order-list bulk actions in sync with this panel. */
	public static function filter_order_bulk_actions( $actions ) {
		foreach ( array_keys( $actions ) as $action_key ) {
			if ( 0 === strpos( $action_key, 'mark_' ) ) unset( $actions[ $action_key ] );
		}
		foreach ( tgo_selectable_statuses() as $status_key => $label ) {
			$actions['mark_' . substr( $status_key, 3 )] = '變更狀態為：' . $label;
		}
		return $actions;
	}

	public static function can_change_to_status( $status ) {
		$role = self::assigned_role();
		if ( 'owner' === $role || ! in_array( $role, array( 'shipping', 'accounting' ), true ) ) return true;
		$status_key = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		return in_array( $status_key, self::settings()['allowed_statuses'][ $role ], true );
	}

	public static function enforce_allowed_status( $order, $data_store ) {
		if ( ! is_admin() || ! $order || ! is_callable( array( $order, 'get_changes' ) ) ) return;
		$changes = $order->get_changes();
		if ( empty( $changes['status'] ) || self::can_change_to_status( $changes['status'] ) ) return;
		throw new Exception( '無此權限：您不可切換至此訂單狀態。' );
	}

	public static function add_order_confirmation_column( $columns ) {
		$columns['tgo_confirmation'] = '確認狀態';
		return $columns;
	}

	public static function confirmation_checkbox( $order ) {
		if ( ! $order ) return '';
		$checked = 'yes' === $order->get_meta( '_tgo_accounting_confirmed', true );
		$disabled = ! in_array( self::assigned_role(), array( 'accounting', 'owner' ), true ) ? ' disabled' : '';
		return '<label class="tgo-confirm"><input type="checkbox" class="tgo-confirmation-toggle" data-order-id="' . esc_attr( $order->get_id() ) . '" ' . checked( $checked, true, false ) . $disabled . '> 已確認</label>';
	}

	public static function render_order_confirmation_column( $column, $post_id ) {
		if ( 'tgo_confirmation' !== $column ) return;
		echo self::confirmation_checkbox( wc_get_order( $post_id ) );
	}

	public static function render_hpos_order_confirmation_column( $column, $order ) {
		if ( 'tgo_confirmation' !== $column ) return;
		echo self::confirmation_checkbox( $order );
	}

	public static function order_confirmation_field( $order ) {
		if ( is_numeric( $order ) ) $order = wc_get_order( $order );
		if ( ! $order || ! in_array( self::assigned_role(), array( 'accounting', 'owner' ), true ) ) return;
		echo '<p class="form-field"><label><input type="checkbox" name="tgo_accounting_confirmed" value="yes" ' . checked( 'yes', $order->get_meta( '_tgo_accounting_confirmed', true ), false ) . '> 確認狀態</label></p>';
	}

	public static function toggle_order_confirmation() {
		check_ajax_referer( 'tgo_confirmation', 'nonce' );
		if ( ! in_array( self::assigned_role(), array( 'accounting', 'owner' ), true ) ) wp_send_json_error( array( 'message' => '無此權限' ), 403 );
		$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) wp_send_json_error( array( 'message' => '無此權限' ), 403 );
		$order->update_meta_data( '_tgo_accounting_confirmed', isset( $_POST['confirmed'] ) && 'yes' === $_POST['confirmed'] ? 'yes' : 'no' );
		$order->save();
		wp_send_json_success();
	}

	public static function prevent_order_deletion( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( self::assigned_role( $user_id ), array( 'shipping', 'accounting' ), true ) ) return $caps;
		if ( in_array( $cap, array( 'delete_shop_order', 'delete_woocommerce_order' ), true ) ) return array( 'do_not_allow' );
		if ( 'delete_post' !== $cap || empty( $args[0] ) ) return $caps;
		return 'shop_order' === get_post_type( $args[0] ) ? array( 'do_not_allow' ) : $caps;
	}

	public static function block_order_delete() {
		if ( in_array( self::assigned_role(), array( 'shipping', 'accounting' ), true ) ) wp_die( '此帳號沒有刪除訂單的權限。', '操作被拒絕', array( 'response' => 403, 'back_link' => true ) );
	}

	public static function block_order_note_deletion() {
		if ( in_array( self::assigned_role(), array( 'shipping', 'accounting' ), true ) ) wp_send_json_error( array( 'message' => '無此權限：此帳號不可刪除訂單備註。' ), 403 );
	}

	public static function add_user_permissions_column( $columns ) {
		$columns['tgo_order_permissions'] = '訂單額外權限';
		return $columns;
	}

	public static function render_user_permissions_column( $output, $column_name, $user_id ) {
		if ( 'tgo_order_permissions' !== $column_name ) return $output;
		$role = self::assigned_role( $user_id );
		if ( ! $role ) return '<span class="tgo-perm-empty">—</span>';
		if ( 'owner' === $role ) return '<strong class="tgo-perm-owner">老闆：全功能</strong>';
		$label = 'shipping' === $role ? '出貨／業務' : '會計';
		$allowed = self::settings()['allowed_statuses'][ $role ];
		$status_text = empty( $allowed ) ? '未限制狀態' : sprintf( '可切換 %d 個狀態', count( $allowed ) );
		return '<strong>' . esc_html( $label ) . '</strong><br><span class="tgo-perm-restricted">不可刪除訂單／備註</span><br><span class="tgo-perm-status">' . esc_html( $status_text ) . '</span>';
	}

	public static function settings_page() {
		if ( ! tgo_can_configure() ) return;
		$settings = self::settings();
		$statuses = self::current_statuses();
		$users = get_users( array( 'orderby' => 'display_name' ) );
		?>
		<div class="wrap tgo-console">
			<div class="tgo-hero"><h1>訂單權限與狀態管理</h1><p>帳號權限、訂單狀態與發票狀態的統一控制中心</p></div>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div><?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<div class="tgo-card"><h2>套用案件範本</h2><p class="description">每個網站安裝後選擇一次範本，即會建立該網站的狀態名稱、出貨／業務與會計可操作狀態，以及發票狀態。帳號不會自動指定。</p><p><?php foreach ( tgo_presets() as $preset_key => $preset ) : ?><button class="button" type="submit" name="tgo_preset" value="<?php echo esc_attr( $preset_key ); ?>">套用 <?php echo esc_html( $preset['title'] ); ?> 範本</button>&nbsp;<?php endforeach; ?></p></div>
				<div class="tgo-card"><h2>帳號與可操作狀態</h2>
				<p class="description">可為出貨／業務與會計各設定多位帳號。未勾選狀態即不可切換；只有指定為老闆的帳號不限制。</p>
				<table class="widefat striped tgo-table-accounts"><thead><tr><th class="tgo-col-role">帳號類型</th><th class="tgo-col-accounts">指定帳號（可複選）</th><th>可切換的訂單狀態（可複選）</th></tr></thead><tbody>
				<?php foreach ( array( 'shipping' => '出貨／業務', 'accounting' => '會計', 'owner' => '老闆（全功能）' ) as $role => $title ) : ?>
				<tr><td><strong><?php echo esc_html( $title ); ?></strong><br><small><?php echo 'owner' === $role ? '不限制、可刪除訂單' : '不可刪除訂單'; ?></small></td><td><select class="tgo-account-select" multiple name="accounts[<?php echo esc_attr( $role ); ?>][]" size="7">
				<?php foreach ( $users as $user ) : ?><option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( in_array( $user->ID, $settings['accounts'][ $role ], true ) ); ?>><?php echo esc_html( $user->display_name . '（' . $user->user_email . '）' ); ?></option><?php endforeach; ?>
				</select></td><td><?php if ( 'owner' === $role ) : ?><em>老闆帳號不限制狀態。</em><?php else : foreach ( $statuses as $status_key => $label ) : ?><label class="tgo-status-choice"><input type="checkbox" name="allowed_statuses[<?php echo esc_attr( $role ); ?>][]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $settings['allowed_statuses'][ $role ], true ) ); ?>> <?php echo esc_html( $settings['status_labels'][ $status_key ] ?? $label ); ?></label><?php endforeach; endif; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php if ( isset( $_GET['updated'] ) ) : ?>
				<h3>目前已儲存的帳號設定</h3>
				<table class="widefat striped tgo-table-accounts"><thead><tr><th>帳號類型</th><th>已套用帳號</th><th>可切換狀態</th></tr></thead><tbody>
				<?php foreach ( array( 'shipping' => '出貨／業務', 'accounting' => '會計', 'owner' => '老闆（全功能）' ) as $role => $title ) : $names = array(); foreach ( $settings['accounts'][ $role ] as $uid ) { $u = get_userdata( $uid ); if ( $u ) $names[] = $u->display_name . '（' . $u->user_email . '）'; } ?>
				<tr><td><strong><?php echo esc_html( $title ); ?></strong></td><td><?php echo $names ? esc_html( implode( '、', $names ) ) : '尚未指定'; ?></td><td><?php echo 'owner' === $role ? '全部狀態' : ( ! empty( $settings['allowed_statuses'][ $role ] ) ? esc_html( self::status_names( $settings['allowed_statuses'][ $role ], $statuses ) ) : '未限制（可操作全部顯示狀態）' ); ?></td></tr>
				<?php endforeach; ?></tbody></table>
				<?php endif; ?></div>

				<div class="tgo-card"><h2>網站目前的訂單狀態名稱</h2>
				<p class="description">可直接修改名稱或勾選隱藏。名稱會同步用於 WooCommerce 後台、訂單列表、會員中心等標準訂單狀態畫面；狀態代碼與既有訂單資料不會改變。隱藏後不會出現在一般狀態選單，但不會刪除既有訂單資料。</p>
				<table class="widefat striped tgo-table-statuses"><thead><tr><th>狀態代碼</th><th>目前名稱</th><th>改為顯示名稱</th><th>預設 WooCommerce 信件</th><th>隱藏此狀態</th></tr></thead><tbody>
				<?php foreach ( $statuses as $status_key => $label ) : $shown = $settings['status_labels'][ $status_key ] ?? $label; $email_info = self::status_email_info( $status_key ); ?>
				<tr><td><code><?php echo esc_html( $status_key ); ?></code></td><td><?php echo esc_html( $label ); ?></td><td><input type="text" class="regular-text" name="status_labels[<?php echo esc_attr( $status_key ); ?>]" value="<?php echo esc_attr( $shown ); ?>"></td><td class="tgo-email-note"><?php if ( $email_info[0] ) : ?><strong><?php echo esc_html( $email_info[1] ); ?></strong><br><label><input type="checkbox" name="email_disabled_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $settings['email_disabled_statuses'], true ) ); ?>> 不寄送此信件</label><?php else : ?><span>— <?php echo esc_html( $email_info[1] ); ?></span><?php endif; ?></td><td><label><input type="checkbox" name="hidden_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" <?php checked( in_array( $status_key, $settings['hidden_statuses'], true ) ); ?>> 隱藏此狀態</label></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<h3>新增訂單狀態</h3>
				<p class="tgo-new-status"><label><strong>英文系統代碼</strong> <span class="tgo-chip">系統內部使用</span><br><input type="text" class="regular-text" name="new_status_slug" placeholder="例如：sample-returned"><br><small>限英文、數字與連字號；建立後的代碼不建議變更。</small></label><label><strong>中文顯示標籤</strong> <span class="tgo-chip">使用者看到的名稱</span><br><input type="text" class="regular-text" name="new_status_label" placeholder="例如：樣品已收回"><br><small>會顯示於 WooCommerce 後台及會員訂單畫面。</small></label></p></div>

				<div class="tgo-card"><h2>會計可用的發票狀態</h2>
				<p class="description">此處設定會計在訂單編輯頁可選擇的發票狀態名稱。清空名稱後，該選項不會顯示。</p>
				<table class="widefat striped tgo-table-invoice"><thead><tr><th>狀態代碼</th><th>顯示名稱</th></tr></thead><tbody>
				<?php foreach ( $settings['invoice_statuses'] as $key => $label ) : ?><tr><td><code><?php echo esc_html( $key ); ?></code></td><td><input class="regular-text" type="text" name="invoice_statuses[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $label ); ?>"></td></tr><?php endforeach; ?>
				</tbody></table>
				<h3>新增發票狀態</h3><p class="tgo-new-status"><label><strong>英文系統代碼</strong><br><input type="text" class="regular-text" name="new_invoice_slug" placeholder="例如：invoice-sent"></label><label><strong>中文顯示標籤</strong><br><input type="text" class="regular-text" name="new_invoice_label" placeholder="例如：已寄送發票"></label></p></div>

				<div class="tgo-card"><h2>案件設定參考</h2>
				<div class="tgo-reference">
					<strong>享物：</strong>保留中（尚未出貨）、處理中（出貨中）、已完成（出貨完成）、已取消（未出即退）、已退貨／未退款（已收到退貨可退款）；會計：已請款、已收款，發票狀態可切換。<br>
					<strong>LC：</strong>保留中（尚未出貨）、已處理（出貨完成）、已完成（整備出貨完成）、已退貨（未退款）、已取消、對帳中；會計：已開發票、已收款、已退款，發票狀態採獨立勾選。
				</div></div>
				<p><button class="button button-primary tgo-save" name="tgo_save_settings" value="1">儲存設定</button></p>
			</form>
		</div>
		<?php
	}

	public static function order_extra_fields( $order = null ) {
		if ( is_numeric( $order ) ) $order = wc_get_order( $order );
		if ( ! $order || ! is_callable( array( $order, 'get_id' ) ) ) return;
		if ( ! current_user_can( 'edit_shop_order', $order->get_id() ) || ! in_array( self::assigned_role(), array( 'accounting', 'owner' ), true ) ) return;
		$invoice = (string) $order->get_meta( '_tgo_invoice_status' );
		$invoice_statuses = self::settings()['invoice_statuses'];
		?>
		<div class="tgo-invoice-box"><h4>發票狀態</h4><p class="form-field"><label for="tgo_invoice_status">選擇發票狀態</label><select name="tgo_invoice_status" id="tgo_invoice_status"><option value="">— 未設定 —</option><?php foreach ( $invoice_statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $invoice, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p></div>
		<?php
	}

	public static function save_order_extra_fields( $order_id ) {
		if ( ! current_user_can( 'edit_shop_order', $order_id ) || ! in_array( self::assigned_role(), array( 'accounting', 'owner' ), true ) ) return;
		if ( ! isset( $_POST['tgo_invoice_status'] ) ) return;
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_tgo_invoice_status', sanitize_key( wp_unslash( $_POST['tgo_invoice_status'] ) ) );
			$order->update_meta_data( '_tgo_accounting_confirmed', isset( $_POST['tgo_accounting_confirmed'] ) ? 'yes' : 'no' );
			$order->save();
		}
	}
}

TGO_Order_Permissions_Statuses::init();
