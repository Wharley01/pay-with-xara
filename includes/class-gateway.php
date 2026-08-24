<?php

/**
 * WooCommerce payment gateway.
 *
 * @package PayWithXara
 */

defined('ABSPATH') || exit;

/**
 * Pay with Xara gateway.
 */
class Xara_WC_Gateway extends WC_Payment_Gateway
{

	const META_REFERENCE  = '_xara_invoice_reference';
	const META_DELIVERIES = '_xara_webhook_deliveries';

	/**
	 * @var string
	 */
	public $api_token = '';

	/**
	 * @var string
	 */
	public $webhook_secret = '';

	/**
	 * @var string
	 */
	public $api_base_url = '';

	/**
	 * @var string
	 */
	public $instructions = '';

	/**
	 * @var bool
	 */
	public $debug = false;

	public function __construct()
	{
		$this->id                 = 'xara';
		$this->method_title       = __('Pay with Xara', 'pay-with-xara');
		$this->method_description = __('Send a Xara invoice to the customer\'s WhatsApp. They can pay with Xara wallet, bank transfer, and other Xara methods.', 'pay-with-xara');
		$this->has_fields         = true;
		$this->supports           = array('products');
		$this->icon               = XARA_WC_PLUGIN_URL . 'assets/images/icon.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled        = $this->get_option('enabled', 'no');
		$this->title          = $this->get_option('title', __('Pay with Xara', 'pay-with-xara'));
		$this->description    = $this->get_option('description');
		$this->instructions   = $this->get_option('instructions');
		$this->api_token      = $this->get_option('api_token');
		$this->webhook_secret = $this->get_option('webhook_secret');
		$this->api_base_url   = $this->get_option('api_base_url', Xara_WC_API_Client::DEFAULT_BASE_URL);
		$this->debug          = 'yes' === $this->get_option('debug', 'no');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
		add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
		add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'admin_order_reference'));
		add_filter('woocommerce_billing_fields', array($this, 'require_billing_phone'));
		add_filter('option_woocommerce_checkout_phone_field', array($this, 'force_checkout_phone_required'));
		add_filter('default_option_woocommerce_checkout_phone_field', array($this, 'force_checkout_phone_required'));
	}

	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __('Enable/Disable', 'pay-with-xara'),
				'type'    => 'checkbox',
				'label'   => __('Enable Pay with Xara', 'pay-with-xara'),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __('Title', 'pay-with-xara'),
				'type'        => 'text',
				'description' => __('Shown to customers at checkout.', 'pay-with-xara'),
				'default'     => __('Pay with Xara', 'pay-with-xara'),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __('Description', 'pay-with-xara'),
				'type'        => 'textarea',
				'description' => __('Shown under the payment method at checkout.', 'pay-with-xara'),
				'default'     => __('An invoice will be sent to your WhatsApp. Pay there with Xara wallet, bank transfer, or other supported methods.', 'pay-with-xara'),
				'desc_tip'    => true,
			),
			'instructions'    => array(
				'title'       => __('Thank you instructions', 'pay-with-xara'),
				'type'        => 'textarea',
				'description' => __('Shown on the order received page and in the customer email.', 'pay-with-xara'),
				'default'     => __('Check WhatsApp for your Xara invoice and complete payment to confirm this order.', 'pay-with-xara'),
				'desc_tip'    => true,
			),
			'api_token'       => array(
				'title'             => __('API token', 'pay-with-xara'),
				'type'              => 'password',
				'description'       => __('Generate this from the Xara business dashboard under Developers. Paste the token only — do not include the word Bearer.', 'pay-with-xara'),
				'default'           => '',
				'custom_attributes' => array(
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
				),
			),
			'webhook_secret'  => array(
				'title'             => __('Webhook secret', 'pay-with-xara'),
				'type'              => 'password',
				'description'       => __('The same secret you configure on the Xara webhook. Used to verify X-Xara-Signature.', 'pay-with-xara'),
				'default'           => '',
				'custom_attributes' => array(
					'autocomplete' => 'off',
				),
			),
			'api_base_url'    => array(
				'title'       => __('API base URL', 'pay-with-xara'),
				'type'        => 'text',
				'description' => __('Leave as the default unless Xara support gives you a staging URL.', 'pay-with-xara'),
				'default'     => Xara_WC_API_Client::DEFAULT_BASE_URL,
			),
			'webhook_url'     => array(
				'title'       => __('Webhook URL', 'pay-with-xara'),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: webhook URL */
					__('Register this URL in the Xara Developers dashboard for <code>invoice.paid</code> and <code>invoice.payment_received</code>. Saving these settings will also try to register it automatically.<br><code>%s</code>', 'pay-with-xara'),
					esc_html(Xara_WC_Webhook_Controller::get_url())
				),
			),
			'debug'           => array(
				'title'       => __('Debug log', 'pay-with-xara'),
				'type'        => 'checkbox',
				'label'       => __('Log Xara API requests in WooCommerce > Status > Logs', 'pay-with-xara'),
				'default'     => 'no',
			),
		);
	}

	/**
	 * @return bool
	 */
	public function is_available()
	{
		if (! parent::is_available()) {
			return false;
		}

		if (empty($this->api_token)) {
			return false;
		}

		if (function_exists('get_woocommerce_currency') && 'NGN' !== get_woocommerce_currency()) {
			return false;
		}

		return true;
	}

	/**
	 * WhatsApp delivery needs a phone number.
	 *
	 * @param array $fields
	 * @return array
	 */
	public function require_billing_phone($fields)
	{
		if ('yes' === $this->enabled && isset($fields['billing_phone'])) {
			$fields['billing_phone']['required'] = true;
		}

		return $fields;
	}

	/**
	 * Checkout Blocks reads this option instead of woocommerce_billing_fields.
	 *
	 * @param string $value
	 * @return string
	 */
	public function force_checkout_phone_required($value)
	{
		return 'yes' === $this->enabled ? 'required' : $value;
	}

	public function payment_fields()
	{
		if ($this->description) {
			echo wp_kses_post(wpautop(wptexturize($this->description)));
		}

		woocommerce_form_field(
			'xara_phone',
			array(
				'type'        => 'tel',
				'label'       => __('WhatsApp phone number', 'pay-with-xara'),
				'required'    => true,
				'class'       => array('form-row-wide'),
				'placeholder' => '0801 234 5678',
			),
			$this->get_posted_phone()
		);
	}

	/**
	 * @return bool
	 */
	public function validate_fields()
	{
		$phone = $this->get_posted_phone();
		if ($phone) {
			return true;
		}

		// Checkout Blocks does not populate $_POST['billing_phone']; the order
		// phone is checked later in process_payment.
		if (isset($_POST['billing_phone']) || isset($_POST['xara_phone'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			wc_add_notice(__('A WhatsApp phone number is required to pay with Xara.', 'pay-with-xara'), 'error');
			return false;
		}

		return true;
	}

	/**
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) {
			wc_add_notice(__('Unable to find this order.', 'pay-with-xara'), 'error');
			return array('result' => 'failure');
		}

		$phone = $this->resolve_phone($order);
		if (! $phone) {
			wc_add_notice(__('A WhatsApp phone number is required to pay with Xara.', 'pay-with-xara'), 'error');
			return array('result' => 'failure');
		}

		if (! $order->get_billing_phone()) {
			$order->set_billing_phone($phone);
		}

		$existing_reference = $order->get_meta(self::META_REFERENCE);
		$client             = $this->get_client();
		$invoice_payload = array(
			'phone'               => $phone,
			'customer_name'       => trim($order->get_formatted_billing_full_name()),
			'note'                => sprintf('WooCommerce order #%s', $order->get_order_number()),
			'external_reference'  => 'woocommerce:' . $order->get_id(),
			'items'               => $this->build_invoice_items($order),
		);

		$delivery_address = $this->build_delivery_address($order);
		if ($delivery_address) {
			$invoice_payload['delivery_address'] = $delivery_address;
		}

		$result = $client->create_invoice($invoice_payload);

		if (empty($result['success']) || empty($result['data']['reference'])) {
			$message = ! empty($result['message'])
				? $result['message']
				: __('Unable to create the Xara invoice. Please try again.', 'pay-with-xara');
			$this->log('Invoice create failed for order ' . $order_id . ': ' . $message);
			wc_add_notice($message, 'error');
			return array('result' => 'failure');
		}

		$data      = $result['data'];
		$reference = (string) $data['reference'];
		$status    = isset($data['status']) ? (string) $data['status'] : 'initiated';

		$order->update_meta_data(self::META_REFERENCE, $reference);
		$order->set_transaction_id($reference);

		if (in_array($status, array('paid', 'completed'), true)) {
			$order->payment_complete($reference);
			$order->add_order_note(
				sprintf(
					/* translators: %s: invoice reference */
					__('Xara invoice %s was already paid.', 'pay-with-xara'),
					$reference
				)
			);
			$order->save();
			if (WC()->cart) {
				WC()->cart->empty_cart();
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url($order),
			);
		}

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: invoice reference */
				__('Awaiting Xara payment. Invoice %s was sent to the customer\'s WhatsApp.', 'pay-with-xara'),
				$reference
			)
		);

		if ($existing_reference && $existing_reference !== $reference) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: old reference, 2: new reference */
					__('Xara invoice reference changed from %1$s to %2$s.', 'pay-with-xara'),
					$existing_reference,
					$reference
				)
			);
		}

		$order->save();
		if (WC()->cart) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url($order),
		);
	}

	/**
	 * Keep the stored token when the password field is submitted empty.
	 *
	 * @param string $key
	 * @param string $value
	 * @return string
	 */
	public function validate_api_token_field($key, $value)
	{
		$value = Xara_WC_API_Client::normalize_token($this->validate_password_field($key, $value));
		return '' === $value ? (string) $this->get_option($key) : $value;
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @return string
	 */
	public function validate_webhook_secret_field($key, $value)
	{
		$value = $this->validate_password_field($key, $value);
		return '' === $value ? (string) $this->get_option($key) : $value;
	}

	/**
	 * @return bool
	 */
	public function process_admin_options()
	{
		$saved = parent::process_admin_options();

		$this->api_token      = $this->get_option('api_token');
		$this->webhook_secret = $this->get_option('webhook_secret');
		$this->api_base_url   = $this->get_option('api_base_url', Xara_WC_API_Client::DEFAULT_BASE_URL);
		$this->debug          = 'yes' === $this->get_option('debug', 'no');

		if (! $this->api_token) {
			return $saved;
		}

		$probe = $this->get_client()->get_webhooks();
		if (empty($probe['success'])) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message */
					__('Xara could not authenticate this API token: %s', 'pay-with-xara'),
					$probe['message'] ?? __('Unknown error', 'pay-with-xara')
				)
			);
			return $saved;
		}

		$webhook_url = Xara_WC_Webhook_Controller::get_url();
		if ($this->is_local_webhook_url($webhook_url)) {
			WC_Admin_Settings::add_message(
				__('API token is valid. This store URL is local, so Xara cannot deliver webhooks here. For paid-order updates, expose the site with a tunnel and register that public webhook URL in the Xara dashboard.', 'pay-with-xara')
			);
			return $saved;
		}

		if (! $this->webhook_secret) {
			WC_Admin_Settings::add_message(__('API token is valid. Add a webhook secret to register the webhook automatically.', 'pay-with-xara'));
			return $saved;
		}

		$result = $this->get_client()->ensure_webhook($webhook_url, $this->webhook_secret);
		if (empty($result['success'])) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error message */
					__('Xara webhook could not be registered automatically: %s. Register the webhook URL manually in the Xara dashboard.', 'pay-with-xara'),
					$result['message'] ?? __('Unknown error', 'pay-with-xara')
				)
			);
		} elseif (! empty($result['created'])) {
			WC_Admin_Settings::add_message(__('Xara webhook registered successfully.', 'pay-with-xara'));
		}

		return $saved;
	}

	/**
	 * @param string $url
	 * @return bool
	 */
	private function is_local_webhook_url($url)
	{
		$host = wp_parse_url($url, PHP_URL_HOST);
		if (! $host) {
			return true;
		}

		$host = strtolower($host);
		return in_array($host, array('localhost', '127.0.0.1', '::1'), true)
			|| substr($host, -6) === '.local'
			|| substr($host, -5) === '.test';
	}

	/**
	 * @param int $order_id
	 */
	public function thankyou_page($order_id)
	{
		if ($this->instructions) {
			echo wp_kses_post(wpautop(wptexturize($this->instructions)));
		}
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false)
	{
		if ($sent_to_admin || ! $this->instructions || $this->id !== $order->get_payment_method()) {
			return;
		}

		if ($order->has_status(array('on-hold', 'pending'))) {
			echo wp_kses_post(wpautop(wptexturize($this->instructions)));
		}
	}

	/**
	 * @param WC_Order $order
	 */
	public function admin_order_reference($order)
	{
		$reference = $order->get_meta(self::META_REFERENCE);
		if (! $reference) {
			return;
		}

		echo '<p><strong>' . esc_html__('Xara invoice', 'pay-with-xara') . ':</strong> ' . esc_html($reference) . '</p>';
	}

	/**
	 * @param string $raw_body
	 * @param string $signature
	 * @return bool
	 */
	public function verify_webhook_signature($raw_body, $signature)
	{
		if (! $this->webhook_secret || ! $signature) {
			return false;
		}

		$expected = base64_encode(hash_hmac('sha256', $raw_body, $this->webhook_secret, true));
		return hash_equals($expected, $signature);
	}

	/**
	 * @param WC_Order $order
	 * @param float    $amount
	 * @return bool
	 */
	public function amounts_match(WC_Order $order, $amount)
	{
		$expected = (float) $order->get_total();
		return abs($expected - (float) $amount) < 1;
	}

	/**
	 * @param string $message
	 */
	public function log($message)
	{
		if (! $this->debug || ! function_exists('wc_get_logger')) {
			return;
		}

		wc_get_logger()->debug($message, array('source' => 'xara'));
	}

	/**
	 * @return Xara_WC_API_Client
	 */
	private function get_client()
	{
		return new Xara_WC_API_Client($this->api_token, $this->api_base_url, $this->debug);
	}

	/**
	 * @param WC_Order|null $order
	 * @return string
	 */
	private function resolve_phone($order = null)
	{
		$candidates = array(
			$this->get_posted_value('xara_phone'),
			$this->get_posted_value('billing_phone'),
		);

		if ($order) {
			$candidates[] = $order->get_billing_phone();
			$candidates[] = $order->get_shipping_phone();
		}

		foreach ($candidates as $phone) {
			if ($phone) {
				return $phone;
			}
		}

		return '';
	}

	/**
	 * @return string
	 */
	private function get_posted_phone()
	{
		return $this->resolve_phone();
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private function get_posted_value($key)
	{
		if (! isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		return wc_clean(wp_unslash($_POST[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Shipping address first, then billing, for the Xara invoice.
	 *
	 * @param WC_Order $order
	 * @return array{address:string,city?:string,state?:string,label:string}|null
	 */
	private function build_delivery_address(WC_Order $order)
	{
		$use_shipping = (bool) $order->get_shipping_address_1();
		$line_1       = $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1();
		$line_2       = $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2();
		$city         = $use_shipping ? $order->get_shipping_city() : $order->get_billing_city();
		$state_code   = $use_shipping ? $order->get_shipping_state() : $order->get_billing_state();
		$postcode     = $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$country      = $use_shipping ? $order->get_shipping_country() : $order->get_billing_country();

		$parts = array_filter(array($line_1, $line_2, $postcode));
		if (empty($parts)) {
			return null;
		}

		$state = $state_code;
		if ($state_code && $country && function_exists('WC') && WC()->countries) {
			$states = WC()->countries->get_states($country);
			if (is_array($states) && ! empty($states[$state_code])) {
				$state = $states[$state_code];
			}
		}

		$address = array(
			'address' => implode(', ', $parts),
			'label'   => $use_shipping ? 'Shipping' : 'Billing',
		);

		if ($city) {
			$address['city'] = $city;
		}
		if ($state) {
			$address['state'] = $state;
		}

		return $address;
	}

	/**
	 * Build ad-hoc invoice lines that sum to the WooCommerce order total.
	 *
	 * @param WC_Order $order
	 * @return array<int,array{name:string,quantity:int,unit_price:float}>
	 */
	private function build_invoice_items(WC_Order $order)
	{
		$items = array();

		foreach ($order->get_items() as $item) {
			$quantity = max(1, (int) $item->get_quantity());
			$total    = (float) $item->get_total() + (float) $item->get_total_tax();
			$items[]  = array(
				'name'       => $item->get_name(),
				'quantity'   => $quantity,
				'unit_price' => round($total / $quantity, 2),
			);
		}

		foreach ($order->get_shipping_methods() as $shipping) {
			$total = (float) $shipping->get_total() + (float) $shipping->get_total_tax();
			if ($total <= 0) {
				continue;
			}
			$items[] = array(
				'name'       => sprintf(
					/* translators: %s: shipping method name */
					__('Shipping — %s', 'pay-with-xara'),
					$shipping->get_name()
				),
				'quantity'   => 1,
				'unit_price' => round($total, 2),
			);
		}

		foreach ($order->get_fees() as $fee) {
			$total = (float) $fee->get_total() + (float) $fee->get_total_tax();
			if (0.0 === $total) {
				continue;
			}
			$items[] = array(
				'name'       => $fee->get_name() ? $fee->get_name() : __('Fee', 'pay-with-xara'),
				'quantity'   => 1,
				'unit_price' => round($total, 2),
			);
		}

		$sum = 0.0;
		foreach ($items as $item) {
			$sum += $item['quantity'] * $item['unit_price'];
		}

		$order_total = round((float) $order->get_total(), 2);
		$difference  = round($order_total - $sum, 2);

		if (empty($items) || abs($difference) >= 0.01) {
			return array(
				array(
					'name'       => sprintf(
						/* translators: %s: order number */
						__('WooCommerce order #%s', 'pay-with-xara'),
						$order->get_order_number()
					),
					'quantity'   => 1,
					'unit_price' => $order_total,
				),
			);
		}

		return $items;
	}

	public function admin_options()
	{
		echo '<p><img src="' . esc_url(XARA_WC_PLUGIN_URL . 'assets/images/logo.svg') . '" alt="' . esc_attr__('Xara', 'pay-with-xara') . '" width="96" height="27" style="height:27px;width:auto;" /></p>';

		if (function_exists('get_woocommerce_currency') && 'NGN' !== get_woocommerce_currency()) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__('Pay with Xara is only available when the store currency is NGN.', 'pay-with-xara');
			echo '</p></div>';
		}

		parent::admin_options();
	}
}
