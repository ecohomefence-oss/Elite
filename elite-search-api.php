<?php
/**
 * Elite Properties — Unified Search API
 * Combina Resales Online + Infocasa. Las keys NUNCA llegan al navegador.
 *
 * Instalación: subir este archivo a /elite-api/ en el WordPress de elitepropertiesspain.com
 * URL pública:  https://www.elitepropertiesspain.com/elite-api/elite-search-api.php
 */

// ─── CONFIGURACIÓN (solo servidor, nunca visible al cliente) ─────────────────
define('ALLOWED_ORIGINS', [
    'https://ecohomefence-oss.github.io',
    'https://www.elitepropertiesspain.com',
    'https://elitepropertiesspain.com',
]);

// Cambia esta cadena por una aleatoria larga — es el secreto del nonce
define('NONCE_SECRET', 'elite_props_2026_xK9mP3qL7nR2sV8w');

define('NONCE_TTL',          300);  // 5 minutos
define('RATE_LIMIT_MAX',      20);  // máx. peticiones por IP por ventana
define('RATE_LIMIT_WINDOW',   60);  // segundos
define('RATE_LIMIT_DIR',      sys_get_temp_dir() . '/elite_rl/');
define('RESULTS_PER_PAGE',    12);

// Resales Online WebAPI v6
define('RESALES_API_KEY',     '3b681cf069ac457cbdc02835ac88a9fac1d0cb2c');
define('RESALES_FILTER_ID',   '');  // ← rellenar desde Resales: Cuenta → API → Filtros
define('RESALES_ENDPOINT',    'https://webapi.resales-online.com/V6/SearchProperties');

// Infocasa SOAP
define('INFOCASA_LICENSE_KEY', '8FA2D6C2-4152-43AE-04-37-74-F0-D1');
define('INFOCASA_CLIENT_ID',   'BOMAA');
define('INFOCASA_ENDPOINT',    'http://api.infocasa.com/clientservice.asmx');
// ─────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// CORS — solo dominios autorizados
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── RUTAS ───────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

// Endpoint nonce: la página lo pide al cargarse
if ($action === 'nonce') {
    echo json_encode(['nonce' => make_nonce(), 'ttl' => NONCE_TTL]);
    exit;
}

// Endpoint búsqueda
if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Validar referer (solo peticiones desde dominios autorizados)
    $ref_host = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
    $ok_hosts  = ['ecohomefence-oss.github.io', 'www.elitepropertiesspain.com', 'elitepropertiesspain.com'];
    if (!in_array($ref_host, $ok_hosts, true)) {
        json_error('Forbidden', 403);
    }

    // 2. Validar nonce
    $nonce = trim($_POST['nonce'] ?? '');
    if (!verify_nonce($nonce)) {
        json_error('Token inválido o expirado', 403);
    }

    // 3. Rate limiting por IP
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
    if (!check_rate_limit($ip)) {
        json_error('Demasiadas peticiones', 429);
    }

    // 4. Parámetros de búsqueda
    $type  = sanitize($_POST['type']  ?? '');
    $zone  = sanitize($_POST['zone']  ?? '');
    $price = max(0, (int)($_POST['price'] ?? 0));
    $beds  = max(0, (int)($_POST['beds']  ?? 0));
    $page  = max(1, (int)($_POST['page']  ?? 1));

    // 5. Consultar ambas APIs en paralelo usando cURL multi
    [$resales_raw, $infocasa_raw] = fetch_parallel($type, $zone, $price, $beds, $page);

    $resales  = parse_resales($resales_raw);
    $infocasa = parse_infocasa($infocasa_raw);

    // 6. Normalizar, mezclar y deduplicar
    $all     = array_merge($resales, $infocasa);
    $merged  = deduplicate($all);

    echo json_encode([
        'ok'         => true,
        'total'      => count($merged),
        'page'       => $page,
        'sources'    => ['resales' => count($resales), 'infocasa' => count($infocasa)],
        'properties' => $merged,
    ]);
    exit;
}

json_error('Petición no válida', 400);

