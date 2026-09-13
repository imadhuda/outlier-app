<?php
/* Small HTTP client. Every outbound call in the app goes through here so that
   timeouts, retries and error shapes are consistent and testable. */

class HttpError extends RuntimeException {
    public $status; public $body; public $retryable;
    public function __construct($msg, $status = 0, $body = '', $retryable = false) {
        parent::__construct($msg); $this->status = $status; $this->body = $body; $this->retryable = $retryable;
    }
}

class Http {
    /** Swappable for tests: set to a callable(method,url,headers,body) => [status, text]. */
    public static $transport = null;

    public static function request($method, $url, array $headers = [], $body = null, $timeout = 45) {
        if (self::$transport) {
            [$status, $text] = call_user_func(self::$transport, $method, $url, $headers, $body);
        } else {
            $ch = curl_init($url);
            $h = [];
            foreach ($headers as $k => $v) $h[] = "$k: $v";
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => $h,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Outlier/1.0',
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
            $text = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($status === 0) throw new HttpError('Network failure: ' . ($err ?: 'no response'), 0, '', true);
        }

        if ($status >= 200 && $status < 300) return $text;

        /* 408/429/5xx are worth another go; 4xx is our own mistake and never is. */
        $retryable = ($status === 408 || $status === 429 || $status >= 500);
        throw new HttpError("HTTP $status", $status, (string)$text, $retryable);
    }

    public static function json($method, $url, array $headers = [], $body = null, $timeout = 45) {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        $raw = self::request($method, $url, $headers, $body, $timeout);
        $d = json_decode((string)$raw, true);
        if ($d === null && trim((string)$raw) !== 'null') {
            throw new HttpError('Response was not valid JSON', 200, substr((string)$raw, 0, 300));
        }
        return $d;
    }
}
