<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Omise implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'omise_public_key' => [
                'label' => 'PUBLIC KEY',
                'description' => '',
                'type' => 'input',
            ],
            'omise_secret_key' => [
                'label' => 'SECRET KEY',
                'description' => '',
                'type' => 'input',
            ],
            'omise_api_base' => [
                'label' => 'API BASE',
                'description' => 'https://api.omise.co',
                'type' => 'input',
            ],
            'omise_currency' => [
                'label' => 'CURRENCY',
                'description' => 'default THB',
                'type' => 'input',
            ],
            'omise_source_type' => [
                'label' => 'SOURCE TYPE',
                'description' => 'wechat or alipay_cn',
                'type' => 'input',
            ],
            'omise_webhook_secret' => [
                'label' => 'WEBHOOK SECRET',
                'description' => '',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        $base = rtrim($this->config['omise_api_base'] ?? 'https://api.omise.co', '/');
        $type = $this->config['omise_source_type'] ?? 'wechat';
        $currency = $this->config['omise_currency'] ?? 'THB';
        $secretKey = $this->config['omise_secret_key'];

        // create source
        $sourceUrl = $base . '/sources';
        $post = http_build_query([
            'type' => $type,
            'amount' => $order['total_amount'],
            'currency' => $currency,
        ]);
        $ch = curl_init($sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_USERPWD => $secretKey . ':',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $src = json_decode($resp, true);
        if (empty($src['id'])) {
            throw new ApiException('error!');
        }

        // create charge
        $chargeUrl = $base . '/charges';
        $post = http_build_query([
            'source' => $src['id'],
            'amount' => $order['total_amount'],
            'currency' => $currency,
            'return_uri' => $order['return_url'],
            'metadata[order]' => $order['trade_no'],
        ]);
        $ch = curl_init($chargeUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_USERPWD => $secretKey . ':',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $charge = json_decode($resp, true);

        $url = '';
        $typeFlag = 1;
        if ($type === 'wechat') {
            $url = $charge['source']['scannable_code']['image']['download_uri'] ?? '';
            $typeFlag = 0;
        } else {
            $url = $charge['authorize_uri'] ?? '';
            $typeFlag = 1;
        }
        if (!$url) {
            throw new ApiException('error!');
        }

        return [
            'type' => $typeFlag,
            'data' => $url,
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $signature = $_SERVER['HTTP_X_OMISE_SIGNATURE'] ?? '';
        if (!$this->verifySignature($payload, $signature)) {
            return false;
        }
        $json = json_decode($payload, true);
        if (($json['data']['status'] ?? '') !== 'successful') {
            return false;
        }
        return [
            'trade_no' => $json['data']['metadata']['order'] ?? ($params['data']['metadata']['order'] ?? ''),
            'callback_no' => $json['data']['id'] ?? ($params['data']['id'] ?? ''),
        ];
    }

    private function verifySignature(string $payload, string $signature): bool
    {
        if (empty($this->config['omise_webhook_secret'])) {
            return false;
        }
        $calculated = base64_encode(hash_hmac('sha256', $payload, $this->config['omise_webhook_secret'], true));
        return hash_equals($calculated, $signature);
    }
}
