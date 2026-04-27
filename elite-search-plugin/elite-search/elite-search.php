<?php
/**
 * Plugin Name: Elite Properties Search API
 * Description: Unified search endpoint for Resales Online. Keys never exposed to browser.
 * Version:     1.0.0
 * Author:      Elite Properties Spain
 */

if (!defined('ABSPATH')) exit;

// ─── CONFIG ──────────────────────────────────────────────────────────────────
define('ELITE_RESALES_KEY',        'b413901735f823c92f418e9fab51eb461d17a5eb');
define('ELITE_RESALES_FILTER',     '12461');
define('ELITE_RESALES_FEATURED',   '12470');
define('ELITE_RESALES_ENDPOINT',   'https://webapi.resales-online.com/V6/SearchProperties');
define('ELITE_NONCE_SECRET',       'elite_search_2026_nR7kP2mQ9xL4wV1j');
define('ELITE_NONCE_TTL',          300);
define('ELITE_RATE_MAX',           20);
define('ELITE_RATE_WINDOW',        60);
define('ELITE_ALLOWED_ORIGINS', [
    'https://ecohomefence-oss.github.io',
    'https://www.elitepropertiesspain.com',
    'https://elitepropertiesspain.com',
]);

// ─── REGISTER REST ROUTES ────────────────────────────────────────────────────
add_action('rest_api_init', function () {

    register_rest_route('elite/v1', '/nonce', [
        'methods'             => 'GET',
        'callback'            => 'elite_nonce_endpoint',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('elite/v1', '/search', [
        'methods'             => 'POST',
        'callback'            => 'elite_search_endpoint',
        'permission_callback' => '__return_true',
    ]);
});

// ─── CORS HEADERS ────────────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($served, $result, $request) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (in_array($origin, ELITE_ALLOWED_ORIGINS, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-WP-Nonce');
            header('Vary: Origin');
        }
        return $served;
    }, 10, 3);
}, 15);

// ─── NONCE ENDPOINT ──────────────────────────────────────────────────────────
function elite_nonce_endpoint() {
    return new WP_REST_Response([
        'nonce' => elite_make_nonce(),
        'ttl'   => ELITE_NONCE_TTL,
    ], 200);
}

// ─── SEARCH ENDPOINT ─────────────────────────────────────────────────────────
function elite_search_endpoint(WP_REST_Request $request) {

    // 1. Validate referer
    $ref  = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    $ok   = ['ecohomefence-oss.github.io', 'www.elitepropertiesspain.com', 'elitepropertiesspain.com'];
    if (!in_array($ref, $ok, true)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    // 2. Validate nonce
    $nonce = trim($request->get_param('nonce') ?? '');
    if (!elite_verify_nonce($nonce)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Token inválido'], 403);
    }

    // 3. Rate limit
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (!elite_rate_limit($ip)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Demasiadas peticiones'], 429);
    }

    // 4. Params
    $type     = sanitize_text_field($request->get_param('type')  ?? '');
    $zone     = sanitize_text_field($request->get_param('zone')  ?? '');
    $price    = max(0, (int)($request->get_param('price') ?? 0));
    $beds     = max(0, (int)($request->get_param('beds')  ?? 0));
    $page     = max(1, (int)($request->get_param('page')  ?? 1));
    $featured = ($request->get_param('featured') === 'true');

    // 5. Query Resales
    $filter   = $featured ? ELITE_RESALES_FEATURED : ELITE_RESALES_FILTER;
    $props    = elite_search_resales($type, $zone, $price, $beds, $page, $filter);

    return new WP_REST_Response([
        'ok'         => true,
        'total'      => count($props),
        'page'       => $page,
        'properties' => $props,
    ], 200);
}

// ─── RESALES ONLINE ──────────────────────────────────────────────────────────
function elite_search_resales(string $type, string $zone, int $price, int $beds, int $page, string $filter): array {
    $params = [
        'p1'             => $filter,
        'p2'             => ELITE_RESALES_KEY,
        'P_Lang'         => 2,
        'P_Image'        => 1,
        'P_PageSize'     => 12,
        'P_PageNo'       => $page,
        'P_ShowGPSCoords'=> 'FALSE',
    ];
    if ($beds  > 0) $params['P_Beds']          = $beds;
    if ($price > 0) $params['P_PriceMax']      = $price;
    if ($zone)      $params['P_Location']      = $zone;
    if ($type)      $params['P_PropertyTypes'] = elite_map_type($type);

    $url      = ELITE_RESALES_ENDPOINT . '?' . http_build_query($params);
    $response = wp_remote_get($url, ['timeout' => 15, 'user-agent' => 'EliteProperties/2026']);

    if (is_wp_error($response)) return [];
    $raw  = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    if (empty($data['properties']) || !is_array($data['properties'])) return [];

    return array_map('elite_normalize_property', $data['properties']);
}

function elite_normalize_property(array $p): array {
    $img = $p['images'][0]['url']
        ?? $p['attachments'][0]['url']
        ?? $p['mainImage']
        ?? '';
    return [
        'source'   => 'resales',
        'ref'      => (string)($p['ref']   ?? $p['id']    ?? ''),
        'title'    => $p['descriptions'][0]['title'] ?? ($p['type']['description'] ?? 'Property'),
        'price'    => (float)($p['price']  ?? 0),
        'beds'     => (int)($p['beds']     ?? 0),
        'baths'    => (int)($p['baths']    ?? 0),
        'sqm'      => (float)($p['built']  ?? $p['plot'] ?? 0),
        'location' => $p['town']['description'] ?? ($p['location']['description'] ?? ''),
        'type'     => $p['type']['description'] ?? '',
        'image'    => $img,
        'url'      => $p['links'][0]['url'] ?? '',
    ];
}

function elite_map_type(string $type): string {
    return match($type) {
        'villa'     => '3-1',
        'apartment' => '2-1',
        'penthouse' => '2-5',
        'townhouse' => '3-3',
        'finca'     => '3-7',
        default     => '',
    };
}

// ─── NONCE ───────────────────────────────────────────────────────────────────
function elite_make_nonce(): string {
    $slot = (int)floor(time() / ELITE_NONCE_TTL);
    return hash_hmac('sha256', (string)$slot, ELITE_NONCE_SECRET);
}

function elite_verify_nonce(string $n): bool {
    if (strlen($n) !== 64) return false;
    $slot = (int)floor(time() / ELITE_NONCE_TTL);
    return hash_equals(elite_make_nonce(), $n)
        || hash_equals(hash_hmac('sha256', (string)($slot - 1), ELITE_NONCE_SECRET), $n);
}

// ─── RATE LIMITING ───────────────────────────────────────────────────────────
function elite_rate_limit(string $ip): bool {
    $key  = 'elite_rl_' . md5($ip);
    $info = get_transient($key) ?: ['c' => 0, 't' => time()];
    if (time() - $info['t'] > ELITE_RATE_WINDOW) {
        $info = ['c' => 1, 't' => time()];
    } else {
        $info['c']++;
    }
    set_transient($key, $info, ELITE_RATE_WINDOW * 2);
    return $info['c'] <= ELITE_RATE_MAX;
}
