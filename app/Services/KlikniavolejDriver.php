<?php

namespace App\Services;

/**
 * SMS driver for KlikniaVolej.cz gateway.
 *
 * Driver ID in sms_messages.driver = 3 (Sms::KLIKNIAVOLEJ)
 * Auth: user + HMAC hash (sha1(user:id:sha1(password)))
 * Hostname: sms_hostname3 config (default: api.klikniavolej.cz:80)
 */
class KlikniavolejDriver
{
    const DRIVER_ID       = 3;
    const DEFAULT_HOSTNAME = 'api.klikniavolej.cz:80';

    private string  $user;
    private string  $password;
    private string  $hostname;
    private bool    $testMode;
    private ?string $lastError  = null;
    private ?string $lastStatus = null;

    public function __construct(
        string $user,
        string $password,
        string $hostname = self::DEFAULT_HOSTNAME,
        bool   $testMode = false
    ) {
        $this->user     = $user;
        $this->password = $password;
        $this->hostname = $hostname ?: self::DEFAULT_HOSTNAME;
        $this->testMode = $testMode;
    }

    public function send(string $sender, string $recipient, string $message): bool
    {
        $lastId = $this->getLastId();
        if ($lastId === null) {
            return false;
        }

        $newId = $lastId + 1;
        $vars  = [
            'user'     => $this->user,
            'number'   => $recipient,
            'sender'   => $sender,
            'text'     => $message,
            'encoding' => 'ascii',
            'test'     => $this->testMode ? 1 : 0,
            'id'       => $newId,
            'hash'     => sha1($this->user . ':' . $newId . ':' . sha1($this->password)),
            'flash'    => 0,
        ];

        $out = $this->post('http://' . $this->hostname . '/smsgateway.pl', $vars);
        if ($out === null) {
            return false;
        }

        $parts = explode(';', $out);
        if (($parts[0] ?? '') === 'OK') {
            $this->lastError  = null;
            $this->lastStatus = "SMS odeslána, počet částí: " . ($parts[2] ?? '?')
                . ", cena: " . ($parts[3] ?? '?') . " Kč";
            return true;
        }

        $this->lastStatus = null;
        $this->lastError  = $this->decodeError($parts[1] ?? '');
        return false;
    }

    public function getError(): ?string  { return $this->lastError; }
    public function getStatus(): ?string { return $this->lastStatus; }

    private function getLastId(): ?int
    {
        $id   = 't' . time();
        $vars = [
            'user' => $this->user,
            'id'   => $id,
            'hash' => sha1($this->user . ':' . $id . ':' . sha1($this->password)),
        ];

        $out = $this->post('http://' . $this->hostname . '/smsmaxid.pl', $vars);
        if ($out === null) {
            return null;
        }

        $parts = explode(';', $out);
        if (($parts[0] ?? '') === 'OK') {
            return (int) ($parts[2] ?? 0);
        }

        $this->lastError = $this->decodeError($parts[1] ?? '');
        return null;
    }

    private function post(string $url, array $data): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
        ]);
        $out       = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            $this->lastError = 'cURL error: ' . $curlError;
            return null;
        }

        return (string) $out;
    }

    private function decodeError(string $code): string
    {
        return match ($code) {
            '00'    => 'SMS message sent',
            '01'    => 'Wrong gateway connection informations',
            '02'    => 'Wrong phone number or identification of user',
            '03'    => 'Wrong phone number of receiver',
            '04'    => 'Wrong or missing arguments',
            '05'    => 'Text of message is too long',
            '06'    => 'Lack of credit',
            '07'    => 'Cannot send message to receiver',
            '08'    => 'An error occurred during sending',
            '09'    => 'Param id with same value was used for another message before',
            default => 'Unknown error: ' . $code,
        };
    }
}
