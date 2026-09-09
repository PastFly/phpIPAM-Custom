<?php

/**
 * phpIPAM Custom — Phase 5F vCenter transport diagnostic.
 *
 * READ ONLY. This tool deliberately does not decrypt or send the saved vCenter
 * credential. It probes the public WSDL and anonymous RetrieveServiceContent
 * operation with the inherited libcurl proxy policy and with proxying disabled,
 * so CLI-only proxy/WAF interference can be distinguished from a direct vCenter
 * /sdk denial.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: Phase 5F transport diagnostic is CLI-only.\n");
    exit(2);
}

$target = isset($argv[1]) ? rtrim((string)$argv[1], '/') : '';
$connectorId = null;
for ($i = 2; $i < count($argv); $i++) {
    $arg = (string)$argv[$i];
    if ($connectorId === null && preg_match('/^[1-9][0-9]*$/', $arg)) {
        $connectorId = (int)$arg;
        continue;
    }
    fwrite(STDERR, "ERROR: unknown or duplicate argument: {$arg}\n");
    exit(2);
}

if ($target === '' || !is_dir($target) || !is_file($target . '/config.php')) {
    fwrite(STDERR, "ERROR: valid phpIPAM target root is required.\n");
    exit(2);
}

function phase5f_diag_autoselect_connector(IntegrationVmwareVcenterConfigStore $Store)
{
    $enabled = array();
    foreach ($Store->list_configs() as $config) if (!empty($config['enabled'])) $enabled[] = $config;
    if (count($enabled) === 0) throw new RuntimeException('No enabled VMware connector');
    if (count($enabled) > 1) throw new RuntimeException('More than one enabled VMware connector; pass connector-id explicitly');
    $id = isset($enabled[0]['id']) ? $enabled[0]['id'] : null;
    if (filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1))) === false) {
        throw new RuntimeException('Enabled VMware connector has invalid id');
    }
    return (int)$id;
}

function phase5f_diag_proxy_flags()
{
    $names = array('http_proxy', 'https_proxy', 'all_proxy', 'no_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY');
    foreach ($names as $name) {
        $value = getenv($name);
        fwrite(STDERR, 'PHASE5F_DIAG_ENV_' . strtoupper($name) . '=' . (($value !== false && $value !== '') ? 'SET' : 'UNSET') . "\n");
    }
}

function phase5f_diag_body_kind($body)
{
    $body = ltrim((string)$body);
    if ($body === '') return 'empty';
    $lower = strtolower(substr($body, 0, 4096));
    if (strpos($lower, '<definitions') !== false || strpos($lower, ':definitions') !== false) return 'wsdl_xml';
    if (strpos($lower, '<soap') !== false || strpos($lower, ':envelope') !== false) return 'soap_xml';
    if (strpos($lower, '<!doctype html') !== false || strpos($lower, '<html') !== false) return 'html';
    if ($body[0] === '<') return 'xml_or_markup';
    if ($body[0] === '{' || $body[0] === '[') return 'json';
    return 'other';
}

function phase5f_diag_request($method, $url, $body, $verifyTls, $forceDirect)
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    $ch = curl_init();
    if ($ch === false) throw new RuntimeException('Unable to initialize cURL');

    $responseBody = '';
    $responseHeaders = array();
    $tooLarge = false;
    $headers = array('Accept: text/xml, application/xml;q=0.9, */*;q=0.1');
    if ($body !== null) $headers[] = 'Content-Type: text/xml; charset=utf-8';

    $options = array(
        CURLOPT_URL => (string)$url,
        CURLOPT_CUSTOMREQUEST => strtoupper((string)$method),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => (bool)$verifyTls,
        CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'phpIPAM-Custom-vSphereSOAP-TransportDiagnostic/1.0',
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
            $trim = trim($line);
            if ($trim !== '') $responseHeaders[] = $trim;
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody, &$tooLarge) {
            if (strlen($responseBody) + strlen($chunk) > 1048576) {
                $tooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        }
    );
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = (string)$body;
    if ($forceDirect) $options[CURLOPT_PROXY] = '';
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;

    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = trim((string)curl_error($ch));
    curl_close($ch);

    if ($tooLarge) throw new RuntimeException('Diagnostic response exceeded 1 MiB safety limit');
    if ($ok === false) {
        return array('status' => $status, 'kind' => 'network_error', 'content_type' => $contentType, 'error' => $error !== '' ? $error : 'request failed');
    }
    return array('status' => $status, 'kind' => phase5f_diag_body_kind($responseBody), 'content_type' => $contentType, 'error' => '');
}