// ─── NONCE ───────────────────────────────────────────────────────────────────
function make_nonce(): string {
    $slot = (int)floor(time() / NONCE_TTL);
    return hash_hmac('sha256', (string)$slot, NONCE_SECRET);
}

function verify_nonce(string $n): bool {
    if (strlen($n) !== 64) return false;
    $slot = (int)floor(time() / NONCE_TTL);
    // acepta la ventana actual y la anterior (evita rechazos en el límite)
    $current  = hash_hmac('sha256', (string)$slot,       NONCE_SECRET);
    $previous = hash_hmac('sha256', (string)($slot - 1), NONCE_SECRET);
    return hash_equals($current, $n) || hash_equals($previous, $n);
}

// ─── RATE LIMITING ───────────────────────────────────────────────────────────
function check_rate_limit(string $ip): bool {
    @mkdir(RATE_LIMIT_DIR, 0700, true);
    $file = RATE_LIMIT_DIR . md5($ip);
    $data = @file_get_contents($file);
    $info = $data ? json_decode($data, true) : ['c' => 0, 't' => time()];

    if (time() - $info['t'] > RATE_LIMIT_WINDOW) {
        $info = ['c' => 1, 't' => time()];
    } else {
        $info['c']++;
    }
    file_put_contents($file, json_encode($info), LOCK_EX);
    return $info['c'] <= RATE_LIMIT_MAX;
}

// ─── PETICIONES EN PARALELO ──────────────────────────────────────────────────
function fetch_parallel(string $type, string $zone, int $price, int $beds, int $page): array {
    $mh = curl_multi_init();

    // — Resales Online —
    $params = [
        'P_Agency_FilterID' => RESALES_FILTER_ID,
        'P_API_Key'         => RESALES_API_KEY,
        'P_Lang'            => 2,
        'P_Image'           => 1,
        'P_PageSize'        => RESULTS_PER_PAGE,
        'P_PageNo'          => $page,
        'P_ShowGPSCoords'   => 'FALSE',
    ];
    if ($beds > 0)  $params['P_Beds']         = $beds;
    if ($price > 0) $params['P_PriceMax']     = $price;
    if ($zone)      $params['P_Location']     = $zone;
    if ($type)      $params['P_PropertyTypes']= map_type_resales($type);

    $ch_resales = curl_init(RESALES_ENDPOINT . '?' . http_build_query($params));
    curl_setopt_array($ch_resales, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'EliteProperties/2026',
    ]);
    curl_multi_add_handle($mh, $ch_resales);

    // — Infocasa SOAP —
    $sv_xml = build_infocasa_filters($type, $zone, $price, $beds);
    $offset = ($page - 1) * RESULTS_PER_PAGE;
    $soap_body = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Header>
    <licenceHeader xmlns="urn:schemas-infocasa-com:client-service:2005-11">
      <lk>' . INFOCASA_LICENSE_KEY . '</lk>
    </licenceHeader>
  </soap:Header>
  <soap:Body>
    <GetProperties xmlns="urn:schemas-infocasa-com:client-service:2005-11">
      <ci>' . INFOCASA_CLIENT_ID . '</ci>
      <descl>200</descl>
      <rimg>1</rimg>
      <offset>' . $offset . '</offset>
      <limit>' . RESULTS_PER_PAGE . '</limit>
      ' . $sv_xml . '
    </GetProperties>
  </soap:Body>
</soap:Envelope>';

    $ch_infocasa = curl_init(INFOCASA_ENDPOINT);
    curl_setopt_array($ch_infocasa, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $soap_body,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "urn:schemas-infocasa-com:client-service:2005-11/GetProperties"',
        ],
    ]);
    curl_multi_add_handle($mh, $ch_infocasa);

    // Ejecutar en paralelo
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

    $r1 = curl_multi_getcontent($ch_resales);
    $r2 = curl_multi_getcontent($ch_infocasa);

    curl_multi_remove_handle($mh, $ch_resales);
    curl_multi_remove_handle($mh, $ch_infocasa);
    curl_multi_close($mh);

    return [$r1 ?: '', $r2 ?: ''];
}

