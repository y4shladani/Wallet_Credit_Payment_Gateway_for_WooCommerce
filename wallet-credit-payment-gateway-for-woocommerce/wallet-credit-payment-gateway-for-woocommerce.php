<?php
/**
 * Plugin Name:       Wallet Credit Payment Gateway for WooCommerce
 * Description:       Provides a wallet credit payment gateway for WooCommerce.Seamlessly integrates with the classic checkout shortcode. Please note that it does not currently support the checkout block.
 * Requires at least: 6.1
 * Requires PHP:      7.0
 * Version:           1.0.0
 * Author:            Yash Ladani
 * Author URI:        https://www.linkedin.com/in/yash-ladani-7b794627a/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wcpg
 */

/**
 * Add wallet credit payment gateway class to WooCommerce payment gateways.
 *
 * @param array $gateways List of WooCommerce payment gateways.
 * @return array Modified list of WooCommerce payment gateways.
 */
function wcpg_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_Wallet_Credit_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wcpg_add_gateway_class' );

/**
 * Initialize the wallet credit payment gateway class.
 */
function wcpg_init_gateway_class() {
	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		/**
		 * Class WC_Wallet_Credit_Gateway
		 */
		class WC_Wallet_Credit_Gateway extends WC_Payment_Gateway {
			/**
			 * WC_Wallet_Credit_Gateway constructor.
			 */
			public function __construct() {
				$this->id                 = 'wallet-credit';
				$this->has_fields         = true;
				$this->method_title       = __( 'Wallet Credit Gateway', 'wcpg' );
				$this->method_description = __( 'Pay with your Wallet Credit via our super-cool payment gateway.', 'wcpg' );

				$this->supports = array( 'products' );

				$this->init_form_fields();
				$this->init_settings();

				$this->title       = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );
				$this->enabled     = $this->get_option( 'enabled' );

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			/**
			 * Initialize form fields for the settings of the wallet credit payment gateway.
			 */
			public function init_form_fields() {
				$this->form_fields = array(
					'enabled'     => array(
						'title'       => __( 'Enable/Disable', 'wcpg' ),
						'label'       => __( 'Enable Wallet Credit Gateway', 'wcpg' ),
						'type'        => 'checkbox',
						'description' => '',
						'default'     => 'no',
					),
					'title'       => array(
						'title'       => __( 'Title', 'wcpg' ),
						'type'        => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'wcpg' ),
						'default'     => __( 'Pay By Wallet Credit', 'wcpg' ),
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => __( 'Description', 'wcpg' ),
						'type'        => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'wcpg' ),
						'default'     => __( 'Pay with your Wallet Credit via our super-cool payment gateway.', 'wcpg' ),
					),
				);
			}

			/**
			 * Output fields for the wallet credit payment gateway.
			 */
			public function payment_fields() {
				$user_id                  = get_current_user_id();
				$wallet_balance           = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
				$wallet_balance_formatted = wc_price( $wallet_balance );

				if ( $this->description ) {
					echo wpautop( wp_kses_post( $this->description ) );
				}

				echo '<p>' . sprintf( esc_html__( 'Your current wallet balance: %1$s', 'wcpg' ), $wallet_balance_formatted ) . '</p>';
			}

			/**
			 * Process payment for the wallet credit payment gateway.
			 *
			 * @param int $order_id Order ID.
			 * @return array Payment result.
			 */
			public function process_payment( $order_id ) {

				$order          = wc_get_order( $order_id );
				$user_id        = $order->get_user_id();
				$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
				$order_total    = $order->get_total();

				if ( $wallet_balance >= $order_total ) {
					$new_balance = $wallet_balance - $order_total;
					update_user_meta( $user_id, 'wcpg_wallet_balance', $new_balance );
					$order->payment_complete();
					wc_reduce_stock_levels( $order_id );
					WC()->cart->empty_cart();
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				} else {

					// This is not the best way to handle this.
					// We can use 'function payment_scripts()' and check in the js file with AJAX whether the user has sufficient balance or not before coming to 'function process_payment()', this way we can avoid failed order.

					// Set order status to failed.
					$order->update_status( 'failed', __( 'Insufficient wallet balance.', 'wcpg' ) );

					$notice_message_1 = sprintf( __( 'Order #%d has failed due to insufficient wallet balance.', 'wcpg' ), $order_id );
					$notice_message_2 = __( 'Your order could not be completed due to insufficient wallet balance.', 'wcpg' );

					wc_add_notice( $notice_message_1, 'error' );
					wc_add_notice( $notice_message_2, 'error' );

					$order->add_order_note( $notice_message_2 );

					return;
				}
			}
		}
	}
}
add_action( 'plugins_loaded', 'wcpg_init_gateway_class' );

/**
 * Add wallet balance field to user profile.
 */
