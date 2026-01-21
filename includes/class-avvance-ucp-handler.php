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
        
        // 3. Force-allow anonymous access to UCP endpoints
        add_filter('rest_authentication_errors', function ($result) {
            if (!empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/ucp/v1/') !== false) {
                return true; // Force authorization "OK"
            }
            return $result;
        }, 99);
    }

    /**
     * MANIFEST: Tells Google "I speak UCP"
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

            // Check if pre-qualification is available (hashed_merchant_id configured)
            $has_prequal = !empty($gateway->get_option('hashed_merchant_id'));

            $capabilities = [
                [
                    "type" => "checkout",
                    "id" => "avvance-financing",
                    "uri" => get_rest_url(null, self::UCP_NAMESPACE . '/checkout'),
                    "supported_payment_methods" => ["avvance"],
                    "handoff_mode" => "redirect"
                ],
                [
                    "type" => "discovery",
                    "id" => "product-search",
                    "uri" => get_rest_url(null, self::UCP_NAMESPACE . '/products')
                ]
            ];

            // Add pre-qualification capability if configured
            if ($has_prequal) {
                $capabilities[] = [
                    "type" => "pre-qualification",
                    "id" => "avvance-prequalify",
                    "uri" => get_rest_url(null, self::UCP_NAMESPACE . '/pre-qualify'),
                    "handoff_mode" => "redirect"
                ];
            }

            $manifest = [
                "ucp_version" => "1.0",
                "merchant_name" => get_bloginfo('name'),
                "capabilities" => $capabilities,
                "support_contact" => get_option('admin_email')
            ];

            header('Content-Type: application/json');
            echo json_encode($manifest, JSON_PRETTY_PRINT);
            exit;
        }
    }

    /**
     * API ROUTES REGISTRATION
     */
    public static function register_ucp_routes() {
        // 1. Product Search (NEW)
        register_rest_route(self::UCP_NAMESPACE, '/products', [
            'methods' => ['GET'],
            'callback' => [__CLASS__, 'search_products'],
            'permission_callback' => '__return_true'
        ]);

        // 2. Create Session (Start Cart)
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'create_session'],
            'permission_callback' => '__return_true'
        ]);

        // 3. Update Session (Calculate Tax/Shipping) (NEW)
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions/(?P<id>[\w-]+)', [
            'methods' => ['PUT', 'GET'], // GET for recovering state
            'callback' => [__CLASS__, 'handle_session_update_or_get'],
            'permission_callback' => '__return_true'
        ]);

        // 4. Finalize (Get Avvance Link)
        register_rest_route(self::UCP_NAMESPACE, '/checkout/sessions/(?P<id>[\w-]+)/finalize', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'finalize_session'],
            'permission_callback' => '__return_true'
        ]);

        // 5. Order Status (Post-Purchase) (NEW)
        register_rest_route(self::UCP_NAMESPACE, '/orders/(?P<id>[\w-]+)', [
            'methods' => ['GET'],
            'callback' => [__CLASS__, 'get_order_status'],
            'permission_callback' => '__return_true'
        ]);

        // 6. Pre-Qualification - Create
        register_rest_route(self::UCP_NAMESPACE, '/pre-qualify', [
            'methods' => ['POST'],
            'callback' => [__CLASS__, 'pre_qualify_user'],
            'permission_callback' => '__return_true'
        ]);

        // 7. Pre-Qualification - Check Status (by request_id)
        register_rest_route(self::UCP_NAMESPACE, '/pre-qualify/(?P<request_id>[\w-]+)', [
            'methods' => ['GET'],
            'callback' => [__CLASS__, 'get_prequalification_status'],
            'permission_callback' => '__return_true'
        ]);
    }

    /* -------------------------------------------------------------------------
     * NEW: PRODUCT DISCOVERY
     * ------------------------------------------------------------------------- */
    
    public static function search_products($request) {
        $query = $request->get_param('q');
        $limit = $request->get_param('limit');
        $limit = $limit ? min(max(1, intval($limit)), 20) : 5; // Default 5, max 20

        $args = [
            'status' => 'publish',
            'limit' => $limit,
            'orderby' => 'relevance'
        ];

        if (!empty($query)) {
            $args['s'] = $query;
        } else {
            $args['featured'] = true;
        }

        $products = wc_get_products($args);
        $results = [];

        foreach ($products as $product) {
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_url($image_id) : wc_placeholder_img_src();

            $results[] = [
                'id' => (string) $product->get_id(),
                'name' => $product->get_name(),
                'price' => (float) $product->get_price(),
                'currency' => get_woocommerce_currency(),
                'image' => $image_url,
                'desc' => strip_tags($product->get_short_description()) ?: $product->get_name(),
                'link' => $product->get_permalink()
            ];
        }

        return $results;
    }

    /* -------------------------------------------------------------------------
     * SESSION MANAGEMENT
     * ------------------------------------------------------------------------- */

    public static function create_session($request) {
        $params = $request->get_json_params();
        $items = $params['items'] ?? [];

        try {
            if (empty($items)) {
                return new WP_Error('ucp_error', 'No items provided', ['status' => 400]);
            }

            $order = wc_create_order();
            $added_products = 0;

            foreach ($items as $item) {
                if (empty($item['id'])) continue;

                $product_id = wc_get_product_id_by_sku($item['id']) ?: intval($item['id']);
                $product = wc_get_product($product_id);

                if (!$product || !$product->is_purchasable()) {
                    continue;
                }

                $quantity = max(1, intval($item['quantity'] ?? 1));
                $order->add_product($product, $quantity);
                $added_products++;
            }

            if ($added_products === 0) {
                $order->delete(true);
                return new WP_Error('ucp_error', 'No valid products found', ['status' => 400]);
            }

            $order->calculate_totals();
            $order->set_payment_method('avvance');
            $order->update_meta_data('_avvance_ucp_order', 'yes');
            $order->save();

            return self::format_session_response($order);

        } catch (Exception $e) {
            return new WP_Error('ucp_error', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Handles PUT (Update Address/Calc Tax) and GET (Get State)
     */
    public static function handle_session_update_or_get($request) {
        $order_id = $request->get_param('id');
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('not_found', 'Session not found', ['status' => 404]);
        }

        // If PUT, update the order
        if ($request->get_method() === 'PUT') {
            $params = $request->get_json_params();
            
            // Update items quantity if provided
            if (!empty($params['items'])) {
                $order->remove_order_items(); // Clear and re-add for simplicity in POC
                foreach ($params['items'] as $item) {
                    $pid = wc_get_product_id_by_sku($item['id']) ?: intval($item['id']);
                    $product = wc_get_product($pid);
                    if ($product) {
                        $order->add_product($product, $item['quantity'] ?? 1);
                    }
                }
            }

            // Update Address for Tax Calculation
            if (!empty($params['customer']['address'])) {
                $addr = $params['customer']['address'];
                $address_args = [
                    'address_1' => $addr['line1'] ?? '',
                    'address_2' => $addr['line2'] ?? '',
                    'city'      => $addr['city'] ?? '',
                    'state'     => $addr['state'] ?? '',
                    'postcode'  => $addr['postal_code'] ?? '',
                    'country'   => $addr['country'] ?? 'US'
                ];
                $order->set_billing_address($address_args);
                $order->set_shipping_address($address_args);
            }

            $order->calculate_totals();
            $order->save();
        }

        return self::format_session_response($order);
    }

    /**
     * Helper to format standard UCP response with Tax/Shipping breakdown
     */
    private static function format_session_response($order) {
        return [
            "session_id" => (string) $order->get_id(),
            "total" => [
                "amount" => $order->get_total(),
                "currency" => $order->get_currency()
            ],
            "tax" => [
                "amount" => $order->get_total_tax(),
                "lines" => $order->get_taxes() // Detailed tax breakdown
            ],
            // In a real app, this would query WC Shipping Zones
            "shipping_options" => [
                [
                    "id" => "standard",
                    "label" => "Standard Shipping", 
                    "amount" => (float) $order->get_shipping_total()
                ]
            ],
            "status" => "active"
        ];
    }

    public static function finalize_session($request) {
        $order_id = $request->get_param('id');
        $params = $request->get_json_params();
        $order = wc_get_order($order_id);

        if (!$order) {
            return new WP_Error('invalid_order', 'Order not found', ['status' => 404]);
        }

        // Update Customer Data
        if (!empty($params['customer'])) {
            $c = $params['customer'];
            $order->set_billing_first_name(sanitize_text_field($c['first_name'] ?? 'Guest'));
            $order->set_billing_last_name(sanitize_text_field($c['last_name'] ?? 'User'));
            $order->set_billing_email(sanitize_email($c['email'] ?? ''));
            $order->set_billing_phone(sanitize_text_field($c['phone'] ?? ''));

            if (!empty($c['address'])) {
                $addr = $c['address'];
                $order->set_billing_address_1(sanitize_text_field($addr['line1'] ?? ''));
                $order->set_billing_address_2(sanitize_text_field($addr['line2'] ?? ''));
                $order->set_billing_city(sanitize_text_field($addr['city'] ?? ''));
                $order->set_billing_state(sanitize_text_field($addr['state'] ?? ''));
                $order->set_billing_postcode(sanitize_text_field($addr['postal_code'] ?? ''));
                $order->set_billing_country(sanitize_text_field($addr['country'] ?? 'US'));
            } else {
                // Fallback for demo
                $order->set_billing_address_1('Address provided during financing');
                $order->set_billing_city('TBD');
                $order->set_billing_state('MN');
                $order->set_billing_postcode('00000');
                $order->set_billing_country('US');
            }
        }
        $order->save();

        // Call Avvance API
        $gateway = avvance_get_gateway();
        if (!$gateway) {
            return new WP_Error('avvance_error', 'Gateway not configured', ['status' => 500]);
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
            return new WP_Error('avvance_error', $response->get_error_message(), ['status' => 400]);
        }

        // Save metadata for Webhooks
        $order->update_meta_data('_avvance_application_guid', $response['applicationGUID']);
        $order->update_meta_data('_avvance_partner_session_id', $response['partnerSessionId']);
        $order->update_meta_data('_avvance_consumer_url', $response['consumerOnboardingURL']);
        $order->save();

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

    /* -------------------------------------------------------------------------
     * NEW: POST-PURCHASE & UTILITIES
     * ------------------------------------------------------------------------- */

    public static function get_order_status($request) {
        $order = wc_get_order($request->get_param('id'));
        if (!$order) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        // Security: Only allow access to UCP-created orders
        if ($order->get_meta('_avvance_ucp_order') !== 'yes') {
            return new WP_Error('forbidden', 'Access denied', ['status' => 403]);
        }

        return [
            "id" => (string) $order->get_id(),
            "status" => $order->get_status(), // e.g., 'processing', 'completed'
            "is_paid" => $order->is_paid(),
            "tracking_number" => $order->get_meta('_tracking_number') ?: null,
            "items" => array_map(function($item) {
                return $item->get_name();
            }, $order->get_items())
        ];
    }

    /**
     * Create a pre-qualification request via Avvance API
     * Stores in database and returns onboarding URL for redirect
     */
    public static function pre_qualify_user($request) {
        $gateway = avvance_get_gateway();

        if (!$gateway) {
            return new WP_Error('unavailable', 'Gateway not configured', ['status' => 501]);
        }

        // Require the Pre-Approval API class
        if (!class_exists('Avvance_PreApproval_API')) {
            require_once AVVANCE_PLUGIN_PATH . 'includes/class-avvance-preapproval-api.php';
        }

        // Check for hashed merchant ID (required for pre-approval)
        $hashed_mid = $gateway->get_option('hashed_merchant_id');
        if (empty($hashed_mid)) {
            return new WP_Error('not_configured', 'Pre-qualification not configured for this merchant', ['status' => 501]);
        }

        $params = $request->get_json_params();

        // Generate a unique session ID for this UCP pre-qualification request
        $session_id = 'ucp_' . wp_generate_uuid4();

        // Generate a UCP fingerprint (since we don't have browser cookies in API calls)
        // Use agent_id if provided, otherwise generate one
        $agent_fingerprint = !empty($params['agent_id'])
            ? 'ucp_agent_' . sanitize_text_field($params['agent_id'])
            : 'ucp_' . wp_generate_uuid4();

        // Initialize Pre-Approval API
        $api = new Avvance_PreApproval_API([
            'client_key' => $gateway->get_option('client_key'),
            'client_secret' => $gateway->get_option('client_secret'),
            'merchant_id' => $gateway->get_option('merchant_id'),
            'environment' => $gateway->get_option('environment')
        ]);

        // Call Avvance Pre-Approval API
        $response = $api->create_preapproval($session_id, $hashed_mid);

        if (is_wp_error($response)) {
            return new WP_Error('preapproval_failed', $response->get_error_message(), ['status' => 400]);
        }

        // Store in database for webhook updates
        self::save_ucp_preapproval([
            'request_id' => $response['preApprovalRequestID'],
            'session_id' => $session_id,
            'browser_fingerprint' => $agent_fingerprint,
            'status' => 'pending'
        ]);

        // Return the onboarding URL for redirect (same pattern as checkout finalize)
        return [
            "request_id" => $response['preApprovalRequestID'],
            "status" => "action_required",
            "next_action" => [
                "type" => "redirect",
                "url" => $response['preApprovalOnboardingURL'],
                "description" => "Complete the pre-qualification application to check eligibility."
            ]
        ];
    }

    /**
     * Check pre-qualification status by request_id
     * Webhook updates the database, this endpoint reads the current state
     */
    public static function get_prequalification_status($request) {
        $request_id = $request->get_param('request_id');

        if (empty($request_id)) {
            return new WP_Error('missing_id', 'Request ID is required', ['status' => 400]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE request_id = %s",
            $request_id
        ), ARRAY_A);

        if (!$record) {
            return new WP_Error('not_found', 'Pre-qualification request not found', ['status' => 404]);
        }

        // Check if expired
        $is_expired = false;
        if (!empty($record['expiry_date'])) {
            $expiry = strtotime($record['expiry_date']);
            if ($expiry && $expiry < time()) {
                $is_expired = true;
            }
        }

        // Map internal status to UCP-friendly response
        $status = $record['status'] ?? 'pending';
        $status_upper = strtoupper($status);

        // Determine eligibility based on status
        // Approved statuses from Avvance webhook
        $approved_statuses = ['APPROVED', 'PRE_APPROVED', 'PRE-APPROVED', 'PREAPPROVED'];
        $pending_statuses = ['PENDING', 'IN_PROGRESS', 'SUBMITTED'];

        $eligible = null;
        if (in_array($status_upper, $pending_statuses)) {
            $eligible = null; // Unknown yet
        } elseif (in_array($status_upper, $approved_statuses)) {
            $eligible = true;
        } else {
            $eligible = false;
        }

        if ($is_expired) {
            return [
                "request_id" => $request_id,
                "status" => "expired",
                "eligible" => false,
                "message" => "Pre-qualification has expired. Please submit a new request."
            ];
        }

        // Build response based on status
        $response = [
            "request_id" => $request_id,
            "status" => $status
        ];

        if ($eligible === true) {
            $response["eligible"] = true;
            $response["max_amount"] = !empty($record['max_amount']) ? floatval($record['max_amount']) : null;
            $response["customer_name"] = $record['customer_name'] ?? null;
            $response["expiry_date"] = $record['expiry_date'] ?? null;
            $response["message"] = !empty($record['max_amount'])
                ? sprintf("Pre-qualified for up to $%s", number_format($record['max_amount'], 2))
                : "Pre-qualification approved";
        } elseif ($eligible === false) {
            $response["eligible"] = false;
            $response["message"] = "Pre-qualification was not approved.";
        } else {
            $response["eligible"] = null;
            $response["message"] = "Pre-qualification is pending. User must complete the application.";
        }

        return $response;
    }

    /**
     * Save UCP pre-approval to database (reuses existing table structure)
     */
    private static function save_ucp_preapproval($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'avvance_preapprovals';

        // Ensure table exists (uses existing handler's method)
        if (class_exists('Avvance_PreApproval_Handler')) {
            Avvance_PreApproval_Handler::create_preapproval_table();
        }

        $insert_data = [
            'request_id' => $data['request_id'],
            'session_id' => $data['session_id'],
            'browser_fingerprint' => $data['browser_fingerprint'],
            'status' => $data['status'] ?? 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            avvance_log('UCP: Failed to save pre-approval to database: ' . $wpdb->last_error, 'error');
        }

        return $result !== false;
    }
}