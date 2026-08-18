<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class name follows WooCommerce convention
/**
 * Avvance Blocks Integration
 *
 * @package Avvance_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Avvance WooCommerce Blocks payment method integration.
 */
class Avvance_Blocks_Integration extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'avvance';

	/**
	 * Gateway instance.
	 *
	 * @var WC_Gateway_Avvance|null
	 */
	private $gateway;

	/**
	 * Initialize the payment method.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_avvance_settings', array() );

		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways['avvance'] ) ? $gateways['avvance'] : null;
	}

	/**
	 * Check if the payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	/**
	 * Get payment method script handles.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path = AVVANCE_PLUGIN_PATH . 'blocks/build/index.js';
		$script_url  = AVVANCE_PLUGIN_URL . 'blocks/build/index.js';
		$asset_path  = AVVANCE_PLUGIN_PATH . 'blocks/build/index.asset.php';

		$dependencies = array();
		$version      = AVVANCE_VERSION;

		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$dependencies = $asset['dependencies'] ?? array();
			$version      = $asset['version'] ?? $version;
		}

		wp_register_script(
			'avvance-blocks',
			$script_url,
			$dependencies,
			$version,
			true
		);

		$theme = strtolower( (string) $this->gateway->get_option( 'widget_theme', 'light' ) );
		if ( ! in_array( $theme, array( 'light', 'dark' ), true ) ) {
			$theme = 'light';
		}
		$icon_light = AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg';

		wp_localize_script(
			'avvance-blocks',
			'avvanceBlocksData',
			array(
				'title'       => $this->gateway->title,
				'description' => $this->gateway->description,
				'icon'        => $icon_light,
				'theme'       => $theme,
			)
		);

		return array( 'avvance-blocks' );
	}

	/**
	 * Get payment method data for the Blocks checkout.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->description,
			'supports'    => $this->gateway->supports,
		);
	}
}
