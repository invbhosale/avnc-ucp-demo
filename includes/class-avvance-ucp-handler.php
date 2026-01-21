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
        error_log('UCP DEBUG: Registering REST routes...'); // Verify code is loading

        // 1. Create Session Route
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions', [
            'methods' => ['POST', 'GET'],
            'callback' => [__CLASS__, 'create_session'],
            'permission_callback' => function ($request) {
                error_log('UCP DEBUG: Checking permission for /checkout/sessions');
                
                // Log if user is logged in or not
                $current_user = wp_get_current_user()->ID;
                error_log('UCP DEBUG: Current User ID: ' . $current_user);

                // FORCE TRUE for debugging
                return true;
            }
        ]);

        // 2. Finalize Route
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions/(?P<id>[\w-]+)/finalize', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'finalize_session'],
            'permission_callback' => function ($request) {
                error_log('UCP DEBUG: Checking permission for /finalize');
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
            // 1. Create a minimal order programmatically
            $order = wc_create_order();
            
            // 2. Add products
            foreach ($items as $item) {
                // Agent sends "sku" or "product_id"
                $product_id = wc_get_product_id_by_sku($item['id']) ?: $item['id'];
                if ($product_id) {
                    $order->add_product(wc_get_product($product_id), $item['quantity'] ?? 1);
                }
            }

            // 3. Calculate totals
            $order->calculate_totals();
            $order->set_payment_method('avvance');
            $order->save();

            return [
                "session_id" => (string) $order->get_id(), // We use Order ID as Session ID
                "total" => [
                    "amount" => $order->get_total(),
                    "currency" => "USD"
                ],
                "status" => "active"
            ];

        } catch (Exception $e) {
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
        if (!empty($params['customer'])) {
            $c = $params['customer'];
            $order->set_billing_first_name($c['first_name'] ?? 'Guest');
            $order->set_billing_last_name($c['last_name'] ?? 'User');
            $order->set_billing_email($c['email'] ?? '');
            $order->set_billing_phone($c['phone'] ?? '9009009000'); // Test Default
            
            // Set dummy address if missing (Avvance requires it)
            if (empty($c['address'])) {
                $order->set_billing_address_1('123 Test St');
                $order->set_billing_city('Minneapolis');
                $order->set_billing_state('MN');
                $order->set_billing_postcode('55402');
                $order->set_billing_country('US');
            }
            $order->save();
        }

        // 2. Call Avvance API (Re-using your existing logic!)
        $gateway = avvance_get_gateway();
        $api_settings = [
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $gateway->get_option('environment')
        ];
        
        $api_client = new Avvance_API_Client($api_settings);
        
        // This method exists in your uploaded file!
        $response = $api_client->create_financing_request($order);

        if (is_wp_error($response)) {
            return new WP_Error('avvance_error', $response->get_error_message(), ['status' => 400]);
        }

        // 3. Return the Magic Handoff URL to the Agent
        return [
            "session_id" => (string) $order_id,
            "status" => "action_required", // Tells Agent to stop and show UI
            "next_action" => [
                "type" => "redirect",
                "url" => $response['consumerOnboardingURL'], // The URL for U.S. Bank
                "description" => "Please complete your financing application."
            ]
        ];
    }
}