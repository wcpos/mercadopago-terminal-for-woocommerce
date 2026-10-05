<?php
namespace WCPOS\WooCommercePOS\MercadoPagoTerminal;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\MercadoPagoClient;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\PointPaymentService;
use WCPOS\WooCommercePOS\MercadoPagoTerminal\Services\TerminalService;

class Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id = Settings::GATEWAY_ID;
		$this->method_title = __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' );
		$this->method_description = __( 'Accept in-person payments using Mercado Pago Terminal.', 'mercadopago-terminal-for-woocommerce' );
		$this->supports = array( 'products' );
		$this->init_settings();
		$this->init_form_fields();
		$this->title = $this->get_option( 'title', __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay in person on a Mercado Pago Point terminal.', 'mercadopago-terminal-for-woocommerce' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'clear_terminal_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_payment_scripts' ) );
	}

	public static function register_gateway( array $methods ): array { $methods[] = __CLASS__; return $methods; }

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'mercadopago-terminal-for-woocommerce' ),
				'type'        => 'checkbox',
				// This switch governs the online store only. WooCommerce POS enables
				// the gateway from POS → Settings → Checkout, whether or not it is
				// enabled here; a bare "for checkout/POS" implied the POS needed it.
				'label'       => sprintf(
					/* translators: %s: link to WooCommerce POS. */
					__( 'Enable Mercado Pago Terminal for web checkout (not necessary for %s)', 'mercadopago-terminal-for-woocommerce' ),
					'<a href="https://wcpos.com" target="_blank">WooCommerce POS</a>'
				),
				'description' => __( 'This enables the gateway for online store checkout. WooCommerce POS uses this gateway once it is enabled under POS → Settings → Checkout, whether or not it is enabled here.', 'mercadopago-terminal-for-woocommerce' ),
				'default'     => 'no',
			),
			'title' => array( 'title' => __( 'Title', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'text', 'default' => __( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' ) ),
			'description' => array( 'title' => __( 'Description', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'textarea', 'default' => __( 'Pay in person on a Mercado Pago Point terminal.', 'mercadopago-terminal-for-woocommerce' ) ),
			'mode' => array( 'title' => __( 'Mode', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'select', 'default' => 'test', 'options' => array( 'test' => __( 'Test', 'mercadopago-terminal-for-woocommerce' ), 'live' => __( 'Live', 'mercadopago-terminal-for-woocommerce' ) ), 'description' => __( "Test credentials drive Mercado Pago's sandbox virtual terminal. Live credentials drive real terminals.", 'mercadopago-terminal-for-woocommerce' ) ),
			'access_token' => array( 'title' => __( 'Access token', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'password', 'default' => '', 'description' => __( 'The access token from your application under Mercado Pago → Your integrations → Credentials.', 'mercadopago-terminal-for-woocommerce' ) ),
			'webhook_secret' => array(
				'title' => __( 'Webhook secret', 'mercadopago-terminal-for-woocommerce' ), 'type' => 'password', 'default' => '',
				/* translators: %s: webhook URL. */
				'description' => sprintf( __( 'Add %s under Your integrations → Webhooks with the "Order (Mercado Pago)" event, then paste the secret signature here.', 'mercadopago-terminal-for-woocommerce' ), esc_url( ( new Settings() )->webhook_url() ) ),
			),
			'default_terminal_id' => $this->default_terminal_field(),
		);
		$enabled_terminals_field = $this->enabled_terminals_field();
		if ( null !== $enabled_terminals_field ) {
			$this->form_fields['enabled_terminals'] = $enabled_terminals_field;
		}
		$this->form_fields['lock_terminal'] = array(
			'title'       => __( 'Lock terminal selection', 'mercadopago-terminal-for-woocommerce' ),
			'type'        => 'checkbox',
			'label'       => __( 'Cashiers cannot change the terminal at checkout — the default terminal is always used.', 'mercadopago-terminal-for-woocommerce' ),
			'description' => __( 'Requires a default terminal to be selected.', 'mercadopago-terminal-for-woocommerce' ),
			'desc_tip'    => true,
			'default'     => 'no',
		);
		$this->form_fields['show_logs'] = array(
			'title'       => __( 'Checkout debug logs', 'mercadopago-terminal-for-woocommerce' ),
			'type'        => 'checkbox',
			'label'       => __( 'Show the log tools (Show logs / Copy / Clear) on the checkout payment panel.', 'mercadopago-terminal-for-woocommerce' ),
			'description' => __( 'Off by default. Enable only when gathering logs for support — payment activity is always recorded in WooCommerce → Status → Logs regardless of this setting.', 'mercadopago-terminal-for-woocommerce' ),
			'desc_tip'    => true,
			'default'     => 'no',
		);
	}

	/**
	 * Build the "Default terminal" field. When an access token is present and we are
	 * on this gateway's settings screen, the terminals are fetched live from
	 * Mercado Pago so the merchant picks from a dropdown instead of pasting an ID.
	 * Falls back to a plain text field when the list cannot be loaded.
	 */
	private function default_terminal_field(): array {
		$base = array(
			'title'       => __( 'Default terminal', 'mercadopago-terminal-for-woocommerce' ),
			'description' => __( 'Terminal used by default at checkout. Fetched live from your Mercado Pago account (standalone terminals must be switched to PDV).', 'mercadopago-terminal-for-woocommerce' ),
			'desc_tip'    => true,
			'default'     => '',
		);
		$options = $this->fetch_terminal_options();
		if ( null === $options ) {
			return array_merge( $base, array( 'type' => 'text' ) );
		}
		return array_merge(
			$base,
			array( 'type' => 'select', 'options' => array( '' => __( '— Select a terminal —', 'mercadopago-terminal-for-woocommerce' ) ) + $options )
		);
	}

	/**
	 * Build the "Enabled terminals" multiselect. Omitted entirely when the
	 * terminal list cannot be fetched — WooCommerce then leaves the saved
	 * value untouched, so a temporary API failure never wipes the setting.
	 */
	private function enabled_terminals_field(): ?array {
		$options = $this->fetch_terminal_options();
		if ( null === $options ) {
			return null;
		}
		return array(
			'title'       => __( 'Enabled terminals', 'mercadopago-terminal-for-woocommerce' ),
			'type'        => 'multiselect',
			'class'       => 'wc-enhanced-select',
			'options'     => $options,
			'default'     => array(),
			'description' => __( 'Only the selected terminals can be chosen at checkout. Leave empty to allow all terminals. The default terminal is always available at checkout, even if it is not selected here.', 'mercadopago-terminal-for-woocommerce' ),
			'desc_tip'    => true,
		);
	}

	/**
	 * Fetch selectable terminals as an id => label map for the settings fields.
	 * Returns null (callers fall back) when we should not or cannot fetch:
	 * not on this settings screen, no access token, or
	 * an API error. Result is memoized per request and cached in a transient.
	 */
	private $terminal_options_cache = false;
	private function fetch_terminal_options(): ?array {
		if ( false !== $this->terminal_options_cache ) { return $this->terminal_options_cache; }
		$this->terminal_options_cache = null;
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) { return null; }
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return null; }
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Settings::GATEWAY_ID !== $section ) { return null; }
		$settings = new Settings();
		if ( '' === $settings->access_token() ) { return null; }
		// Cache the fetched list so repeated settings-page renders don't each make
		// an HTTP call, and use a short timeout so a cache miss during a Mercado Pago
		// outage fails fast to the text-field fallback instead of hanging admin.
		$cache_key = 'mptfwc_terminal_choices_' . $settings->mode();
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->terminal_options_cache = $cached;
			return $cached;
		}
		try {
			$terminals = ( new TerminalService( new MercadoPagoClient( $settings->access_token() ), $settings ) )->list_terminals( 8 );
		} catch ( \Exception $e ) {
			return null;
		}
		$options = array();
		foreach ( AjaxHandler::selectable_terminals( $terminals ) as $terminal ) {
			$options[ $terminal['id'] ] = sprintf( '%s (%s)', $terminal['label'], $terminal['id'] );
		}
		set_transient( $cache_key, $options, 5 * MINUTE_IN_SECONDS );
		$this->terminal_options_cache = $options;
		return $options;
	}

	public function admin_options(): void {
		parent::admin_options();
		$settings = new Settings();
		echo '<h2>' . esc_html__( 'Mercado Pago Terminal diagnostics', 'mercadopago-terminal-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		$this->row( __( 'Active environment', 'mercadopago-terminal-for-woocommerce' ), $settings->mode() );
		$this->row( __( 'Access token', 'mercadopago-terminal-for-woocommerce' ), '' !== $settings->access_token() ? __( 'configured', 'mercadopago-terminal-for-woocommerce' ) : __( 'MISSING', 'mercadopago-terminal-for-woocommerce' ) );
		$this->row( __( 'Webhook secret', 'mercadopago-terminal-for-woocommerce' ), '' !== $settings->webhook_secret() ? __( 'configured', 'mercadopago-terminal-for-woocommerce' ) : __( 'MISSING — webhook signatures are not verified', 'mercadopago-terminal-for-woocommerce' ) );
		$this->row( __( 'Selected default terminal', 'mercadopago-terminal-for-woocommerce' ), $settings->default_terminal_id() );
		$this->row( __( 'Webhook URL', 'mercadopago-terminal-for-woocommerce' ), $settings->webhook_url() );
		echo '<tr><th>' . esc_html__( 'Payment logs', 'mercadopago-terminal-for-woocommerce' ) . '</th><td>';
		printf(
			/* translators: %s: link to the WooCommerce status logs screen. */
			esc_html__( 'Recorded in %s (source: mercadopago-terminal-for-woocommerce).', 'mercadopago-terminal-for-woocommerce' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce → Status → Logs', 'mercadopago-terminal-for-woocommerce' ) . '</a>'
		);
		echo '</td></tr>';
		echo '</tbody></table>';
		if ( null === $this->fetch_terminal_options() ) { return; }
		try {
			$terminals = ( new TerminalService( new MercadoPagoClient( $settings->access_token() ), $settings ) )->list_terminals( 8 );
		} catch ( \Exception $e ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Terminals', 'mercadopago-terminal-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat" data-nonce="' . esc_attr( wp_create_nonce( 'mptfwc_admin_actions' ) ) . '"><thead><tr>';
		foreach ( array( __( 'ID', 'mercadopago-terminal-for-woocommerce' ), __( 'Label', 'mercadopago-terminal-for-woocommerce' ), __( 'Operating mode', 'mercadopago-terminal-for-woocommerce' ), __( 'Action', 'mercadopago-terminal-for-woocommerce' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $terminals as $terminal ) {
			echo '<tr><td>' . esc_html( $terminal['id'] ) . '</td><td>' . esc_html( $terminal['label'] ) . '</td><td>' . esc_html( $terminal['operating_mode'] ) . '</td><td>';
			if ( 'PDV' !== $terminal['operating_mode'] ) {
				echo '<button type="button" class="button mptfwc-set-pdv" data-terminal-id="' . esc_attr( $terminal['id'] ) . '">' . esc_html__( 'Switch to PDV', 'mercadopago-terminal-for-woocommerce' ) . '</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function payment_fields(): void {
		global $wp;

		$description = apply_filters( 'woocommerce_gateway_description', $this->get_option( 'description' ), $this->id );
		if ( $description ) {
			echo '<p>' . wp_kses_post( $description ) . '</p>';
		}

		$settings = new Settings();
		$order_id = 0;
		$order_token = '';
		$order = null;
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				$order_token = AjaxHandler::order_token( $order_id );
			} else {
				$order_id = 0;
			}
		}

		// Resume the poll loop on reload when an unfinished payment is still open
		// for this order — otherwise a refresh mid-payment drops the cashier back
		// to an idle panel while the payment lingers open on Mercado Pago.
		$resume = false;
		if ( $order && ! $order->is_paid() ) {
			$current = PaymentAttempt::current( $order );
			if ( $current && PaymentAttempt::is_non_final( (string) ( $current['status'] ?? '' ) ) ) {
				$resume = true;
			}
		}

		$is_pos = function_exists( 'woocommerce_pos_request' ) && woocommerce_pos_request();
		$locked = $settings->lock_terminal();
		echo '<div id="mptfwc-payment-interface" class="mptfwc-payment-interface" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '" data-default-terminal-id="' . esc_attr( $settings->default_terminal_id() ) . '" data-pos="' . ( $is_pos ? '1' : '0' ) . '" data-lock-terminal="' . ( $locked ? '1' : '0' ) . '" data-resume="' . ( $resume ? '1' : '0' ) . '" data-gateway-id="' . esc_attr( Settings::GATEWAY_ID ) . '">';
		echo '<div class="mptfwc-payment-card">';
		echo '<h4>' . esc_html__( 'Mercado Pago Terminal', 'mercadopago-terminal-for-woocommerce' ) . '</h4>';
		if ( $order_id ) {
			echo '<p class="mptfwc-payment-help">' . esc_html__( 'Send this order to a Mercado Pago terminal. The payment completes automatically once the terminal confirms.', 'mercadopago-terminal-for-woocommerce' ) . '</p>';
			$default_terminal = $settings->default_terminal_id();
			echo '<div class="mptfwc-terminal-field">';
			echo '<label class="mptfwc-terminal-label" for="mptfwc-terminal-select">' . esc_html__( 'Terminal', 'mercadopago-terminal-for-woocommerce' ) . '</label>';
			if ( $locked ) {
				// Selection is locked to the default terminal; no list is fetched.
				echo '<select id="mptfwc-terminal-select" class="mptfwc-terminal-select" disabled>';
				echo '<option value="' . esc_attr( $default_terminal ) . '" selected>' . esc_html( $default_terminal ) . '</option>';
				echo '</select>';
			} else {
				echo '<select id="mptfwc-terminal-select" class="mptfwc-terminal-select" disabled aria-busy="true">';
				if ( '' !== $default_terminal ) {
					echo '<option value="' . esc_attr( $default_terminal ) . '" selected>' . esc_html( $default_terminal ) . '</option>';
				} else {
					echo '<option value="">' . esc_html__( 'Loading terminals…', 'mercadopago-terminal-for-woocommerce' ) . '</option>';
				}
				echo '</select>';
			}
			echo '</div>';
			// One button that toggles between Start and Cancel: while a payment is
			// in flight the panel polls automatically, so a single control both
			// starts and cancels the terminal payment (no separate status button).
			$action_mode  = $resume ? 'cancel' : 'start';
			$action_label = $resume
				? __( 'Cancel Terminal Payment', 'mercadopago-terminal-for-woocommerce' )
				: __( 'Start Terminal Payment', 'mercadopago-terminal-for-woocommerce' );
			echo '<div class="mptfwc-payment-actions">';
			echo '<button type="button" class="button button-primary mptfwc-primary-action" data-mptfwc-mode="' . esc_attr( $action_mode ) . '" data-order-id="' . esc_attr( $order_id ) . '" data-order-token="' . esc_attr( $order_token ) . '">' . esc_html( $action_label ) . '</button>';
			echo '</div>';
			echo '<div class="mptfwc-payment-status" role="status" aria-live="polite"></div>';
		} else {
			echo '<p class="mptfwc-payment-help">' . esc_html__( 'Payment activity logs will appear here during checkout. If payment creation fails, copy these logs for support.', 'mercadopago-terminal-for-woocommerce' ) . '</p>';
		}
		echo '</div>';

		// The log tools are a support aid, hidden unless the merchant opts in.
		// The textarea itself is always present (JS writes to it) but stays
		// collapsed; only the toolbar visibility is gated.
		$show_logs = $settings->show_logs();
		echo '<div class="mptfwc-logging-section' . ( $show_logs ? '' : ' mptfwc-logging-hidden' ) . '">';
		if ( $show_logs ) {
			echo '<div class="mptfwc-logging-header">';
			echo '<h4>' . esc_html__( 'Logs', 'mercadopago-terminal-for-woocommerce' ) . '</h4>';
			echo '<div class="mptfwc-logging-actions">';
			echo '<button type="button" class="button mptfwc-toggle-log" data-expanded="false">' . esc_html__( 'Show logs', 'mercadopago-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button mptfwc-copy-log">' . esc_html__( 'Copy', 'mercadopago-terminal-for-woocommerce' ) . '</button>';
			echo '<button type="button" class="button mptfwc-clear-log">' . esc_html__( 'Clear', 'mercadopago-terminal-for-woocommerce' ) . '</button>';
			echo '</div>';
			echo '</div>';
		}
		echo '<div class="mptfwc-log-content" style="display: none;">';
		echo '<textarea class="mptfwc-payment-log-textarea" readonly placeholder="' . esc_attr__( 'Mercado Pago Terminal payment activity will appear here...', 'mercadopago-terminal-for-woocommerce' ) . '"></textarea>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<noscript>' . esc_html__( 'Please enable JavaScript to use the Mercado Pago Terminal integration.', 'mercadopago-terminal-for-woocommerce' ) . '</noscript>';
	}

	public function clear_terminal_cache(): void {
		delete_transient( 'mptfwc_terminal_choices_test' );
		delete_transient( 'mptfwc_terminal_choices_live' );

	}
	private function row( string $label, string $value ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>'; }
	public function enqueue_admin_scripts(): void {
		wp_enqueue_script( 'mptfwc-admin', MPTFWC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), MPTFWC_VERSION, true );
		wp_localize_script( 'mptfwc-admin', 'mptfwcAdminData', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	}
	public function enqueue_payment_scripts(): void {
		wp_enqueue_script( 'mptfwc-payment', MPTFWC_PLUGIN_URL . 'assets/js/payment.js', array(), MPTFWC_VERSION, true );
		wp_enqueue_style( 'mptfwc-payment', MPTFWC_PLUGIN_URL . 'assets/css/payment.css', array(), MPTFWC_VERSION );
		wp_localize_script(
			'mptfwc-payment',
			'mptfwcPaymentData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'defaultTerminalId' => ( new Settings() )->default_terminal_id(),
				'pollIntervalMs' => (int) apply_filters( 'mptfwc_poll_interval_ms', 2000 ),
				'pollTimeoutMs' => (int) apply_filters( 'mptfwc_poll_timeout_ms', 360000 ),
				'i18n' => array(
					'logsShown' => __( 'Hide logs', 'mercadopago-terminal-for-woocommerce' ),
					'logsHidden' => __( 'Show logs', 'mercadopago-terminal-for-woocommerce' ),
					'copied' => __( 'Logs copied to clipboard.', 'mercadopago-terminal-for-woocommerce' ),
					'copyFailed' => __( 'Unable to copy logs automatically.', 'mercadopago-terminal-for-woocommerce' ),
					'startAction' => __( 'Start Terminal Payment', 'mercadopago-terminal-for-woocommerce' ),
					'cancelAction' => __( 'Cancel Terminal Payment', 'mercadopago-terminal-for-woocommerce' ),
					'idle' => __( 'Mercado Pago Terminal status: idle', 'mercadopago-terminal-for-woocommerce' ),
					'sending' => __( 'Sending to terminal…', 'mercadopago-terminal-for-woocommerce' ),
					'waiting' => __( 'Waiting for terminal…', 'mercadopago-terminal-for-woocommerce' ),
					'atTerminal' => __( 'Customer is paying on the terminal…', 'mercadopago-terminal-for-woocommerce' ),
					'actionRequired' => __( 'Confirm the payment on the terminal.', 'mercadopago-terminal-for-woocommerce' ),
					'completing' => __( 'Payment complete — finishing order…', 'mercadopago-terminal-for-woocommerce' ),
					'selectTerminal' => __( 'Select a terminal first.', 'mercadopago-terminal-for-woocommerce' ),
					'failed' => __( 'Payment failed. You can try again.', 'mercadopago-terminal-for-woocommerce' ),
					'canceled' => __( 'Payment canceled.', 'mercadopago-terminal-for-woocommerce' ),
					'expired' => __( 'The payment expired on the terminal. You can try again.', 'mercadopago-terminal-for-woocommerce' ),
					'cancelOnTerminal' => __( 'The payment is already on the terminal. Cancel it on the terminal, or wait for it to expire.', 'mercadopago-terminal-for-woocommerce' ),
					'verificationFailed' => __( 'Mercado Pago reported a payment that does not match this order. Check the order notes.', 'mercadopago-terminal-for-woocommerce' ),
					'timedOut' => __( 'Timed out waiting for the terminal. Check the terminal or try again.', 'mercadopago-terminal-for-woocommerce' ),
					'contacting' => __( 'Contacting Mercado Pago…', 'mercadopago-terminal-for-woocommerce' ),
					'requestFailed' => __( 'Mercado Pago Terminal request failed. Copy logs for support.', 'mercadopago-terminal-for-woocommerce' ),
					'noTerminals' => __( 'No terminals found on this Mercado Pago account.', 'mercadopago-terminal-for-woocommerce' ),
					'selectTerminalOption' => __( '— Select a terminal —', 'mercadopago-terminal-for-woocommerce' ),
					'terminalsFailed' => __( 'Could not load terminals.', 'mercadopago-terminal-for-woocommerce' ),
				),
			)
		);
	}
	/**
	 * Completes the order when the terminal payment has already succeeded.
	 *
	 * The terminal payment is created and confirmed out-of-band via AJAX/webhook,
	 * so by the time WooCommerce submits the order-pay form the payment is
	 * usually already reconciled. We confirm it is paid (polling Mercado Pago once more
	 * if needed) and hand WooCommerce the thank-you redirect the POS listens for.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		if ( ! $order->is_paid() ) {
			try {
				$settings = new Settings();
				$service  = new PointPaymentService( new MercadoPagoClient( $settings->access_token() ), $settings );
				$result   = $service->poll_order( $order );
				if ( 'paid' === ( $result['status'] ?? '' ) ) {
					$refreshed = wc_get_order( $order_id );
					if ( $refreshed ) {
						$order = $refreshed;
					}
				}
			} catch ( \Exception $e ) {
				Logger::log( 'Mercado Pago Terminal process_payment could not verify payment: ' . $e->getMessage(), array(), 'error' );
			}
		}
		if ( $order->is_paid() ) {
			return array( 'result' => 'success', 'redirect' => AjaxHandler::order_return_url( $order ) );
		}
		wc_add_notice( __( 'This order has not been paid yet. Start the payment above and wait for Mercado Pago to confirm — the order finishes on its own. If the customer has already paid, give it a few seconds and try again.', 'mercadopago-terminal-for-woocommerce' ), 'notice' );
		return array( 'result' => 'failure' );
	}
}
