<?php
/**
 * connect.php — tiny JSON backend for the portfolio's poll + ping wall.
 *
 * Upload the whole api/ folder next to index.html on any PHP 7.4+ host
 * (GoDaddy cPanel works). No database: data lives in api/data/connect.json,
 * which api/data/.htaccess keeps private.
 *
 *   GET  api/connect.php                        → current state
 *   POST {"type":"vote","choice":"coffee"}      → one vote per visitor (changeable)
 *   POST {"type":"ping","name","note","kind"}   → add a note (rate limited)
 *   POST {"type":"delete","id","token"}         → remove a note (needs ADMIN_TOKEN)
 *
 * Moderation: set ADMIN_TOKEN below to a long random string, then delete a
 * note with  curl -X POST -d '{"type":"delete","id":"<id>","token":"<token>"}' https://yoursite/api/connect.php
 * or simply edit api/data/connect.json by hand.
 */
declare(strict_types=1);

const DATA_DIR      = __DIR__ . '/data';
const DATA_FILE     = DATA_DIR . '/connect.json';
const CHOICES       = ['console.log', 'breakpoints', 'rubber-duck', 'coffee'];  // keep in sync with POLL in index.html
const SHOW_PINGS    = 30;     // notes returned to the page
const KEEP_PINGS    = 300;    // notes kept on disk
const PING_COOLDOWN = 60;     // seconds between notes from one visitor
const PINGS_PER_DAY = 5;      // per visitor
const ADMIN_TOKEN   = '';     // set to enable deletes over HTTP; empty = disabled

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Anonymous visitor id: random, kept in a first-party cookie. Only its hash is stored. */
function visitor(): string {
    $id = $_COOKIE['pb_vid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        setcookie('pb_vid', $id, [
            'expires'  => time() + 31536000,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return hash('sha256', $id);
}

function clean(string $s, int $max): string {
    $s = strip_tags($s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/* Read-modify-write under an exclusive lock so two visitors can't clobber each other. */
function with_data(callable $fn) {
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true)) out(['error' => 'Storage unavailable'], 500);
    $fh = fopen(DATA_FILE, 'c+');
    if (!$fh) out(['error' => 'Storage unavailable'], 500);
    flock($fh, LOCK_EX);
    $raw  = stream_get_contents($fh);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) $data = [];
    $data += ['votes' => [], 'pings' => [], 'rate' => []];
    [$data, $result] = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $result;
}

function state(array $d, string $me): array {
    $counts = array_fill_keys(CHOICES, 0);
    foreach ($d['votes'] as $v) if (isset($counts[$v])) $counts[$v]++;
    $pings = array_slice(array_reverse($d['pings']), 0, SHOW_PINGS);
    return [
        'counts' => $counts,
        'mine'   => $d['votes'][$me] ?? null,
        'pings'  => array_map(fn($p) => [
            'id' => $p['id'], 'name' => $p['name'], 'note' => $p['note'],
            'kind' => $p['kind'], 'at' => $p['at'],
        ], $pings),
    ];
}

$me     = visitor();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    out(with_data(fn($d) => [$d, state($d, $me)]));
}
if ($method !== 'POST') out(['error' => 'Method not allowed'], 405);

$body = file_get_contents('php://input', false, null, 0, 4096);
$in   = json_decode($body ?: '', true);
if (!is_array($in)) out(['error' => 'Bad request'], 400);
$type = $in['type'] ?? '';

$res = with_data(function (array $d) use ($in, $type, $me) {
    $now = time() * 1000;

    if ($type === 'vote') {
        $c = (string)($in['choice'] ?? '');
        if (!in_array($c, CHOICES, true)) return [$d, ['error' => 'Unknown option', 'code' => 400]];
        $d['votes'][$me] = $c;
        return [$d, state($d, $me)];
    }

    if ($type === 'ping') {
        if (!empty($in['website'])) return [$d, state($d, $me)];   // honeypot: pretend it worked
        $note = clean((string)($in['note'] ?? ''), 280);
        $name = clean((string)($in['name'] ?? ''), 40);
        $kind = ($in['kind'] ?? '') === 'ping' ? 'ping' : 'coffee';
        if ($note === '') return [$d, ['error' => 'Write a short note first.', 'code' => 400]];

        $ip  = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . date('Y-m-d'));
        $key = $me . ':' . $ip;
        $d['rate'] = array_filter($d['rate'], fn($r) => $now - $r['t'] < 86400000);   // keep 24h
        $mine = array_values(array_filter($d['rate'], fn($r) => $r['k'] === $key || $r['ip'] === $ip));
        if ($mine && $now - max(array_column($mine, 't')) < PING_COOLDOWN * 1000)
            return [$d, ['error' => 'One note a minute, please.', 'code' => 429]];
        if (count($mine) >= PINGS_PER_DAY)
            return [$d, ['error' => 'That\'s plenty for today. Thank you!', 'code' => 429]];

        $d['rate'][]  = ['k' => $key, 'ip' => $ip, 't' => $now];
        $d['pings'][] = ['id' => bin2hex(random_bytes(6)), 'name' => $name, 'note' => $note,
                         'kind' => $kind, 'at' => $now, 'by' => $me];
        $d['pings']   = array_slice($d['pings'], -KEEP_PINGS);
        return [$d, state($d, $me)];
    }

    if ($type === 'delete') {
        if (ADMIN_TOKEN === '' || !hash_equals(ADMIN_TOKEN, (string)($in['token'] ?? '')))
            return [$d, ['error' => 'Forbidden', 'code' => 403]];
        $id = (string)($in['id'] ?? '');
        $d['pings'] = array_values(array_filter($d['pings'], fn($p) => $p['id'] !== $id));
        return [$d, state($d, $me)];
    }

    return [$d, ['error' => 'Bad request', 'code' => 400]];
});

if (isset($res['error'])) out(['error' => $res['error']], $res['code'] ?? 400);
out($res);
