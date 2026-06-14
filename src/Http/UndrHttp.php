<?php
declare(strict_types=1);

namespace Undr\Core\Http;

// ---------------------------------------------------------------------------
// Tiny dependency-free HTTP GET client (PHP streams). No Composer/cURL needed.
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
}
