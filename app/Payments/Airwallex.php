<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Airwallex implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'airwallex_client_id' => [
                'label' => 'Client ID',
                'description' => '',
                'type' => 'input',
            ],
            'airwallex_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'airwallex_api_base' => [
                'label' => 'API BASE',
                'description' => 'https://api.airwallex.com',
                'type' => 'input',
            ],
            'airwallex_currency' => [
                'label' => 'CURRENCY',
                'description' => 'default USD',
                'type' => 'input',
            ],
            'airwallex_webhook_secret' => [
                'label' => 'WEBHOOK SECRET',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    private function authToken(): ?string
    {
        $base = rtrim($this->config['airwallex_api_base'] ?? 'https://api.airwallex.com', '/');
        $url = $base . '/api/v1/authentication/login';
        $payload = json_encode([
            'client_id' => $this->config['airwallex_client_id'],
            'api_key' => $this->config['airwallex_api_key'],
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        return $data['token'] ?? null;
    }

    public function pay($order): array
    {
        $token = $this->authToken();
        if (!$token) {
            throw new ApiException('failed to get token');
        }
        $base = rtrim($this->config['airwallex_api_base'] ?? 'https://api.airwallex.com', '/');
        $url = $base . '/api/v1/pa/payment_intents/create';
        $payload = [
            'merchant_order_id' => $order['trade_no'],
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => $this->config['airwallex_currency'] ?? 'USD',
            'return_url' => $order['return_url'],
            'webhook_url' => $order['notify_url'],
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (empty($data['next_action']['hosted_url'])) {
            throw new ApiException('error!');
        }
        return [
            'type' => 1,
            'data' => $data['next_action']['hosted_url'],
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if (!$this->verifySignature($payload, $signature)) {
            return false;
        }
        $json = json_decode($payload, true);
        return [
            'trade_no' => $json['data']['merchant_order_id'] ?? ($params['merchant_order_id'] ?? ''),
            'callback_no' => $json['data']['id'] ?? ($params['id'] ?? ''),
        ];
    }

    private function verifySignature(string $payload, string $signature): bool
    {
        if (empty($this->config['airwallex_webhook_secret'])) {
            return false;
        }
        $calculated = base64_encode(hash_hmac('sha256', $payload, $this->config['airwallex_webhook_secret'], true));
        return hash_equals($calculated, $signature);
    }
}