function wcpg_add_wallet_balance_field_admin() {
	add_action( 'show_user_profile', 'wcpg_show_wallet_balance_admin' );
	add_action( 'edit_user_profile', 'wcpg_show_wallet_balance_admin' );
}
add_action( 'admin_init', 'wcpg_add_wallet_balance_field_admin' );

/**
 * Show wallet balance field in user profile.
 *
 * @param WP_User $user User object.
 */
function wcpg_show_wallet_balance_admin( $user ) {
	$wallet_balance = get_user_meta( $user->ID, 'wcpg_wallet_balance', true );
	?>
	<h3><?php esc_html_e( 'Wallet Balance', 'wcpg' ); ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="wcpg_wallet_balance"><?php esc_html_e( 'Balance', 'wcpg' ); ?></label></th>
			<td>
				<?php
				$current_user  = wp_get_current_user();
				$allowed_roles = array( 'administrator' );
				if ( array_intersect( $allowed_roles, $current_user->roles ) ) {
					?>
					<input type="number" name="wcpg_wallet_balance" id="wcpg_wallet_balance" class="regular-text" min="0" value="<?php echo esc_attr( $wallet_balance ); ?>" required="required" />
					<?php
				} else {
					echo esc_html( $wallet_balance );
				}
				?>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Save wallet balance field in user profile.
 *
 * @param int $user_id User ID.
 */
function wcpg_save_wallet_balance_field_admin( $user_id ) {
	$current_user  = wp_get_current_user();
	$allowed_roles = array( 'administrator' );
	if ( array_intersect( $allowed_roles, $current_user->roles ) && isset( $_POST['wcpg_wallet_balance'] ) ) {
		$wallet_balance = $_POST['wcpg_wallet_balance'];
		if ( is_numeric( $wallet_balance ) ) {
			update_user_meta( $user_id, 'wcpg_wallet_balance', sanitize_text_field( absint( $wallet_balance ) ) );
		}
	}
}
add_action( 'personal_options_update', 'wcpg_save_wallet_balance_field_admin' );
add_action( 'edit_user_profile_update', 'wcpg_save_wallet_balance_field_admin' );

/**
 * Ensure user has 0 credit when created or existing user does not have credit data.
 *
 * @param int $user_id User ID.
 */
function wcpg_initialize_wallet_balance( $user_id ) {
	$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
	if ( empty( $wallet_balance ) || ! is_numeric( $wallet_balance ) ) {
		update_user_meta( $user_id, 'wcpg_wallet_balance', 0 );
	}
}
add_action( 'user_register', 'wcpg_initialize_wallet_balance' );
add_action( 'profile_update', 'wcpg_initialize_wallet_balance' );

/**
 * Add custom column to the user list in admin.
 *
 * @param array $columns List of columns.
 * @return array Modified list of columns.
 */
function wcpg_add_custom_user_column( $columns ) {
	$current_user  = wp_get_current_user();
	$allowed_roles = array( 'administrator' );
	if ( array_intersect( $allowed_roles, $current_user->roles ) ) {
		$columns['wcpg_wallet_balance'] = __( 'Wallet Balance', 'wcpg' );
	}
	return $columns;
}
add_filter( 'manage_users_columns', 'wcpg_add_custom_user_column' );

/**
 * Populate custom column with user's credit.
 *
 * @param string $output Custom column content.
 * @param string $column_name Column name.
 * @param int    $user_id User ID.
 * @return string Modified custom column content.
 */
function wcpg_display_custom_user_column( $output, $column_name, $user_id ) {
	$current_user  = wp_get_current_user();
	$allowed_roles = array( 'administrator' );
	if ( 'wcpg_wallet_balance' === $column_name && array_intersect( $allowed_roles, $current_user->roles ) ) {
		$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
		if ( empty( $wallet_balance ) || ! is_numeric( $wallet_balance ) ) {
			update_user_meta( $user_id, 'wcpg_wallet_balance', 0 );
			$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
		}
		return $wallet_balance;
	}
	return $output;
}
add_filter( 'manage_users_custom_column', 'wcpg_display_custom_user_column', 10, 3 );

/**
 * Display wallet balance on the user's account page.
 */
function wcpg_display_wallet_balance_on_my_account_page() {
	$user_id        = get_current_user_id();
	$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
	if ( empty( $wallet_balance ) || ! is_numeric( $wallet_balance ) ) {
		update_user_meta( $user_id, 'wcpg_wallet_balance', 0 );
		$wallet_balance = get_user_meta( $user_id, 'wcpg_wallet_balance', true );
	}
	$wallet_balance_formatted = wc_price( $wallet_balance );

	echo '<p>' . sprintf( esc_html__( 'Your current wallet balance: %1$s', 'wcpg' ), $wallet_balance_formatted ) . '</p>';
}
add_action( 'woocommerce_before_my_account', 'wcpg_display_wallet_balance_on_my_account_page' );
