<?php
/**
 * Plugin Name: Elite Properties Search API
 * Description: Unified search — Resales Online + Infocasa. Keys never exposed to browser.
 * Version:     1.1.0
 * Author:      Elite Properties Spain
 */

if (!defined('ABSPATH')) exit;

// ─── CONFIG ──────────────────────────────────────────────────────────────────
define('ELITE_RESALES_KEY',        'b413901735f823c92f418e9fab51eb461d17a5eb');
define('ELITE_RESALES_FILTER',     '12461');
define('ELITE_RESALES_FEATURED',   '12470');
define('ELITE_RESALES_ENDPOINT',   'https://webapi.resales-online.com/V6/SearchProperties');

// Infocasa SOAP (mismo sistema que /vold/ — IP ya autorizada en el servidor)
define('ELITE_IC_LICENSE',     '8FA2D6C2-4152-43AE-04-37-74-F0-D1');
define('ELITE_IC_CLIENT',      'BOMAA');
define('ELITE_IC_ENDPOINT',    'http://api.infocasa.com/clientservice.asmx');
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

    // 5. Query Resales + Infocasa en paralelo
    $filter  = $featured ? ELITE_RESALES_FEATURED : ELITE_RESALES_FILTER;
    $resales  = elite_search_resales($type, $zone, $price, $beds, $page, $filter);
    $infocasa = elite_search_infocasa($type, $zone, $price, $beds, $page);
    $merged   = elite_deduplicate(array_merge($resales, $infocasa));

    return new WP_REST_Response([
        'ok'         => true,
        'total'      => count($merged),
        'page'       => $page,
        'sources'    => ['resales' => count($resales), 'infocasa' => count($infocasa)],
        'properties' => $merged,
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

// ─── INFOCASA SOAP ───────────────────────────────────────────────────────────
function elite_search_infocasa(string $type, string $zone, int $price, int $beds, int $page): array {
    $filters = '';
    if ($beds  > 0) $filters .= '<sv ty="bedrooms"><val>'.$beds.'</val><op>&gt;=</op></sv>';
    if ($price > 0) $filters .= '<sv ty="price"><val>'.$price.'</val><op>&lt;=</op></sv>';
    if ($zone)      $filters .= '<sv ty="town"><val>'.esc_xml($zone).'</val><op>=</op></sv>';
    if ($type) {
        $map = ['villa'=>'Villa','apartment'=>'Apartment','penthouse'=>'Penthouse','townhouse'=>'Townhouse','finca'=>'Country Property'];
        if (!empty($map[$type])) $filters .= '<sv ty="proptype"><val>'.$map[$type].'</val><op>=</op></sv>';
    }

    $offset = ($page - 1) * 12;
    $soap   = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Header>
    <licenceHeader xmlns="urn:schemas-infocasa-com:client-service:2005-11">
      <lk>'.ELITE_IC_LICENSE.'</lk>
    </licenceHeader>
  </soap:Header>
  <soap:Body>
    <GetProperties xmlns="urn:schemas-infocasa-com:client-service:2005-11">
      <ci>'.ELITE_IC_CLIENT.'</ci>
      <descl>200</descl><rimg>1</rimg>
      <offset>'.$offset.'</offset><limit>12</limit>
      '.$filters.'
    </GetProperties>
  </soap:Body>
</soap:Envelope>';

    $response = wp_remote_post(ELITE_IC_ENDPOINT, [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '"urn:schemas-infocasa-com:client-service:2005-11/GetProperties"',
        ],
        'body' => $soap,
    ]);

    if (is_wp_error($response)) return [];
    $raw = wp_remote_retrieve_body($response);
    if (!$raw) return [];

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($raw);
    if (!$doc) return [];

    $nodes = $doc->xpath('//*[local-name()="p"]');
    if (empty($nodes)) return [];

    $result = [];
    foreach ($nodes as $p) {
        $img = (string)($p->images->img ?? $p->attachments->att ?? '');
        $result[] = [
            'source'   => 'infocasa',
            'ref'      => (string)($p->ref  ?? $p->rn  ?? ''),
            'title'    => (string)($p->title ?? $p->desc ?? 'Property'),
            'price'    => (float)($p->price  ?? $p->pr  ?? 0),
            'beds'     => (int)($p->beds     ?? $p->bd  ?? 0),
            'baths'    => (int)($p->baths    ?? $p->bt  ?? 0),
            'sqm'      => (float)($p->built  ?? $p->sqm ?? 0),
            'location' => (string)($p->town  ?? $p->ar  ?? ''),
            'type'     => (string)($p->type  ?? $p->pt  ?? ''),
            'image'    => $img,
            'url'      => (string)($p->url   ?? ''),
        ];
    }
    return $result;
}

// ─── DEDUPLICACIÓN ───────────────────────────────────────────────────────────
function elite_deduplicate(array $props): array {
    // Resales tiene prioridad cuando hay duplicado (más datos)
    usort($props, fn($a, $b) => ($a['source'] === 'resales' ? 0 : 1) - ($b['source'] === 'resales' ? 0 : 1));
    $seen = [];
    $out  = [];
    foreach ($props as $p) {
        $bucket = round($p['price'] / 5000) * 5000;
        $key    = $bucket.'|'.$p['beds'].'|'.strtolower(substr(trim($p['location']), 0, 4));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $p;
        }
    }
    return $out;
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