function phase5f_diag_print_result($name, array $result)
{
    fwrite(STDERR, 'PHASE5F_DIAG_' . $name . '_HTTP=' . (int)$result['status'] . "\n");
    fwrite(STDERR, 'PHASE5F_DIAG_' . $name . '_BODY=' . $result['kind'] . "\n");
    if ($result['content_type'] !== '') {
        $safeType = preg_replace('/[^A-Za-z0-9_.+\-\/=; ]/', '', substr($result['content_type'], 0, 120));
        fwrite(STDERR, 'PHASE5F_DIAG_' . $name . '_CONTENT_TYPE=' . $safeType . "\n");
    }
    if ($result['error'] !== '') {
        fwrite(STDERR, 'PHASE5F_DIAG_' . $name . '_NETWORK_ERROR=' . preg_replace('/[\r\n]+/', ' ', substr($result['error'], 0, 240)) . "\n");
    }
}

$originalCwd = getcwd();
try {
    $_SERVER['DOCUMENT_ROOT'] = $target;
    chdir($target . '/functions');
    require_once($target . '/functions/functions.php');
    if ($originalCwd !== false) chdir($originalCwd);

    require_once($target . '/functions/classes/class.IntegrationVmwareVcenter.php');
    require_once($target . '/functions/classes/class.IntegrationSecretCipher.php');
    require_once($target . '/functions/classes/class.IntegrationVmwareVcenterConfigStore.php');

    $Database = new Database_PDO;
    $Cipher = IntegrationSecretCipher::from_keyring_file();
    $Store = new IntegrationVmwareVcenterConfigStore($Database, $Cipher);
    if ($connectorId === null) $connectorId = phase5f_diag_autoselect_connector($Store);
    $public = $Store->get_config($connectorId);
    if (empty($public['enabled'])) throw new RuntimeException('Selected VMware connector is disabled');

    $Policy = new IntegrationVmwareVcenter();
    $endpoint = $Policy->normalize_endpoint($public['endpoint']);
    $verifyTls = !empty($public['verify_tls']);

    fwrite(STDERR, "PHASE5F_DIAG_CONNECTOR_ID={$connectorId}\n");
    fwrite(STDERR, 'PHASE5F_DIAG_TLS=' . ($verifyTls ? 'VERIFY' : 'INSECURE') . "\n");
    fwrite(STDERR, "PHASE5F_DIAG_CREDENTIAL_SENT=0\n");
    fwrite(STDERR, "PHASE5F_DIAG_PERSISTENCE=0\n");
    fwrite(STDERR, "PHASE5F_DIAG_VCENTER_MUTATIONS=0\n");
    phase5f_diag_proxy_flags();

    $serviceEnvelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soapenv:Body><RetrieveServiceContent xmlns="urn:vim25">'
        . '<_this type="ServiceInstance">ServiceInstance</_this>'
        . '</RetrieveServiceContent></soapenv:Body></soapenv:Envelope>';

    $wsdlDefault = phase5f_diag_request('GET', $endpoint . '/sdk/vimService.wsdl', null, $verifyTls, false);
    $wsdlDirect = phase5f_diag_request('GET', $endpoint . '/sdk/vimService.wsdl', null, $verifyTls, true);
    $soapDefault = phase5f_diag_request('POST', $endpoint . '/sdk', $serviceEnvelope, $verifyTls, false);
    $soapDirect = phase5f_diag_request('POST', $endpoint . '/sdk', $serviceEnvelope, $verifyTls, true);

    phase5f_diag_print_result('WSDL_DEFAULT', $wsdlDefault);
    phase5f_diag_print_result('WSDL_DIRECT', $wsdlDirect);
    phase5f_diag_print_result('SOAP_DEFAULT', $soapDefault);
    phase5f_diag_print_result('SOAP_DIRECT', $soapDirect);

    $defaultDenied = !in_array((int)$soapDefault['status'], array(200, 201), true);
    $directSoapOk = in_array((int)$soapDirect['status'], array(200, 201), true) && $soapDirect['kind'] === 'soap_xml';
    $directWsdlOk = (int)$wsdlDirect['status'] === 200 && $wsdlDirect['kind'] === 'wsdl_xml';

    if ($defaultDenied && $directSoapOk) {
        $classification = 'PROXY_INTERFERENCE_CONFIRMED';
    } elseif ($directSoapOk) {
        $classification = 'SOAP_TRANSPORT_REACHABLE';
    } elseif ($directWsdlOk && (int)$soapDirect['status'] === 403) {
        $classification = 'SOAP_POST_POLICY_OR_HEADER_BLOCK';
    } elseif ((int)$wsdlDirect['status'] === 403 && (int)$soapDirect['status'] === 403) {
        $classification = 'DIRECT_VCENTER_403';
    } else {
        $classification = 'INCONCLUSIVE';
    }

    fwrite(STDERR, 'PHASE5F_DIAG_RESULT=' . $classification . "\n");
} catch (Exception $e) {
    fwrite(STDERR, "PHASE5F_DIAG_RESULT=FAILED\n");
    fwrite(STDERR, 'ERROR_CATEGORY=' . get_class($e) . "\n");
    fwrite(STDERR, 'ERROR_MESSAGE=' . preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 500)) . "\n");
    exit(1);
} finally {
    if ($originalCwd !== false) @chdir($originalCwd);
}
