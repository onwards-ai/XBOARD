<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class HitPay implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'hitpay_api_key' => [
                'label' => 'API KEY',
                'description' => '',
                'type' => 'input',
            ],
            'hitpay_webhook_salt' => [
                'label' => 'WEBHOOK SALT',
                'description' => '',
                'type' => 'input',
            ],
            'hitpay_api_base' => [
                'label' => 'API BASE',
                'description' => 'https://api.hit-pay.com/v1',
                'type' => 'input',
            ],
            'hitpay_methods' => [
                'label' => 'PAYMENT METHODS',
                'description' => 'comma separated e.g. wechat_pay,card',
                'type' => 'input',
            ],
            'hitpay_currency' => [
                'label' => 'CURRENCY',
                'description' => 'default SGD',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        $base = rtrim($this->config['hitpay_api_base'] ?? 'https://api.hit-pay.com/v1', '/');
        $url = $base . '/payment-requests';

        $methods = [];
        if (!empty($this->config['hitpay_methods'])) {
            $methods = array_map('trim', explode(',', $this->config['hitpay_methods']));
        } else {
            $methods[] = 'wechat_pay';
        }

        $currency = $this->config['hitpay_currency'] ?? 'SGD';

        $post = 'amount=' . sprintf('%.2f', $order['total_amount'] / 100) . '&currency=' . $currency;
        $post .= '&reference_number=' . $order['trade_no'];
        foreach ($methods as $m) {
            $post .= '&payment_methods[]=' . $m;
        }
        $post .= '&redirect_url=' . urlencode($order['return_url']);
        $post .= '&webhook=' . urlencode($order['notify_url']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => [
                'X-BUSINESS-API-KEY: ' . $this->config['hitpay_api_key'],
                'Content-Type: application/x-www-form-urlencoded',
                'X-Requested-With: XMLHttpRequest',
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        if (empty($data['url'])) {
            throw new ApiException('error!');
        }
        return [
            'type' => 1,
            'data' => $data['url'],
        ];
    }

    public function notify($params): array|bool
    {
        $raw = request()->getContent();
        parse_str($raw, $payload);

        $receivedHmac = $payload['hmac'] ?? '';
        unset($payload['hmac']);

        $calculated = $this->generateSignature($this->config['hitpay_webhook_salt'], $payload);
        if (!hash_equals($calculated, $receivedHmac)) {
            return false;
        }

        return [
            'trade_no' => $payload['reference_number'] ?? ($params['reference_number'] ?? ''),
            'callback_no' => $payload['payment_request_id'] ?? ($params['payment_request_id'] ?? ''),
        ];
    }

    private function generateSignature(string $secret, array $data): string
    {
        ksort($data);
        $sig = '';
        foreach ($data as $key => $val) {
            $sig .= $key . $val;
        }
        return hash_hmac('sha256', $sig, $secret);
    }
}
