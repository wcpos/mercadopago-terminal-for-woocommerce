<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WCPOS\WooCommercePOSPro\Payments\Server\Reader_Curation;
use WCPOS\WooCommercePOSPro\Payments\Server\Redactor;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;

class Gateway extends \WC_Payment_Gateway {
	public function __construct() {
		$this->id = Settings::GATEWAY_ID;
		$this->method_title = __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' );
		$this->method_description = __( 'Point terminal payments through WooCommerce POS Pro 2.0.', 'mercadopago-terminal-for-woocommerce' );
		$this->supports = array( 'products', 'refunds' );
		$this->has_fields = true;
		$this->init_form_fields();
		$this->init_settings();
		$this->enabled = $this->get_option( 'enabled', 'no' );
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}
	public static function register_gateway( array $methods ): array { $methods[] = __CLASS__; return $methods; }
	public function is_available() { return parent::is_available() && '' !== ( new Settings() )->access_token(); }
	public function payment_fields() {
		global $wp;
		echo wpautop( wp_kses_post( $this->get_description() ) );
		$order = is_checkout_pay_page() ? wc_get_order( $wp->query_vars['order-pay'] ?? 0 ) : false;
		if ( $order ) { wcpos_pro_order_pay_panel( $this, $order ); }
	}
	public function process_payment( $order_id ) { return wcpos_pro_order_pay_process( wc_get_order( $order_id ) ); }
	public function process_refund( $order_id, $amount = null, $reason = '' ) { return wcpos_pro_order_pay_refund( wc_get_order( $order_id ), $amount, $reason ); }
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array( 'title' => __( 'Enable', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'checkbox', 'default' => 'no', 'description' => __( 'POS availability is configured in POS settings.', 'mercadopago-terminal-for-woocommerce' ) ),
			'title' => array( 'title' => __( 'Title', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'text', 'default' => __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' ) ),
			'description' => array( 'title' => __( 'Description', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'textarea', 'default' => __( 'Pay in person on a Mercado Pago Point terminal.', 'mercadopago-terminal-for-woocommerce' ) ),
			'mode' => array( 'title' => __( 'Mode', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'select', 'default' => 'test', 'options' => array( 'test' => __( 'Test', 'mercadopago-terminal-for-woocommerce' ), 'live' => __( 'Live', 'mercadopago-terminal-for-woocommerce' ) ), 'description' => __( "Test credentials drive Mercado Pago's sandbox virtual terminal. Live credentials drive real terminals.", 'mercadopago-terminal-for-woocommerce' ) ),
			'access_token' => array( 'title' => __( 'Access token', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'mptfwc_secret', 'default' => '', 'description' => __( 'The access token from your application under Mercado Pago → Your integrations → Credentials.', 'mercadopago-terminal-for-woocommerce' ) ),
			'webhook_secret' => array(
				'title' => __( 'Webhook secret', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'mptfwc_secret', 'default' => '',
				/* translators: %s: webhook URL. */
				'description' => sprintf( __( 'Add %s under Your integrations → Webhooks with the "Order (Mercado Pago)" event, then paste the secret signature here.', 'mercadopago-terminal-for-woocommerce' ), esc_url( ( new Settings() )->webhook_url() ) ),
			),
		);
	}
	public function generate_mptfwc_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$saved = (string) $this->get_option( $key );
		$placeholder = '' !== $saved ? sprintf( __( 'Saved (ends in %s). Leave blank to keep.', 'mercadopago-terminal-for-woocommerce' ), substr( $saved, -4 ) ) : ( $data['placeholder'] ?? '' );
		return '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr( $field_key ) . '">' . esc_html( $data['title'] ) . '</label></th>'
			. '<td class="forminp"><fieldset><legend class="screen-reader-text"><span>' . esc_html( $data['title'] ) . '</span></legend>'
			. '<input class="input-text regular-input" type="password" autocomplete="new-password" name="' . esc_attr( $field_key ) . '" id="' . esc_attr( $field_key ) . '" value="" placeholder="' . esc_attr( $placeholder ) . '" />'
			. '<p class="description">' . wp_kses_post( $data['description'] ?? '' ) . '</p></fieldset></td></tr>';
	}

	public function validate_mptfwc_secret_field( $key, $value ) {
		$value = trim( (string) $value );
		return '' === $value ? $this->get_option( $key ) : $value;
	}

	public function process_admin_options() {
		$result = parent::process_admin_options();
		Reader_Curation::forget( $this->id );
		delete_option( 'mptfwc_last_verified_webhook' );
		return $result;
	}
	public function admin_options(): void {
		parent::admin_options();
		$readers = ( new Provider_Adapter() )->list_readers();
		echo '<h2>' . esc_html__( 'Mercado Pago diagnostics', 'mercadopago-terminal-for-woocommerce' ) . '</h2><p>';
		echo esc_html( is_wp_error( $readers ) ? $readers->get_error_message() : __( 'Credentials accepted. Restart the terminal after switching to PDV.', 'mercadopago-terminal-for-woocommerce' ) ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=mercadopago-terminal' ) ) . '">' . esc_html__( 'View logs', 'mercadopago-terminal-for-woocommerce' ) . '</a></p>';
		if ( is_wp_error( $readers ) ) { return; }
		echo '<table class="widefat"><thead><tr><th>ID</th><th>' . esc_html__( 'Terminal', 'mercadopago-terminal-for-woocommerce' ) . '</th><th>' . esc_html__( 'Mode', 'mercadopago-terminal-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $readers as $reader ) {
			echo '<tr><td>' . esc_html( $reader['id'] ) . '</td><td>' . esc_html( $reader['label'] ) . '</td><td>' . esc_html( 'online' === $reader['status'] ? 'PDV' : 'STANDALONE' ) . '</td><td>';
			if ( 'offline' === $reader['status'] ) {
				$url = wp_nonce_url( add_query_arg( array( 'action' => 'mptfwc_set_pdv', 'terminal_id' => $reader['id'] ), admin_url( 'admin-post.php' ) ), 'mptfwc_set_pdv' );
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Switch to PDV', 'mercadopago-terminal-for-woocommerce' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	public static function set_pdv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'mptfwc_set_pdv' );
		try {
			( new TerminalService( new MercadoPagoClient( ( new Settings() )->access_token() ) ) )->set_pdv_mode( sanitize_text_field( wp_unslash( $_REQUEST['terminal_id'] ?? '' ) ) );
		} catch ( \Throwable $e ) { wp_die( esc_html( Redactor::message( $e->getMessage() ) ) ); }
		Reader_Curation::forget( Settings::GATEWAY_ID );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Settings::GATEWAY_ID ) );
		exit;
	}
}
