<?php
/**
 * Universal Commerce Protocol (UCP) Handler for Avvance
 * Bridges AI Agents with Avvance Financing
 */

if (!defined('ABSPATH')) {
    exit;
}

class Avvance_UCP_Handler {

    const UCP_NAMESPACE = 'ucp/v1';

    public static function init() {
        // 1. Register the .well-known/ucp manifest
        add_action('init', [__CLASS__, 'register_ucp_manifest_rewrite']);
        add_action('template_redirect', [__CLASS__, 'serve_ucp_manifest']);

        // 2. Register REST API endpoints for the Agent
        add_action('rest_api_init', [__CLASS__, 'register_ucp_routes']);
        // --- ADD THIS NEW BLOCK ---
        // Force-allow anonymous access to UCP endpoints
        add_filter('rest_authentication_errors', function ($result) {
            // Check if the current URL is for our Agent
            if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/ucp/v1/') !== false) {
                return true; // Force authorization "OK"
            }
            return $result;
        }, 99); // Priority 99 to override other plugins
        // --------------------------
    }

    /**
     * 1. MANIFEST: Tells Google "I speak UCP"
     * URL: https://yourstore.com/.well-known/ucp
     */
    public static function register_ucp_manifest_rewrite() {
        add_rewrite_rule('^ucp-manifest/?$', 'index.php?ucp_manifest=1', 'top');
        
        add_filter('query_vars', function($vars) {
            $vars[] = 'ucp_manifest';
            return $vars;
        });
    }

    public static function serve_ucp_manifest() {
        if (get_query_var('ucp_manifest')) {
            $gateway = avvance_get_gateway();
            if (!$gateway || $gateway->enabled !== 'yes') {
                status_header(404);
                exit;
            }

            $manifest = [
                "ucp_version" => "1.0",
                "merchant_name" => get_bloginfo('name'),
                "capabilities" => [
                    [
                        "type" => "checkout", // The standard UCP capability
                        "id" => "avvance-financing",
                        "uri" => get_rest_url(null, self::UCP_NAMESPACE . '/checkout'),
                        "supported_payment_methods" => ["avvance"],
                        "handoff_mode" => "redirect" // Critical: Tells AI we need a redirect
                    ]
                ],
                "support_contact" => get_option('admin_email')
            ];

            header('Content-Type: application/json');
            echo json_encode($manifest, JSON_PRETTY_PRINT);
            exit;
        }
    }

    /**
     * 2. API ROUTES: The "Brain" that listens to the Agent
     */
    public static function register_ucp_routes() {
        avvance_log('UCP: Registering REST routes');

        // 1. Create Session Route
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions', [
            'methods' => ['POST', 'GET'],
            'callback' => [__CLASS__, 'create_session'],
            'permission_callback' => function ($request) {
                avvance_log('UCP: Permission check for /checkout/sessions');
                return true;
            }
        ]);

        // 2. Finalize Route
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions/(?P<id>[\w-]+)/finalize', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'finalize_session'],
            'permission_callback' => function ($request) {
                avvance_log('UCP: Permission check for /finalize');
                return true;
            }
        ]);
    }

    /**
     * Agent Intent: "I want to buy these items"
     * Action: Create a pending WC Order to get an ID/Total
     */
    public static function create_session($request) {
        $params = $request->get_json_params();
        $items = $params['items'] ?? [];

        try {
            // 1. Validate items array
            if (empty($items)) {
                return new WP_Error('ucp_error', 'No items provided', ['status' => 400]);
            }

            // 2. Create a minimal order programmatically
            $order = wc_create_order();
            $added_products = 0;

            // 3. Add products with validation
            foreach ($items as $item) {
                if (empty($item['id'])) {
                    continue;
                }

                // Agent sends "sku" or "product_id"
                $product_id = wc_get_product_id_by_sku($item['id']) ?: intval($item['id']);
                $product = wc_get_product($product_id);

                // Validate product exists and is purchasable
                if (!$product || !$product->is_purchasable()) {
                    avvance_log("UCP: Product not found or not purchasable: {$item['id']}", 'warning');
                    continue;
                }

                $quantity = max(1, intval($item['quantity'] ?? 1));
                $order->add_product($product, $quantity);
                $added_products++;
            }

            // 4. Ensure at least one product was added
            if ($added_products === 0) {
                $order->delete(true);
                return new WP_Error('ucp_error', 'No valid products found', ['status' => 400]);
            }

            // 5. Calculate totals and save
            $order->calculate_totals();
            $order->set_payment_method('avvance');
            $order->update_meta_data('_avvance_ucp_order', 'yes');
            $order->add_order_note(__('Order created via UCP (AI Agent)', 'avvance-for-woocommerce'));
            $order->save();

            avvance_log("UCP: Session created - Order #{$order->get_id()} with {$added_products} products");

            return [
                "session_id" => (string) $order->get_id(),
                "total" => [
                    "amount" => $order->get_total(),
                    "currency" => $order->get_currency()
                ],
                "status" => "active"
            ];

        } catch (Exception $e) {
            avvance_log('UCP: Session creation failed - ' . $e->getMessage(), 'error');
            return new WP_Error('ucp_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Agent Intent: "The user said yes, generate the payment link."
     * Action: Call Avvance API -> Return Handoff URL
     */
    public static function finalize_session($request) {
        $order_id = $request->get_param('id');
        $params = $request->get_json_params();

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', ['status' => 404]);
        }

        // 1. Update Customer Data from Agent (if provided)
        $has_placeholder_address = false;
        if (!empty($params['customer'])) {
            $c = $params['customer'];
            $order->set_billing_first_name(sanitize_text_field($c['first_name'] ?? 'Guest'));
            $order->set_billing_last_name(sanitize_text_field($c['last_name'] ?? 'User'));
            $order->set_billing_email(sanitize_email($c['email'] ?? ''));
            $order->set_billing_phone(sanitize_text_field($c['phone'] ?? ''));

            // Handle address - use provided or set placeholder
            if (!empty($c['address'])) {
                $addr = $c['address'];
                $order->set_billing_address_1(sanitize_text_field($addr['line1'] ?? ''));
                $order->set_billing_address_2(sanitize_text_field($addr['line2'] ?? ''));
                $order->set_billing_city(sanitize_text_field($addr['city'] ?? ''));
                $order->set_billing_state(sanitize_text_field($addr['state'] ?? ''));
                $order->set_billing_postcode(sanitize_text_field($addr['postal_code'] ?? ''));
                $order->set_billing_country(sanitize_text_field($addr['country'] ?? 'US'));
            } else {
                // Set placeholder address (Avvance requires address for API call)
                // User will provide real address during Avvance application
                $order->set_billing_address_1('Address provided during financing');
                $order->set_billing_city('TBD');
                $order->set_billing_state('MN');
                $order->set_billing_postcode('00000');
                $order->set_billing_country('US');
                $has_placeholder_address = true;
            }
        }

        // Flag order if using placeholder address
        if ($has_placeholder_address) {
            $order->update_meta_data('_avvance_placeholder_address', 'yes');
            $order->add_order_note(__('Note: Order has placeholder address. Real address will be collected during Avvance application.', 'avvance-for-woocommerce'));
        }

        $order->save();

        // 2. Call Avvance API
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            return new WP_Error('avvance_error', 'Avvance gateway not configured', ['status' => 500]);
        }

        $api_settings = [
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $gateway->get_option('environment')
        ];

        $api_client = new Avvance_API_Client($api_settings);
        $response = $api_client->create_financing_request($order);

        if (is_wp_error($response)) {
            avvance_log('UCP: Financing request failed for order #' . $order_id . ': ' . $response->get_error_message(), 'error');
            return new WP_Error('avvance_error', $response->get_error_message(), ['status' => 400]);
        }

        // 3. Store response data on order (same as normal checkout flow)
        // This is critical for webhook updates to work!
        $order->update_meta_data('_avvance_application_guid', $response['applicationGUID']);
        $order->update_meta_data('_avvance_partner_session_id', $response['partnerSessionId']);
        $order->update_meta_data('_avvance_consumer_url', $response['consumerOnboardingURL']);
        $order->update_meta_data('_avvance_url_created_at', time());
        $order->add_order_note(sprintf(
            __('Avvance application created via UCP. Application ID: %s', 'avvance-for-woocommerce'),
            $response['applicationGUID']
        ));
        $order->save();

        avvance_log('UCP: Order #' . $order_id . ' finalized. Application GUID: ' . $response['applicationGUID']);

        // 4. Return the Handoff URL to the Agent
        return [
            "session_id" => (string) $order_id,
            "status" => "action_required",
            "next_action" => [
                "type" => "redirect",
                "url" => $response['consumerOnboardingURL'],
                "description" => "Please complete your financing application."
            ]
        ];
    }
}