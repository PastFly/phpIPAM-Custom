<?php

/**
 * phpIPAM Custom — Phase 5F.3.3 narrow direct/no-proxy REST transport.
 * Compatible with the existing Phase 5D topology transport callback signature.
 */
class IntegrationVmwareVcenterDirectRestTransport
{
    private $maxResponseBytes;

    public function __construct($maxResponseBytes = 8388608)
    {
        $this->maxResponseBytes = max(65536, min(8388608, (int)$maxResponseBytes));
    }

    public function __invoke($method, $url, $headers, $username, $password, $verifyTls, $connectTimeout, $requestTimeout)
    {
        $method = strtoupper(trim((string)$method));
        $url = trim((string)$url);
        if (!in_array($method, array('GET', 'POST', 'DELETE'), true)) throw new InvalidArgumentException('Phase 5F.3.3 REST transport method is not allowed');
        if (!preg_match('#^https://#i', $url)) throw new InvalidArgumentException('Phase 5F.3.3 REST transport requires HTTPS');
        if (!is_array($headers)) throw new InvalidArgumentException('Phase 5F.3.3 REST transport headers must be an array');
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Phase 5F.3.3 REST transport');

        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize Phase 5F.3.3 REST transport');
        $body = '';
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;
        $connectTimeout = max(1, min(30, (int)$connectTimeout));
        $requestTimeout = max($connectTimeout, min(60, (int)$requestTimeout));
        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vCenter-Search-REST/1.0',
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        if ($username !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = (string)$username . ':' . (string)$password;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $duration = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        $error = trim((string)curl_error($ch));
        curl_close($ch);
        if ($tooLarge) throw new RuntimeException('Phase 5F.3.3 REST response exceeded the safety limit');
        if ($ok === false) throw new RuntimeException('Phase 5F.3.3 REST network error: ' . ($error !== '' ? $error : 'request failed'));
        return array('status' => $status, 'body' => $body, 'error' => $error, 'duration_ms' => $duration);
    }
}
