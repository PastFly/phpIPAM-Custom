<?php

/**
 * phpIPAM Custom — Phase 5F direct HTTPS transport for vCenter Web Services.
 *
 * The production server exposes HTTP(S)_PROXY environment variables for generic
 * outbound traffic, while the internal vCenter is intentionally reached directly.
 * This narrowly-scoped transport makes that policy explicit for Phase 5F SOAP
 * calls so libcurl cannot silently inherit a proxy and turn a direct vCenter
 * request into an HTTP 403 from the proxy/WAF.
 *
 * No generic URL/method surface is exposed: HTTPS POST only, no redirects,
 * bounded response size/timeouts, and proxying explicitly disabled.
 */
class IntegrationVmwareVcenterDirectTransport
{
    private $maxResponseBytes;

    public function __construct($maxResponseBytes = 8388608)
    {
        $this->maxResponseBytes = max(16384, min(8388608, (int)$maxResponseBytes));
    }

    public function __invoke($method, $url, $headers, $body, $verifyTls, $connectTimeout, $requestTimeout)
    {
        $method = strtoupper(trim((string)$method));
        $url = trim((string)$url);
        $body = (string)$body;
        $verifyTls = (bool)$verifyTls;
        $connectTimeout = max(1, min(30, (int)$connectTimeout));
        $requestTimeout = max($connectTimeout, min(60, (int)$requestTimeout));

        if ($method !== 'POST') {
            throw new InvalidArgumentException('Phase 5F direct vCenter transport permits HTTPS POST only');
        }
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('Phase 5F direct vCenter transport requires HTTPS');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Invalid Phase 5F direct vCenter transport URL');
        }
        if (!empty($parts['fragment'])) {
            throw new InvalidArgumentException('Phase 5F direct vCenter transport URL must not contain a fragment');
        }
        if (!is_array($headers)) {
            throw new InvalidArgumentException('Phase 5F direct vCenter transport headers must be an array');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Phase 5F direct vCenter transport');
        }

        $ch = curl_init();
        if ($ch === false) throw new RuntimeException('Unable to initialize Phase 5F direct vCenter transport');

        $responseBody = '';
        $responseHeaders = array();
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_USERAGENT => 'phpIPAM-Custom-vSphereDirect/1.0',
            // Deliberately ignore inherited HTTP_PROXY / HTTPS_PROXY for the
            // configured internal vCenter endpoint. Transport diagnostic on the
            // live server proved proxied /sdk requests receive HTTP 403 while
            // the same direct requests receive HTTP 200 XML.
            CURLOPT_PROXY => '',
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $trim = trim($line);
                if ($trim !== '') $responseHeaders[] = $trim;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$tooLarge, $maxBytes) {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            }
        );
        if (defined('CURLOPT_NOPROXY')) $options[CURLOPT_NOPROXY] = '*';
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string)curl_error($ch));
        curl_close($ch);

        if ($tooLarge) throw new RuntimeException('Phase 5F direct vCenter response exceeded the safety limit');
        if ($ok === false) {
            throw new RuntimeException('Phase 5F direct vCenter network error: ' . ($error !== '' ? $error : 'request failed'));
        }

        return array(
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders
        );
    }
}
