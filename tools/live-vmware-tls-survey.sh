#!/usr/bin/env bash
# Read-only connector metadata and credential-free TLS diagnostics.
# Only public peer certificates are written, outside the app and checkout.
set -euo pipefail
trap 'rc=$?; printf "\nTLS_SURVEY_EXIT=%s\n" "$rc"' EXIT
[[ "$(id -u)" -eq 0 ]] || { echo 'STOP: Run as root.'; exit 1; }
for cmd in php openssl timeout awk runuser mktemp; do
    command -v "$cmd" >/dev/null || { echo "STOP: Missing $cmd"; exit 1; }
done
getent passwd www-data >/dev/null || { echo 'STOP: www-data not found.'; exit 1; }
APP='/var/www/html'
CONFIG="$(PHPIPAM_TLS_APP="$APP" php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
error_reporting(0);
try {
    $db = []; $config = [];
    ob_start();
    try { require getenv('PHPIPAM_TLS_APP') . '/config.php'; }
    finally { ob_end_clean(); }
    if (!isset($db['host'], $db['name'], $db['user'], $db['pass'])) throw new RuntimeException();
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5];
    if (!empty($db['ssl'])) {
        foreach (['ssl_key'=>'PDO::MYSQL_ATTR_SSL_KEY', 'ssl_cert'=>'PDO::MYSQL_ATTR_SSL_CERT',
            'ssl_ca'=>'PDO::MYSQL_ATTR_SSL_CA', 'ssl_capath'=>'PDO::MYSQL_ATTR_SSL_CAPATH',
            'ssl_cipher'=>'PDO::MYSQL_ATTR_SSL_CIPHER'] as $key=>$constant) {
            if (!empty($db[$key]) && defined($constant)) $options[constant($constant)] = $db[$key];
        }
        if (isset($db['ssl_verify']) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'))
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = (bool)$db['ssl_verify'];
    }
    $dsn = 'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['name'].';charset=utf8mb4';
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    $q = $pdo->prepare('SELECT endpoint, verify_tls, enabled FROM custom_integrations WHERE id=? AND provider=?');
    $q->execute([1, 'vmware_vcenter']);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['enabled'] !== 1) throw new RuntimeException();
    $p = parse_url($row['endpoint']);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) ||
        isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) ||
        !in_array($p['path'] ?? '', ['', '/'], true)) throw new RuntimeException();
    $host = trim($p['host'], '[]'); $port = (int)($p['port'] ?? 443);
    $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if ($port < 1 || $port > 65535 || (!$ip && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)))
        throw new RuntimeException();
    $authority = str_contains($host, ':') ? '['.$host.']' : $host;
    $url = 'https://'.$authority.':'.$port;
    echo implode("\n", [$url, $host, $port, (int)$row['verify_tls'], $ip ? 'ip' : 'dns'])."\n";
} catch (Throwable $e) {
    fwrite(STDERR, "STOP: Cannot safely read enabled VMware connector #1 metadata. No secrets printed.\n");
    exit(2);
}
PHP
)"
mapfile -t META <<< "$CONFIG"
[[ ${#META[@]} -eq 5 ]] || { echo 'STOP: Invalid metadata.'; exit 2; }
URL="${META[0]}"; HOST="${META[1]}"; PORT="${META[2]}"
printf 'CONNECTOR_ID=1\nVCENTER_URL=%s\nSAVED_VERIFY_TLS=%s\nUTC=%s\n' "$URL" "${META[3]}" "$(date -u +%FT%TZ)"
grep -E '^(ID|VERSION_ID)=' /etc/os-release || true
openssl version
umask 077
DIR="$(mktemp -d /var/tmp/phpipam-vcenter-tls.XXXXXXXX)"
printf 'TLS_REPORT_DIR=%s\n' "$DIR"
CONNECT="$HOST:$PORT"; SNI=(-servername "$HOST")
if [[ "${META[4]}" == ip ]]; then
    SNI=(-noservername)
    [[ "$HOST" != *:* ]] || CONNECT="[$HOST]:$PORT"
fi
# Observation only: a peer-supplied chain is NOT a trusted CA source.
# Discard session diagnostics; retain only PEM certificate blocks.
set +e
timeout 15s openssl s_client -connect "$CONNECT" "${SNI[@]}" -showcerts -no_ticket \
    </dev/null 2>/dev/null | awk '
    /^-----BEGIN CERTIFICATE-----$/ {p=1}
    p {print}
    /^-----END CERTIFICATE-----$/ {p=0}
    ' > "$DIR/peer-chain.pem"
CAPTURE=("${PIPESTATUS[@]}")
set -e
printf 'CERTIFICATE_CAPTURE_EXIT=%s\nPEER_CHAIN_IS_NOT_TRUSTED_AUTOMATICALLY=1\n' "${CAPTURE[0]}"
if [[ -s "$DIR/peer-chain.pem" ]]; then
    openssl x509 -in "$DIR/peer-chain.pem" -noout -nameopt RFC2253 \
        -subject -issuer -dates -fingerprint -sha256 -ext subjectAltName,basicConstraints
else
    echo 'PEER_CERTIFICATE=NOT_RECEIVED'
fi
# Use the app's web UID but CLI SAPI. No login, cookies, redirects or proxy.
runuser -u www-data -- env PHPIPAM_TLS_URL="$URL" php -d display_errors=0 -d log_errors=0 <<'PHP'
<?php
if (!extension_loaded('curl')) { echo "STOP: PHP cURL unavailable.\n"; exit(3); }
echo 'PHP_SAPI='.PHP_SAPI.'; UID='.(function_exists('posix_geteuid') ? posix_geteuid() : 'unknown')."\n";
echo 'PHP_CURL_TLS_BACKEND='.json_encode(curl_version()['ssl_version'])."\n";
foreach (['curl.cainfo', 'openssl.cafile', 'openssl.capath'] as $name)
    echo $name.'='.json_encode(ini_get($name))."\n";
foreach (['HTTPS'=>'/', 'SDK'=>'/sdk'] as $name=>$path) {
    $ch = curl_init(getenv('PHPIPAM_TLS_URL').$path);
    curl_setopt_array($ch, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_HEADER=>false, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>12,
        CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*',
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $ok = curl_exec($ch); $error = curl_errno($ch);
    $verify = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo $name.'_TLS_VERIFIED='.(($ok !== false && $error === 0 && $verify === 0 && $http > 0) ? '1' : '0')."\n";
    echo $name.'_CURL_ERROR='.$error.'; SSL_VERIFY_RESULT='.$verify.'; HTTP_STATUS='.$http."\n";
    if ($error) echo $name.'_ERROR_DETAIL='.json_encode(curl_error($ch), JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    curl_close($ch);
}
echo "AUTHENTICATED_WEB_SAPI_TEST=NOT_RUN\n";
PHP
printf 'APPLICATION_CHANGED=0\nCONNECTOR_CHANGED=0\nCA_IMPORTED=0\nINVENTORY_REQUESTS=0\n'
