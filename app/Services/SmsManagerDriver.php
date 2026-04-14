<?php

namespace App\Services;

/**
 * SMS driver for SmsManager JSON API v2 (https://api.smsmngr.com/v2).
 *
 * Driver ID in sms_messages.driver = 5 (Sms::SMSMANAGER)
 * Auth: x-api-key header (sms_password5 config value)
 */
class SmsManagerDriver
{
    const DRIVER_ID = 5;

    private string  $apiKey;
    private string  $baseUrl;
    private ?string $lastError  = null;
    private ?string $lastStatus = null;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.smsmngr.com/v2')
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function send(string $sender, string $recipient, string $message): bool
    {
        $recipient = preg_replace('/\D+/', '', $recipient);

        if ($recipient === '') {
            $this->lastError = 'Wrong phone number of receiver';
            return false;
        }

        $payload = [
            'body' => $message,
            'to'   => [['phone_number' => $recipient]],
            'tag'  => 'transactional',
        ];

        $response = $this->apiPost('/message', $payload);

        if ($response === null) {
            return false;
        }

        $accepted = count($response['accepted'] ?? []);
        $rejected = count($response['rejected'] ?? []);

        if ($accepted > 0) {
            $requestId = $response['request_id'] ?? '';
            $this->lastError  = null;
            $this->lastStatus = 'Accepted' . ($requestId ? ", request_id: $requestId" : '')
                . ", accepted: $accepted, rejected: $rejected";
            return true;
        }

        $this->lastStatus = null;
        $this->lastError  = $this->extractError($response);
        return false;
    }

    public function getError(): ?string  { return $this->lastError; }
    public function getStatus(): ?string { return $this->lastStatus; }

    private function apiPost(string $path, array $payload): ?array
    {
        $json = json_encode($payload);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-api-key: ' . $this->apiKey,
            ],
        ]);

        $out       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            $this->lastError = 'cURL error: ' . $curlError;
            return null;
        }

        $decoded = json_decode($out, true);

        if (!is_array($decoded)) {
            $this->lastError = "Invalid JSON response (HTTP $httpCode)";
            return null;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        }

        $this->lastError = $this->extractError($decoded) ?: "HTTP $httpCode";
        return null;
    }

    private function extractError(array $response): string
    {
        if (isset($response['message']) && is_string($response['message']) && $response['message'] !== '') {
            return $response['message'];
        }
        if (isset($response['error']) && is_string($response['error']) && $response['error'] !== '') {
            return $response['error'];
        }
        $errors = $response['errors'] ?? [];
        if (is_array($errors) && count($errors)) {
            $first = reset($errors);
            if (is_string($first))  return $first;
            if (is_array($first))   return $first['message'] ?? $first['error'] ?? 'Unknown error';
        }
        $rejected = $response['rejected'] ?? [];
        if (is_array($rejected) && count($rejected)) {
            $first = reset($rejected);
            if (is_array($first)) return $first['reason'] ?? $first['message'] ?? 'Message rejected';
            return 'Message rejected';
        }
        return 'Unknown API error';
    }
}
