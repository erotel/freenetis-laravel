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

    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FreenetIS/Laravel',
            CURLOPT_ENCODING       => '',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

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
            throw new \RuntimeException('FIO API HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
        }

        return $response;
    }
}
