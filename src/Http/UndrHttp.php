<?php
declare(strict_types=1);

namespace Undr\Core\Http;

// ---------------------------------------------------------------------------
// Tiny dependency-free HTTP GET client. Uses cURL when the extension is
// loaded — cURL races IPv4/IPv6 connects (Happy Eyeballs), so a host with a
// black-holed IPv6 route costs ~200ms instead of hanging into the timeout,
// which is exactly what the PHP-streams fallback does. No Composer deps.
// Part of the reusable UNDR sync module — brand-agnostic.
// ---------------------------------------------------------------------------

final class UndrResponse
{
    public function __construct(
        public int $status,            // 0 = transport failure (timeout/DNS/connection)
        public string $body,           // '' on failure or 304
        public ?string $etag,
        public ?string $lastModified
    ) {}

    public function ok(): bool { return $this->status >= 200 && $this->status < 300; }
    public function notModified(): bool { return $this->status === 304; }
    public function transportError(): bool { return $this->status === 0; }
    public function serverError(): bool { return $this->status >= 500; }
}

final class UndrHttp
{
    /** @param string[] $defaultHeaders extra headers sent on every request (e.g. Authorization) */
    public function __construct(
        private int $timeout = 8,
        private int $retries = 2,
        private array $defaultHeaders = []
    ) {}

    /**
     * Conditional GET. $conditional = ['etag' => ?string, 'lastModified' => ?string].
     * Retries transport errors and 5xx with exponential backoff; never retries 4xx/304.
     */
    public function get(string $url, array $conditional = []): UndrResponse
    {
        $attempt = 0;
        while (true) {
            $res = $this->once($url, $conditional);
            $retryable = $res->transportError() || $res->serverError();
            if (!$retryable || $attempt >= $this->retries) {
                return $res;
            }
            // backoff: 200ms, 400ms, 800ms … (deterministic; no Math.random())
            usleep(200000 * (1 << $attempt));
            $attempt++;
        }
    }

    private function once(string $url, array $conditional): UndrResponse
    {
        $headers = array_merge([
            'Accept: application/json',
            'User-Agent: UndrSync/1',
        ], $this->defaultHeaders);
        if (!empty($conditional['etag']))         $headers[] = 'If-None-Match: ' . $conditional['etag'];
        if (!empty($conditional['lastModified'])) $headers[] = 'If-Modified-Since: ' . $conditional['lastModified'];

        if (function_exists('curl_init')) {
            return $this->viaCurl($url, $headers);
        }

        $ctx = stream_context_create(['http' => [
            'method'         => 'GET',
            'header'         => implode("\r\n", $headers),
            'timeout'        => $this->timeout,
            'follow_location'=> 1,
            'max_redirects'  => 3,
            'ignore_errors'  => true, // read body on 4xx/5xx instead of returning false
        ]]);

        $body = @file_get_contents($url, false, $ctx);
        $responseHeaders = $http_response_header ?? [];

        if ($body === false && !$responseHeaders) {
            return new UndrResponse(0, '', null, null); // transport failure
        }

        $status = 0;
        $etag = $lastModified = null;
        foreach ($responseHeaders as $i => $h) {
            if ($i === 0 && preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int) $m[1];
            elseif (stripos($h, 'ETag:') === 0)           $etag = trim(substr($h, 5));
            elseif (stripos($h, 'Last-Modified:') === 0)   $lastModified = trim(substr($h, 14));
        }

        return new UndrResponse($status, $status === 304 ? '' : (string) $body, $etag, $lastModified);
    }

    /** cURL transport. Response headers are captured per block so a redirect's
     *  ETag/Last-Modified never leaks into the final response's values. */
    private function viaCurl(string $url, array $headers): UndrResponse
    {
        $etag = $lastModified = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$etag, &$lastModified): int {
                if (preg_match('~^HTTP/~i', $line))              $etag = $lastModified = null; // new block (redirect)
                elseif (stripos($line, 'ETag:') === 0)           $etag = trim(substr($line, 5));
                elseif (stripos($line, 'Last-Modified:') === 0)  $lastModified = trim(substr($line, 14));
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 0) {
            return new UndrResponse(0, '', null, null); // transport failure
        }
        return new UndrResponse($status, $status === 304 ? '' : (string) $body, $etag, $lastModified);
    }
}
