<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class SmoochPay implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'smoochpay_api_base' => [
                'label' => 'API BASE',
                'description' => 'https://api.smoochpay.com',
                'type' => 'input',
            ],
            'smoochpay_merchant_no' => [
                'label' => 'MERCHANT NO',
                'description' => '',
                'type' => 'input',
            ],
            'smoochpay_api_key' => [
                'label' => 'API KEY',
                'description' => 'Used as apikey header when calling the API',
                'type' => 'input',
            ],
            'smoochpay_currency' => [
                'label' => 'CURRENCY',
                'description' => 'Default USD',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        $base = rtrim($this->config['smoochpay_api_base'] ?? 'https://api.smoochpay.com', '/');
        $url = $base . '/payment/auto';

        $payload = [
            'merchant_no' => $this->config['smoochpay_merchant_no'] ?? '',
            'order_no' => $order['trade_no'],
            'amount' => sprintf('%.2f', $order['total_amount'] / 100),
            'currency' => $this->config['smoochpay_currency'] ?? 'USD',
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'user_id' => $order['user_id'],
        ];

        if (empty($payload['merchant_no'])) {
            throw new ApiException(__('Payment gateway configuration error'));
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if (!empty($this->config['smoochpay_api_key'])) {
            $headers[] = 'apikey: ' . $this->config['smoochpay_api_key'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $err) {
            throw new ApiException(__('Payment gateway request failed'));
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new ApiException(__('Payment gateway request failed'));
        }

        $code = $data['code'] ?? ($data['status'] ?? '');
        if (!in_array($code, ['0', 0, 'SUCCESS', 'success', 'OK', 200], true)) {
            $message = $data['message'] ?? ($data['msg'] ?? '');
            if (!empty($message)) {
                throw new ApiException($message);
            }
            throw new ApiException(__('Payment gateway request failed'));
        }

        $link = $data['data']['cashierUrl']
            ?? $data['data']['payUrl']
            ?? $data['data']['payment_url']
            ?? $data['data']['url']
            ?? $data['cashierUrl']
            ?? $data['payUrl']
            ?? $data['payment_url']
            ?? $data['url']
            ?? '';

        if (empty($link)) {
            throw new ApiException(__('Payment gateway request failed'));
        }

        return [
            'type' => 1,
            'data' => $link,
        ];
    }

    public function notify($params): array|bool
    {
        $payload = [];
        if (is_array($params) && !empty($params)) {
            $payload = $params;
        } else {
            $raw = request()->getContent();
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
        }

        if (empty($payload)) {
            return false;
        }

        $data = $payload['data'] ?? $payload;
        $status = strtoupper($data['status'] ?? ($payload['status'] ?? ''));
        if (!in_array($status, ['SUCCESS', 'PAID', 'COMPLETED', 'PAY_SUCCESS'], true)) {
            return false;
        }

        $tradeNo = $data['order_no']
            ?? $data['merchant_order_no']
            ?? $data['trade_no']
            ?? $payload['order_no']
            ?? $payload['trade_no']
            ?? '';

        $callbackNo = $data['channel_order_no']
            ?? $data['transaction_no']
            ?? $data['payment_no']
            ?? $payload['channel_order_no']
            ?? $payload['transaction_no']
            ?? '';

        if (empty($tradeNo)) {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
        ];
    }
}
