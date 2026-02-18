<?php
/**
 * Avvance Blocks Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Avvance_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'avvance';
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_avvance_settings', array() );

		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways['avvance'] ) ? $gateways['avvance'] : null;
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

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

		wp_localize_script(
			'avvance-blocks',
			'avvanceBlocksData',
			array(
				'title'       => $this->gateway->title,
				'description' => $this->gateway->description,
				'icon'        => AVVANCE_PLUGIN_URL . 'assets/images/avvance-logo.svg',
			)
		);

		return array( 'avvance-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->description,
			'supports'    => $this->gateway->supports,
		);
	}
}
