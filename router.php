<?php
/**
 * Router for PHP's built-in web server (see start-mock.bat):
 *
 *   GET  /TipMobileWS/Service1.svc?wsdl        → the mock WSDL
 *   GET  /TipMobileWS/Service1.svc?singleWsdl  → same
 *   POST /TipMobileWS/Service1.svc             → SOAP call (handled by MockService1)
 *   GET  /                                     → status page
 *
 * Every SOAP call is logged to logs/mock-YYYYMMDD.log: decoded request params,
 * the data the mock returned, and the raw request/response XML.
 * Created: 2026-10-12. Last modified: 2026-10-12 (readable log format).
 */

require __DIR__ . '/src/MockService1.php';
require __DIR__ . '/src/CallRecorder.php';

const SERVICE_PATH = '/TipMobileWS/Service1.svc';
const WSDL_FILE = __DIR__ . '/wsdl/TipMobileWS-Service1.wsdl';

ini_set('soap.wsdl_cache_enabled', '0');
date_default_timezone_set('Asia/Bangkok'); // same clock as TipFlashClaim's logs

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$query = $_SERVER['QUERY_STRING'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$serviceUrl = 'http://' . $host . SERVICE_PATH;

if (strcasecmp($path, SERVICE_PATH) === 0) {
    if ($method === 'GET' && preg_match('/^(wsdl|singlewsdl)\b/i', $query)) {
        header('Content-Type: text/xml; charset=utf-8');
        echo str_replace('__LOCATION__', $serviceUrl, file_get_contents(WSDL_FILE));

        return true;
    }

    if ($method === 'POST') {
        $request = file_get_contents('php://input');
        $started = microtime(true);

        $server = new SoapServer(WSDL_FILE, [
            'cache_wsdl' => WSDL_CACHE_NONE,
            'encoding' => 'UTF-8',
        ]);
        $recorder = new CallRecorder(new MockService1(__DIR__ . '/data'));
        $server->setObject($recorder);

        ob_start();
        $server->handle($request);
        $response = ob_get_clean();

        writeLog($recorder, $request, $response, microtime(true) - $started);
        echo $response;

        return true;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "MOCK Service1 is running.\nWSDL: {$serviceUrl}?wsdl\n";

    return true;
}

if ($path === '/') {
    header('Content-Type: text/plain; charset=utf-8');
    $ops = implode("\n  - ", array_map(function ($f) {
        return preg_replace('/^\S+\s+(\w+)\(.*$/', '$1', $f);
    }, (new SoapClient(WSDL_FILE, ['cache_wsdl' => WSDL_CACHE_NONE]))->__getFunctions()));
    echo "TIP MOCK SOAP server (for local testing only — NOT the real Dhipaya service)\n\n";
    echo "Service1 WSDL : {$serviceUrl}?wsdl\n";
    echo "Operations    :\n  - {$ops}\n\n";
    echo "Logs          : " . __DIR__ . DIRECTORY_SEPARATOR . "logs\n";

    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found: {$path}\n";

return true;

function writeLog(CallRecorder $call, string $request, string $response, float $seconds): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $operation = $call->operation;
    if ($operation === null && preg_match('/<(?:\w+:)?Body[^>]*>\s*<(?:\w+:)?(\w+)/', $request, $m)) {
        $operation = $m[1]; // request never reached the handler (e.g. bad XML)
    }
    $isFault = $call->error !== null || strpos($response, 'Fault>') !== false;
    $status = $isFault ? 'FAULT' : 'OK';

    $lines = [
        sprintf('==================== %s | %s | %s | %.0f ms ====================',
            date('Y-m-d H:i:s'), $operation ?? '?', $status, $seconds * 1000),
        'From       : ' . ($_SERVER['REMOTE_ADDR'] ?? '?'),
        'SOAPAction : ' . ($_SERVER['HTTP_SOAPACTION'] ?? '-'),
        '',
        '--- 1) Request params received (decoded) ---',
        prettyJson(toArray($call->params)),
        '',
    ];
    if ($call->error !== null) {
        $lines[] = '--- 2) Response: SOAP Fault ---';
        $lines[] = get_class($call->error) . ': ' . $call->error->getMessage();
    } else {
        $result = toArray($call->result);
        $lines[] = '--- 2) Response data returned by the mock ---';
        $lines[] = prettyJson($result);
        foreach ((array)$result as $key => $value) {
            if (is_string($value) && is_array($json = json_decode($value, true))) {
                $lines[] = '';
                $lines[] = "--- 2b) {$key} is a JSON string — after json_decode() ---";
                $lines[] = prettyJson($json);
            }
        }
    }
    $lines[] = '';
    $lines[] = '--- 3) Raw request XML ---';
    $lines[] = prettyXml($request);
    $lines[] = '';
    $lines[] = '--- 4) Raw response XML ---';
    $lines[] = prettyXml($response);

    @file_put_contents($dir . '/mock-' . date('Ymd') . '.log', implode("\n", $lines) . "\n\n", FILE_APPEND);
    // Also echo a one-liner to the console window running `php -S`.
    error_log(sprintf('SOAP %s %s (%.0f ms)', $operation ?? '?', $status, $seconds * 1000));
}

function toArray($data)
{
    return json_decode(json_encode($data), true);
}

function prettyJson($data): string
{
    return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

function prettyXml(string $xml): string
{
    if ($xml === '') {
        return '(empty)';
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (@$dom->loadXML($xml)) {
        return trim($dom->saveXML($dom->documentElement));
    }

    return $xml;
}
