<?php

namespace App\Services;

class FioApiService
{
    const BASE_URL = 'https://fioapi.fio.cz/v1/rest';

    public function fetchLast(string $token): string
    {
        $url = self::BASE_URL . '/last/' . $token . '/transactions.csv';
        return $this->fetch($url);
    }

    public function fetchPeriod(string $token, string $dateFrom, string $dateTo): string
    {
        $url = self::BASE_URL . '/periods/' . $token . '/' . $dateFrom . '/' . $dateTo . '/transactions.csv';
        return $this->fetch($url);
    }

    /**
     * Set FIO bookmark to a specific transaction ID.
     * Kohana uses this before auto-download: sets bookmark to last known transaction_code from DB.
     * Next fetchLast() will return only transactions after this ID.
     */
    public function setLastId(string $token, int $transactionId): void
    {
        $url = self::BASE_URL . '/set-last-id/' . $token . '/' . $transactionId . '/';
        $this->fetch($url);
    }

    /**
     * Set FIO bookmark to a specific date.
     * Next fetchLast() will return transactions from this date onward.
     */
    public function setLastDate(string $token, string $date): void
    {
        $url = self::BASE_URL . '/set-last-date/' . $token . '/' . $date . '/';
        $this->fetch($url);
    }

    /**
     * POST XML payment order to FIO import endpoint.
     * Returns the raw API response body.
     */
    public function importPayments(string $token, string $xmlContent): string
    {
        $url     = self::BASE_URL . '/import/';
        $tmpFile = tempnam(sys_get_temp_dir(), 'fio_');
        file_put_contents($tmpFile, $xmlContent);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FreenetIS/Laravel',
            CURLOPT_POSTFIELDS     => [
                'token' => $token,
                'type'  => 'xml',
                'file'  => new \CURLFile($tmpFile, 'text/xml', 'payment.xml'),
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        unlink($tmpFile);

        if ($error) {
            throw new \RuntimeException('FIO API curl error: ' . $error);
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException('FIO API HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500));
        }

        return (string) $response;
    }

    private function fetch(string $url): string
    {
        $lastTimeoutError = '';

        // FIO občas (rate-limit / zátěž) drží spojení ~30 s a teprve pak odpoví,
        // takže krátký timeout tu odpověď utne těsně před cílem. Delší TIMEOUT dá
        // pomalé odpovědi prostor; CONNECTTIMEOUT přitom drží rychlé selhání při
        // reálném výpadku sítě. Při timeoutu jeden retry — s odstupem >30 s kvůli
        // limitu FIO (1 požadavek / 30 s / token).
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'FreenetIS/Laravel',
                CURLOPT_ENCODING       => '',
            ]);
            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno === CURLE_OPERATION_TIMEDOUT && $attempt < 2) {
                $lastTimeoutError = $error;
                sleep(35); // > 30s limit FIO, pak zkusit ještě jednou
                continue;
            }

            if ($error) {
                throw new \RuntimeException('FIO API curl error: ' . $error);
            }
            if ($httpCode === 409) {
                throw new \RuntimeException('FIO API: Příliš brzy po posledním stažení (limit 30 sekund). Zkuste znovu.');
            }
            if ($httpCode === 500) {
                throw new \RuntimeException('FIO API: Chyba serveru FIO banky. Zkuste znovu.');
            }
            if ($httpCode !== 200) {
                throw new \RuntimeException('FIO API HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 200));
            }

            return (string) $response;
        }

        // Sem se dojde jen když i druhý pokus vypršel timeoutem.
        throw new \RuntimeException('FIO API curl error: ' . $lastTimeoutError);
    }
}
