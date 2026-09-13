<?php
require_once __DIR__ . '/http.php';

/** Resend over HTTPS. PHP's mail() from shared hosting lands in spam. */
class Mailer {
    private $key, $from;
    public function __construct($key, $fromEmail, $fromName) {
        $this->key = $key;
        $this->from = $fromName ? "$fromName <$fromEmail>" : $fromEmail;
    }
    public function enabled() { return !empty($this->key); }

    public function send($to, $subject, $html) {
        if (!$this->enabled()) return ['skipped' => 'no resend key configured'];
        $d = Http::json('POST', 'https://api.resend.com/emails', [
            'Authorization' => 'Bearer ' . $this->key,
        ], ['from' => $this->from, 'to' => [$to], 'subject' => $subject, 'html' => $html], 30);
        return ['id' => $d['id'] ?? null];
    }
}
