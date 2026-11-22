<?php

declare(strict_types=1);

namespace NM\Services\SocialAuth\Social\Http;

use NM\Services\SocialAuth\Social\Contracts\HttpClientInterface;
use NM\Services\SocialAuth\Social\Exceptions\ProviderException;

/** Client HTTPS minimal : TLS vérifié, pas de redirection, délais courts, réponse bornée. */
final class CurlHttpClient implements HttpClientInterface
{
    private const MAX_BODY = 1048576;

    public function request(string $method, string $url, array $headers = [], ?array $form = null): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new ProviderException('URL non HTTPS refusée');
        }
        if (!function_exists('curl_init')) {
            return $this->requestWithStreams($method, $url, $headers, $form);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ProviderException('curl indisponible');
        }

        $lines = ['User-Agent: nm-framework-social-auth'];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => self::MAX_BODY,
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = $body === false;
        curl_close($ch);

        // Message volontairement sans URL ni corps : ils pourraient contenir des secrets.
        if ($failed) {
            throw new ProviderException('échec de transport HTTP');
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Repli pour les installations PHP sans l'extension cURL.
     *
     * @param array<string, string> $headers
     * @param array<string, string>|null $form
     * @return array{status: int, body: string}
     */
    private function requestWithStreams(string $method, string $url, array $headers, ?array $form): array
    {
        $lines = ['User-Agent: nm-framework-social-auth'];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }
        if ($form !== null) {
            $lines[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $lines),
                'content' => $form === null
                    ? ''
                    : http_build_query($form, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 10,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new ProviderException('échec de transport HTTP');
        }

        $body = @stream_get_contents($stream, self::MAX_BODY + 1);
        $metadata = stream_get_meta_data($stream);
        fclose($stream);

        if ($body === false || strlen($body) > self::MAX_BODY || !empty($metadata['timed_out'])) {
            throw new ProviderException('échec de transport HTTP');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}
