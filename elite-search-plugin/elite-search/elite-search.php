<?php
/**
 * Plugin Name: Elite Properties Search API
 * Description: Unified search - Resales Online + Infocasa. Keys never exposed to browser.
 * Version:     1.2.0
 * Author:      Elite Properties Spain
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) exit;

// CONFIG
define('ELITE_RESALES_KEY',      'b413901735f823c92f418e9fab51eb461d17a5eb');
define('ELITE_RESALES_FILTER',   '12461');
define('ELITE_RESALES_FEATURED', '12470');
define('ELITE_RESALES_ENDPOINT', 'https://webapi.resales-online.com/V6/SearchProperties');
define('ELITE_IC_LICENSE',       '8FA2D6C2-4152-43AE-04-37-74-F0-D1');
define('ELITE_IC_CLIENT',        'BOMAA');
define('ELITE_IC_ENDPOINT',      'http://api.infocasa.com/clientservice.asmx');
define('ELITE_NONCE_SECRET',     'elite_search_2026_nR7kP2mQ9xL4wV1j');
define('ELITE_NONCE_TTL',        300);
define('ELITE_RATE_MAX',         20);
define('ELITE_RATE_WINDOW',      60);

add_action('rest_api_init', 'elite_register_routes');
add_action('rest_api_init', 'elite_cors_init', 15);

function elite_register_routes() {
    register_rest_route('elite/v1', '/nonce', array(
        'methods'             => 'GET',
        'callback'            => 'elite_nonce_endpoint',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('elite/v1', '/search', array(
        'methods'             => 'POST',
        'callback'            => 'elite_search_endpoint',
        'permission_callback' => '__return_true',
    ));
}

function elite_cors_init() {
    $allowed = array(
        'https://ecohomefence-oss.github.io',
        'https://www.elitepropertiesspain.com',
        'https://elitepropertiesspain.com',
    );
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($served, $result, $request) use ($allowed) {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Vary: Origin');
        }
        return $served;
    }, 10, 3);
}

function elite_nonce_endpoint() {
    return new WP_REST_Response(array(
        'nonce' => elite_make_nonce(),
        'ttl'   => ELITE_NONCE_TTL,
    ), 200);
}

function elite_search_endpoint(WP_REST_Request $req) {
    // Validate referer
    $referer  = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $ref_host = parse_url($referer, PHP_URL_HOST);
    $ok_hosts = array('ecohomefence-oss.github.io', 'www.elitepropertiesspain.com', 'elitepropertiesspain.com');
    if (!in_array($ref_host, $ok_hosts, true)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'Forbidden'), 403);
    }

    // Validate nonce
    $nonce = trim((string)$req->get_param('nonce'));
    if (!elite_verify_nonce($nonce)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'Token invalido'), 403);
    }

    // Rate limit
    $ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR'];
    if (!elite_rate_limit($ip)) {
        return new WP_REST_Response(array('ok' => false, 'error' => 'Demasiadas peticiones'), 429);
    }

    // Params
    $type     = sanitize_text_field((string)$req->get_param('type'));
    $zone     = sanitize_text_field((string)$req->get_param('zone'));
    $price    = max(0, (int)$req->get_param('price'));
    $beds     = max(0, (int)$req->get_param('beds'));
    $page     = max(1, (int)$req->get_param('page'));
    $featured = ($req->get_param('featured') === 'true');

    $filter   = $featured ? ELITE_RESALES_FEATURED : ELITE_RESALES_FILTER;
    $resales  = elite_search_resales($type, $zone, $price, $beds, $page, $filter);
    $infocasa = elite_search_infocasa($type, $zone, $price, $beds, $page);
    $merged   = elite_deduplicate(array_merge($resales, $infocasa));

    return new WP_REST_Response(array(
        'ok'         => true,
        'total'      => count($merged),
        'page'       => $page,
        'sources'    => array('resales' => count($resales), 'infocasa' => count($infocasa)),
        'properties' => $merged,
    ), 200);
}

function elite_search_resales($type, $zone, $price, $beds, $page, $filter) {
    $params = array(
        'p1'              => $filter,
        'p2'              => ELITE_RESALES_KEY,
        'P_Lang'          => 2,
        'P_Image'         => 1,
        'P_PageSize'      => 12,
        'P_PageNo'        => $page,
        'P_ShowGPSCoords' => 'FALSE',
    );
    if ($beds  > 0) $params['P_Beds']          = $beds;
    if ($price > 0) $params['P_PriceMax']       = $price;
    if ($zone)      $params['P_Location']       = $zone;
    if ($type)      $params['P_PropertyTypes']  = elite_map_type_resales($type);

    $url  = ELITE_RESALES_ENDPOINT . '?' . http_build_query($params);
    $resp = wp_remote_get($url, array('timeout' => 15, 'user-agent' => 'EliteProperties/2026'));

    if (is_wp_error($resp)) return array();
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($data['properties']) || !is_array($data['properties'])) return array();

    $out = array();
    foreach ($data['properties'] as $p) {
        $img = '';
        if (!empty($p['images'][0]['url']))      $img = $p['images'][0]['url'];
        elseif (!empty($p['attachments'][0]['url'])) $img = $p['attachments'][0]['url'];
        elseif (!empty($p['mainImage']))             $img = $p['mainImage'];

        $title = '';
        if (!empty($p['descriptions'][0]['title'])) $title = $p['descriptions'][0]['title'];
        elseif (!empty($p['type']['description']))   $title = $p['type']['description'];
        else                                         $title = 'Property';

        $location = '';
        if (!empty($p['town']['description']))     $location = $p['town']['description'];
        elseif (!empty($p['location']['description'])) $location = $p['location']['description'];

        $url_prop = !empty($p['links'][0]['url']) ? $p['links'][0]['url'] : '';

        $out[] = array(
            'source'   => 'resales',
            'ref'      => isset($p['ref']) ? (string)$p['ref'] : (isset($p['id']) ? (string)$p['id'] : ''),
            'title'    => $title,
            'price'    => isset($p['price']) ? (float)$p['price'] : 0,
            'beds'     => isset($p['beds'])  ? (int)$p['beds']   : 0,
            'baths'    => isset($p['baths']) ? (int)$p['baths']  : 0,
            'sqm'      => isset($p['built']) ? (float)$p['built'] : (isset($p['plot']) ? (float)$p['plot'] : 0),
            'location' => $location,
            'type'     => isset($p['type']['description']) ? $p['type']['description'] : '',
            'image'    => $img,
            'url'      => $url_prop,
        );
    }
    return $out;
}

function elite_map_type_resales($type) {
    $map = array(
        'villa'     => '3-1',
        'apartment' => '2-1',
        'penthouse' => '2-5',
        'townhouse' => '3-3',
        'finca'     => '3-7',
    );
    return isset($map[$type]) ? $map[$type] : '';
}

function elite_search_infocasa($type, $zone, $price, $beds, $page) {
    $filters = '';
    if ($beds  > 0) $filters .= '<sv ty="bedrooms"><val>' . (int)$beds  . '</val><op>&gt;=</op></sv>';
    if ($price > 0) $filters .= '<sv ty="price"><val>'    . (int)$price . '</val><op>&lt;=</op></sv>';
    if ($zone)      $filters .= '<sv ty="town"><val>'     . htmlspecialchars($zone, ENT_XML1, 'UTF-8') . '</val><op>=</op></sv>';
    if ($type) {
        $ic_map = array('villa' => 'Villa', 'apartment' => 'Apartment', 'penthouse' => 'Penthouse', 'townhouse' => 'Townhouse', 'finca' => 'Country Property');
        if (!empty($ic_map[$type])) {
            $filters .= '<sv ty="proptype"><val>' . $ic_map[$type] . '</val><op>=</op></sv>';
        }
    }

    $offset = ($page - 1) * 12;
    $soap   = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Header><licenceHeader xmlns="urn:schemas-infocasa-com:client-service:2005-11"><lk>' . ELITE_IC_LICENSE . '</lk></licenceHeader></soap:Header>'
        . '<soap:Body><GetProperties xmlns="urn:schemas-infocasa-com:client-service:2005-11">'
        . '<ci>' . ELITE_IC_CLIENT . '</ci>'
        . '<descl>200</descl><rimg>1</rimg>'
        . '<offset>' . $offset . '</offset><limit>12</limit>'
        . $filters
        . '</GetProperties></soap:Body></soap:Envelope>';

    $resp = wp_remote_post(ELITE_IC_ENDPOINT, array(
        'timeout' => 15,
        'headers' => array(
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '"urn:schemas-infocasa-com:client-service:2005-11/GetProperties"',
        ),
        'body' => $soap,
    ));

    if (is_wp_error($resp)) return array();
    $raw = wp_remote_retrieve_body($resp);
    if (!$raw) return array();

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($raw);
    if (!$doc) return array();

    $nodes = $doc->xpath('//*[local-name()="p"]');
    if (empty($nodes)) return array();

    $out = array();
    foreach ($nodes as $p) {
        $img = '';
        if (!empty($p->images->img))      $img = (string)$p->images->img;
        elseif (!empty($p->attachments->att)) $img = (string)$p->attachments->att;

        $out[] = array(
            'source'   => 'infocasa',
            'ref'      => isset($p->ref)   ? (string)$p->ref   : (isset($p->rn)  ? (string)$p->rn   : ''),
            'title'    => isset($p->title) ? (string)$p->title : (isset($p->desc)? (string)$p->desc  : 'Property'),
            'price'    => isset($p->price) ? (float)$p->price  : (isset($p->pr)  ? (float)$p->pr     : 0),
            'beds'     => isset($p->beds)  ? (int)$p->beds     : (isset($p->bd)  ? (int)$p->bd       : 0),
            'baths'    => isset($p->baths) ? (int)$p->baths    : (isset($p->bt)  ? (int)$p->bt       : 0),
            'sqm'      => isset($p->built) ? (float)$p->built  : (isset($p->sqm) ? (float)$p->sqm    : 0),
            'location' => isset($p->town)  ? (string)$p->town  : (isset($p->ar)  ? (string)$p->ar    : ''),
            'type'     => isset($p->type)  ? (string)$p->type  : (isset($p->pt)  ? (string)$p->pt    : ''),
            'image'    => $img,
            'url'      => isset($p->url)   ? (string)$p->url   : '',
        );
    }
    return $out;
}

function elite_deduplicate($props) {
    usort($props, function($a, $b) {
        $wa = $a['source'] === 'resales' ? 0 : 1;
        $wb = $b['source'] === 'resales' ? 0 : 1;
        return $wa - $wb;
    });
    $seen = array();
    $out  = array();
    foreach ($props as $p) {
        $bucket = round($p['price'] / 5000) * 5000;
        $key    = $bucket . '|' . $p['beds'] . '|' . strtolower(substr(trim($p['location']), 0, 4));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $p;
        }
    }
    return $out;
}

function elite_make_nonce() {
    $slot = (int)floor(time() / ELITE_NONCE_TTL);
    return hash_hmac('sha256', (string)$slot, ELITE_NONCE_SECRET);
}

function elite_verify_nonce($n) {
    if (strlen($n) !== 64) return false;
    $slot = (int)floor(time() / ELITE_NONCE_TTL);
    $cur  = hash_hmac('sha256', (string)$slot,       ELITE_NONCE_SECRET);
    $prev = hash_hmac('sha256', (string)($slot - 1), ELITE_NONCE_SECRET);
    return hash_equals($cur, $n) || hash_equals($prev, $n);
}

function elite_rate_limit($ip) {
    $key  = 'elite_rl_' . md5($ip);
    $info = get_transient($key);
    if (!$info) $info = array('c' => 0, 't' => time());
    if (time() - $info['t'] > ELITE_RATE_WINDOW) {
        $info = array('c' => 1, 't' => time());
    } else {
        $info['c']++;
    }
    set_transient($key, $info, ELITE_RATE_WINDOW * 2);
    return $info['c'] <= ELITE_RATE_MAX;
}
