/**
 * Cloudflare Worker - UCP Proxy for AI Agents
 *
 * This bypasses ModSecurity because:
 * 1. Cloudflare Workers have trusted IP ranges
 * 2. We add browser-like headers to the forwarded request
 * 3. The request appears to come from Cloudflare, not OpenAI
 *
 * SETUP INSTRUCTIONS:
 * 1. Go to https://dash.cloudflare.com/
 * 2. Navigate to Workers & Pages > Create Application > Create Worker
 * 3. Paste this code and deploy
 * 4. Your worker URL will be: https://your-worker-name.your-subdomain.workers.dev
 * 5. Use this URL as the server in your OpenAPI schema
 *
 * CONFIGURATION:
 * Change TARGET_STORE to your WooCommerce store URL
 */

const TARGET_STORE = 'https://ecom-sandbox.net';

export default {
  async fetch(request, env, ctx) {
    // CORS headers for all responses
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Accept, Origin, X-Requested-With',
      'Access-Control-Max-Age': '86400',
    };

    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    try {
      const url = new URL(request.url);

      // Debug: Log EVERYTHING about the incoming request
      console.log('=== INCOMING REQUEST ===');
      console.log('Full URL:', request.url);
      console.log('Pathname:', url.pathname);
      console.log('Search:', url.search);
      console.log('Host:', url.host);
      console.log('Method:', request.method);

      // Log all headers
      const headerObj = {};
      for (const [key, value] of request.headers.entries()) {
        headerObj[key] = value;
      }
      console.log('Headers:', JSON.stringify(headerObj));

      // Get the endpoint - GPT Actions sends path like /products with params separate
      let endpoint = null;

      // Method 1: Check query parameter (for manual/browser testing)
      endpoint = url.searchParams.get('ucp_endpoint') || url.searchParams.get('endpoint');

      // Method 2: Use the URL path (this is how GPT Actions sends it)
      // GPT sends: /products?q=furnace  -> pathname = '/products'
      if (!endpoint) {
        const pathname = url.pathname;
        // Accept any path that looks like a valid endpoint
        if (pathname && pathname !== '/' && pathname.length > 1) {
          endpoint = pathname;
        }
      }

      // Debug logging
      console.log('Parsed endpoint:', endpoint);
      console.log('Pathname:', url.pathname);
      console.log('Search params:', [...url.searchParams.entries()]);

      if (!endpoint) {
        return new Response(JSON.stringify({
          error: true,
          message: 'Missing ucp_endpoint parameter',
          debug: {
            url: request.url,
            pathname: url.pathname,
            search: url.search,
            params: Object.fromEntries(url.searchParams),
            hint: 'Use /products?q=search or ?ucp_endpoint=/products'
          }
        }), {
          status: 400,
          headers: { 'Content-Type': 'application/json', ...corsHeaders },
        });
      }

      // Build target URL - start fresh
      const targetUrl = new URL(TARGET_STORE);
      targetUrl.pathname = '/';
      targetUrl.search = ''; // Clear any existing params

      // Set required params
      targetUrl.searchParams.set('avvance_api', '1');
      targetUrl.searchParams.set('ucp_endpoint', endpoint);

      // Copy other query params (exclude routing params)
      const excludeParams = ['ucp_endpoint', 'endpoint', 'avvance_api'];
      for (const [key, value] of url.searchParams.entries()) {
        if (!excludeParams.includes(key)) {
          targetUrl.searchParams.set(key, value);
        }
      }

      console.log('Target URL:', targetUrl.toString());

      // Browser-like headers that bypass ModSecurity
      // Also includes a bypass header for Cloudflare WAF rules
      const headers = new Headers({
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'application/json, text/plain, */*',
        'Accept-Language': 'en-US,en;q=0.9',
        'Connection': 'keep-alive',
        'Sec-Ch-Ua': '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
        'Sec-Ch-Ua-Mobile': '?0',
        'Sec-Ch-Ua-Platform': '"Windows"',
        'Sec-Fetch-Dest': 'empty',
        'Sec-Fetch-Mode': 'cors',
        'Sec-Fetch-Site': 'cross-site',
        // Custom bypass header - configure Cloudflare WAF to skip challenge when this header is present
        'X-UCP-Bypass': 'avvance-ai-agent-2024',
        'X-Forwarded-For': '203.0.113.1', // Dummy IP to avoid Worker IP detection
      });

      // Get request body for POST/PUT
      let body = null;
      if (request.method === 'POST' || request.method === 'PUT') {
        body = await request.text();
        headers.set('Content-Type', 'application/json');
      }

      // Forward the request
      const response = await fetch(targetUrl.toString(), {
        method: request.method,
        headers: headers,
        body: body,
      });

      // Get response body
      const responseBody = await response.text();

      console.log('Response status:', response.status);
      console.log('Response body preview:', responseBody.substring(0, 200));

      // Return with CORS headers
      return new Response(responseBody, {
        status: response.status,
        headers: { 'Content-Type': 'application/json', ...corsHeaders },
      });

    } catch (error) {
      console.error('Worker error:', error);
      return new Response(JSON.stringify({
        error: true,
        message: 'Proxy error: ' + error.message
      }), {
        status: 500,
        headers: { 'Content-Type': 'application/json', ...corsHeaders },
      });
    }
  },
};
