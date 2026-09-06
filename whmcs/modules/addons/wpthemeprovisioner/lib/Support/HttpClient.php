<?php
namespace Wtp\Addon\Support;

use RuntimeException;

/**
 * The outbound HTTP client WHMCS uses to drive the installer and verify sites.
 *
 * Two things make it different from a plain curl call:
 *
 *  1. **IP pinning.** Right after `createacct` the customer's DNS may still
 *     point at their old host (or nowhere). Every request can therefore be
 *     pinned to the provisioning server's IP with CURLOPT_RESOLVE while still
 *     sending the correct Host header and SNI.
 *  2. **Bounded everything.** Response size, redirect count and time are all
 *     capped, because the far end is a machine we have just handed to a
 *     customer and must not be trusted to behave.
 */
class HttpClient
{
    // Not final: the integration suite substitutes a subclass so the whole
    // provisioning pipeline can be driven without a network. Everything below
    // request() is behaviour, not policy, so overriding that one method is
    // enough to replace the transport completely.

    public const MAX_RESPONSE_BYTES = 2097152; // 2 MiB — this client never downloads packages

    /** @var int */
    private $timeout;
    /** @var int */
    private $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = max(1, $timeout);
        $this->connectTimeout = max(1, $connectTimeout);
    }

    /**
     * @param array<string,mixed> $options
     *        headers   array<string,string>
     *        body      string|array
     *        json      array           (sets Content-Type and encodes)
     *        resolve   string          IP to pin the hostname to
     *        insecure  bool            skip TLS verification (only for a freshly
     *                                  created account whose certificate has not
     *                                  been issued yet — never for the API)
     *        timeout   int
     * @return array{status:int,body:string,headers:array<string,string>,error:string,error_code:string}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP curl extension is required');
        }

        $method = strtoupper($method);
        $headers = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
        $body = '';

        if (isset($options['json'])) {
            $body = \Wtp\Shared\Support\Json::encode($options['json']);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($options['body'])) {
            $body = is_array($options['body']) ? http_build_query($options['body']) : (string) $options['body'];
            if (is_array($options['body']) && !isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        }

        $ch = curl_init();
        $responseHeaders = [];
        $received = 0;
        $tooLarge = false;

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,   // redirects are handled explicitly
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? $this->timeout),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => empty($options['insecure']),
            CURLOPT_SSL_VERIFYHOST => empty($options['insecure']) ? 2 : 0,
            CURLOPT_USERAGENT => 'WHMCS-Theme-Provisioner/' . (defined('WTP_VERSION') ? WTP_VERSION : '1.0.0'),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ];

        // Collect the body ourselves so the size cap is authoritative: curl is
        // told to abort the transfer the moment the limit is crossed.
        $collected = '';
        $curlOptions[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (
            &$received,
            &$tooLarge,
            &$collected
        ): int {
            $received += strlen($chunk);
            if ($received > self::MAX_RESPONSE_BYTES) {
                $tooLarge = true;
                return 0; // aborts the transfer
            }
            $collected .= $chunk;
            return strlen($chunk);
        };

        if ($body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
            $headers['Content-Length'] = (string) strlen($body);
        }
        if ($headers !== []) {
            $formatted = [];
            foreach ($headers as $name => $value) {
                $formatted[] = $name . ': ' . $value;
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $formatted;
        }
        if (!empty($options['resolve'])) {
            $parsed = parse_url($url);
            $host = isset($parsed['host']) ? (string) $parsed['host'] : '';
            $port = isset($parsed['port'])
                ? (int) $parsed['port']
                : (isset($parsed['scheme']) && $parsed['scheme'] === 'https' ? 443 : 80);
            if ($host !== '') {
                $curlOptions[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $options['resolve']];
            }
        }

        curl_setopt_array($ch, $curlOptions);
        curl_exec($ch);

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            return [
                'status' => $status,
                'body' => '',
                'headers' => $responseHeaders,
                'error' => 'Response exceeded ' . self::MAX_RESPONSE_BYTES . ' bytes',
                'error_code' => 'response_too_large',
            ];
        }

        return [
            'status' => $status,
            'body' => $collected,
            'headers' => $responseHeaders,
            'error' => $errno !== 0 ? $error : '',
            'error_code' => $errno !== 0 ? self::curlErrorCode($errno) : '',
        ];
    }

    /** @param array<string,mixed> $options */
    public function get(string $url, array $options = []): array
    {
        return $this->request('GET', $url, $options);
    }

    /** @param array<string,mixed> $options */
    public function post(string $url, array $options = []): array
    {
        return $this->request('POST', $url, $options);
    }

    /** Map curl's numeric errors onto the codes the error classifier knows. */
    public static function curlErrorCode(int $errno): string
    {
        switch ($errno) {
            case CURLE_OPERATION_TIMEOUTED:
                return 'network_timeout';
            case CURLE_COULDNT_RESOLVE_HOST:
                return 'dns_not_resolved';
            case CURLE_COULDNT_CONNECT:
                return 'connection_refused';
            case CURLE_SSL_CONNECT_ERROR:
            case CURLE_SSL_PEER_CERTIFICATE:
            case CURLE_SSL_CACERT:
                return 'ssl_not_ready';
            default:
                return 'network_error';
        }
    }
}
