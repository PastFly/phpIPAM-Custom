<?php

/**
 * phpIPAM Custom — VMware vCenter read-only connector client.
 *
 * Phase 5A is intentionally transient: this class performs authentication,
 * one read-only inventory request and best-effort session cleanup. It does not
 * persist endpoint, credentials, tokens or inventory data.
 */
class IntegrationVmwareVcenter
{
    private $transport;
    private $connectTimeout;
    private $requestTimeout;
    private $maxResponseBytes;

    public function __construct($transport = null, $connectTimeout = 8, $requestTimeout = 20, $maxResponseBytes = 2097152)
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new InvalidArgumentException("VMware transport must be callable");
        }

        $this->transport = $transport;
        $this->connectTimeout = max(1, (int)$connectTimeout);
        $this->requestTimeout = max($this->connectTimeout, (int)$requestTimeout);
        $this->maxResponseBytes = max(4096, (int)$maxResponseBytes);
    }

    public function normalize_endpoint($endpoint)
    {
        $endpoint = trim((string)$endpoint);
        if ($endpoint === "") {
            throw new InvalidArgumentException("vCenter endpoint is required");
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint)) {
            $endpoint = "https://" . $endpoint;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) {
            throw new InvalidArgumentException("Invalid vCenter endpoint");
        }

        if (strtolower($parts["scheme"]) !== "https") {
            throw new InvalidArgumentException("vCenter endpoint must use HTTPS");
        }

        if (isset($parts["user"]) || isset($parts["pass"])) {
            throw new InvalidArgumentException("Credentials must not be embedded in the vCenter URL");
        }

        if (!empty($parts["query"]) || !empty($parts["fragment"])) {
            throw new InvalidArgumentException("vCenter endpoint must not contain query or fragment data");
        }

        $path = isset($parts["path"]) ? trim((string)$parts["path"]) : "";
        if ($path !== "" && $path !== "/") {
            throw new InvalidArgumentException("Use the vCenter base URL without /ui or another path");
        }

        $host = strtolower(trim((string)$parts["host"], "[]"));
        if ($host === "localhost" || $host === "localhost.localdomain") {
            throw new InvalidArgumentException("localhost is not an allowed vCenter target");
        }
        if ($this->is_blocked_literal_ip($host)) {
            throw new InvalidArgumentException("Loopback, unspecified and link-local vCenter targets are not allowed");
        }

        $port = isset($parts["port"]) ? (int)$parts["port"] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("Invalid vCenter TCP port");
        }

        $hostForUrl = strpos($host, ":") !== false ? "[" . $host . "]" : $host;
        return "https://" . $hostForUrl . ($port !== null ? ":" . $port : "");
    }

    public function test_connection($endpoint, $username, $password, $verifyTls = true)
    {
        $endpoint = $this->normalize_endpoint($endpoint);
        $username = trim((string)$username);
        $password = (string)$password;
        $verifyTls = (bool)$verifyTls;

        if ($username === "") {
            throw new InvalidArgumentException("vCenter username is required");
        }
        if ($password === "") {
            throw new InvalidArgumentException("vCenter password is required");
        }

        $started = microtime(true);
        $session = null;
        $apiFamily = null;
        $authStatus = 0;
        $inventoryStatus = 0;
        $datacenterCount = 0;

        try {
            $login = $this->request("POST", $endpoint . "/api/session", array("Accept: application/json"), $username, $password, $verifyTls);
            $authStatus = $login["status"];

            if ($authStatus === 200 || $authStatus === 201) {
                $session = $this->extract_session_token($login["body"]);
                $apiFamily = "modern";
            } elseif ($authStatus === 404 || $authStatus === 405) {
                $login = $this->request("POST", $endpoint . "/rest/com/vmware/cis/session", array("Accept: application/json"), $username, $password, $verifyTls);
                $authStatus = $login["status"];
                if ($authStatus !== 200 && $authStatus !== 201) {
                    throw new RuntimeException($this->http_failure_message("authentication", $authStatus));
                }
                $session = $this->extract_session_token($login["body"]);
                $apiFamily = "legacy";
            } else {
                throw new RuntimeException($this->http_failure_message("authentication", $authStatus));
            }

            if ($session === "") {
                throw new RuntimeException("vCenter authentication returned an empty session token");
            }

            $headers = array(
                "Accept: application/json",
                "vmware-api-session-id: " . $session
            );

            if ($apiFamily === "modern") {
                $inventory = $this->request("GET", $endpoint . "/api/vcenter/datacenter", $headers, null, null, $verifyTls);
            } else {
                $inventory = $this->request("GET", $endpoint . "/rest/vcenter/datacenter", $headers, null, null, $verifyTls);
            }

            $inventoryStatus = $inventory["status"];
            if ($inventoryStatus !== 200) {
                throw new RuntimeException($this->http_failure_message("inventory", $inventoryStatus));
            }

            $datacenterCount = $this->extract_datacenter_count($inventory["body"], $apiFamily);
        } finally {
            if (is_string($session) && $session !== "" && is_string($apiFamily)) {
                $headers = array(
                    "Accept: application/json",
                    "vmware-api-session-id: " . $session
                );
                try {
                    if ($apiFamily === "modern") {
                        $this->request("DELETE", $endpoint . "/api/session", $headers, null, null, $verifyTls);
                    } else {
                        $this->request("DELETE", $endpoint . "/rest/com/vmware/cis/session", $headers, null, null, $verifyTls);
                    }
                } catch (Exception $ignored) {
                    // Session cleanup is best-effort and must never hide the primary result.
                }
            }
        }

        return array(
            "ok" => true,
            "endpoint" => $endpoint,
            "api_family" => $apiFamily,
            "auth_http_status" => $authStatus,
            "inventory_http_status" => $inventoryStatus,
            "datacenter_count" => $datacenterCount,
            "verify_tls" => $verifyTls,
            "duration_ms" => (int)round((microtime(true) - $started) * 1000)
        );
    }

    private function request($method, $url, $headers, $username, $password, $verifyTls)
    {
        if ($this->transport !== null) {
            $response = call_user_func(
                $this->transport,
                strtoupper((string)$method),
                (string)$url,
                (array)$headers,
                $username,
                $password,
                (bool)$verifyTls,
                $this->connectTimeout,
                $this->requestTimeout
            );
            return $this->normalize_transport_response($response);
        }

        return $this->curl_request($method, $url, $headers, $username, $password, $verifyTls);
    }

    private function normalize_transport_response($response)
    {
        if (!is_array($response)) {
            throw new RuntimeException("VMware transport returned an invalid response");
        }

        $status = isset($response["status"]) ? (int)$response["status"] : 0;
        $body = isset($response["body"]) ? (string)$response["body"] : "";
        $error = isset($response["error"]) ? trim((string)$response["error"]) : "";
        $duration = isset($response["duration_ms"]) ? max(0, (int)$response["duration_ms"]) : 0;

        if ($status === 0 && $error !== "") {
            throw new RuntimeException("vCenter network error: " . $error);
        }

        return array(
            "status" => $status,
            "body" => $body,
            "error" => $error,
            "duration_ms" => $duration
        );
    }

    private function curl_request($method, $url, $headers, $username, $password, $verifyTls)
    {
        if (!function_exists("curl_init")) {
            throw new RuntimeException("PHP cURL extension is required for the VMware vCenter connector");
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException("Unable to initialize cURL");
        }

        $body = "";
        $tooLarge = false;
        $maxBytes = $this->maxResponseBytes;

        $options = array(
            CURLOPT_URL => (string)$url,
            CURLOPT_CUSTOMREQUEST => strtoupper((string)$method),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => (array)$headers,
            CURLOPT_USERAGENT => "phpIPAM-Custom-vCenter/1.0",
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }
        );

        if (defined("CURLOPT_PROTOCOLS") && defined("CURLPROTO_HTTPS")) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        if (defined("CURLOPT_REDIR_PROTOCOLS") && defined("CURLPROTO_HTTPS")) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($username !== null) {
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = (string)$username . ":" . (string)$password;
        }

        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $duration = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
        $error = trim((string)curl_error($ch));
        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException("vCenter response exceeded the connector safety limit");
        }
        if ($ok === false) {
            throw new RuntimeException("vCenter network error: " . ($error !== "" ? $error : "request failed"));
        }

        return array(
            "status" => $status,
            "body" => $body,
            "error" => $error,
            "duration_ms" => $duration
        );
    }

    private function extract_session_token($body)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("vCenter session response is not valid JSON");
        }

        if (is_string($decoded)) {
            $token = $decoded;
        } elseif (is_array($decoded) && isset($decoded["value"]) && is_string($decoded["value"])) {
            $token = $decoded["value"];
        } else {
            throw new RuntimeException("vCenter session response did not contain a session token");
        }

        $token = trim($token);
        if (strlen($token) > 4096) {
            throw new RuntimeException("vCenter session token exceeded the connector safety limit");
        }
        return $token;
    }

    private function extract_datacenter_count($body, $apiFamily)
    {
        $decoded = json_decode((string)$body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("vCenter Datacenter response is not valid JSON");
        }

        if ($apiFamily === "legacy") {
            if (!is_array($decoded) || !array_key_exists("value", $decoded) || !is_array($decoded["value"])) {
                throw new RuntimeException("Unexpected legacy vCenter Datacenter response");
            }
            return count($decoded["value"]);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Unexpected vCenter Datacenter response");
        }
        return count($decoded);
    }

    private function http_failure_message($phase, $status)
    {
        $status = (int)$status;
        if ($phase === "authentication") {
            if ($status === 401) {
                return "vCenter authentication failed (HTTP 401)";
            }
            if ($status === 403) {
                return "vCenter authentication was forbidden (HTTP 403)";
            }
            return "vCenter authentication failed (HTTP " . $status . ")";
        }

        if ($status === 401) {
            return "vCenter session was not accepted for inventory access (HTTP 401)";
        }
        if ($status === 403) {
            return "vCenter account lacks permission to read Datacenter inventory (HTTP 403)";
        }
        return "vCenter Datacenter inventory request failed (HTTP " . $status . ")";
    }

    private function is_blocked_literal_ip($host)
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        $packed = @inet_pton($host);
        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 4) {
            $a = ord($packed[0]);
            $b = ord($packed[1]);
            if ($a === 0 || $a === 127) {
                return true;
            }
            if ($a === 169 && $b === 254) {
                return true;
            }
            return false;
        }

        if (strlen($packed) === 16) {
            if ($packed === str_repeat("\0", 16)) {
                return true;
            }
            if ($packed === str_repeat("\0", 15) . "\1") {
                return true;
            }
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            if ($first === 0xfe && ($second & 0xc0) === 0x80) {
                return true;
            }
        }

        return false;
    }
}