// ─── PARSERS ─────────────────────────────────────────────────────────────────
function parse_resales(string $raw): array {
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!isset($data['properties']) || !is_array($data['properties'])) return [];

    return array_map(function($p) {
        $img = $p['images'][0]['url']      ??
               $p['attachments'][0]['url'] ??
               $p['mainImage']             ?? '';
        return [
            'source'   => 'resales',
            'ref'      => (string)($p['ref'] ?? $p['id'] ?? ''),
            'title'    => $p['descriptions'][0]['title'] ?? ($p['type']['description'] ?? 'Property'),
            'price'    => (float)($p['price'] ?? 0),
            'beds'     => (int)($p['beds']  ?? 0),
            'baths'    => (int)($p['baths'] ?? 0),
            'sqm'      => (float)($p['built'] ?? $p['plot'] ?? 0),
            'location' => $p['town']['description'] ?? ($p['location']['description'] ?? ''),
            'type'     => $p['type']['description'] ?? '',
            'image'    => $img,
            'url'      => $p['links'][0]['url'] ?? '',
        ];
    }, $data['properties']);
}

function parse_infocasa(string $raw): array {
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
            'ref'      => (string)($p->ref ?? $p->rn ?? ''),
            'title'    => (string)($p->title ?? $p->desc ?? 'Property'),
            'price'    => (float)($p->price ?? $p->pr ?? 0),
            'beds'     => (int)($p->beds  ?? $p->bd ?? 0),
            'baths'    => (int)($p->baths ?? $p->bt ?? 0),
            'sqm'      => (float)($p->built ?? $p->sqm ?? 0),
            'location' => (string)($p->town ?? $p->ar ?? ''),
            'type'     => (string)($p->type ?? $p->pt ?? ''),
            'image'    => $img,
            'url'      => (string)($p->url ?? ''),
        ];
    }
    return $result;
}

// ─── DEDUPLICACIÓN ───────────────────────────────────────────────────────────
function deduplicate(array $props): array {
    // Elimina duplicados por precio (±5%) + dormitorios + primeras 4 letras de zona
    // Cuando hay duplicado, se queda el de Resales (más datos)
    usort($props, fn($a, $b) => ($a['source'] === 'resales' ? 0 : 1) - ($b['source'] === 'resales' ? 0 : 1));

    $seen = [];
    $out  = [];
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

// ─── MAPEO DE TIPOS ──────────────────────────────────────────────────────────
function map_type_resales(string $type): string {
    return match($type) {
        'villa'      => '3-1',
        'apartment'  => '2-1',
        'penthouse'  => '2-5',
        'townhouse'  => '3-3',
        'finca'      => '3-7',
        default      => '',
    };
}

function build_infocasa_filters(string $type, string $zone, int $price, int $beds): string {
    $filters = [];
    if ($beds > 0)  $filters[] = ['ty' => 'bedrooms', 'val' => $beds,  'op' => '>='];
    if ($price > 0) $filters[] = ['ty' => 'price',    'val' => $price, 'op' => '<='];
    if ($zone)      $filters[] = ['ty' => 'town',     'val' => $zone,  'op' => '='];
    if ($type) {
        $ic_types = [
            'villa'     => 'Villa',
            'apartment' => 'Apartment',
            'penthouse' => 'Penthouse',
            'townhouse' => 'Townhouse',
            'finca'     => 'Country Property',
        ];
        if (isset($ic_types[$type])) {
            $filters[] = ['ty' => 'proptype', 'val' => $ic_types[$type], 'op' => '='];
        }
    }
    $xml = '';
    foreach ($filters as $f) {
        $xml .= sprintf(
            '<sv ty="%s"><val>%s</val><op>%s</op></sv>',
            htmlspecialchars($f['ty']),
            htmlspecialchars((string)$f['val']),
            htmlspecialchars($f['op'])
        );
    }
    return $xml;
}

// ─── UTILIDADES ──────────────────────────────────────────────────────────────
function sanitize(string $s): string {
    return htmlspecialchars(strip_tags(trim($s)), ENT_QUOTES, 'UTF-8');
}

function json_error(string $msg, int $code): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
