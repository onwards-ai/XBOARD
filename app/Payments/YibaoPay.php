<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class YibaoPay implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'yibao_base_url' => [
                'label' => 'BASE URL',
                'description' => 'https://yibaopay.cc',
                'type' => 'input',
            ],
            'yibao_merchant_id' => [
                'label' => 'MERCHANT ID',
                'description' => '商户号',
                'type' => 'input',
            ],
            'yibao_secret_key' => [
                'label' => 'SECRET KEY',
                'description' => '商户密钥',
                'type' => 'input',
            ],
            'yibao_channel' => [
                'label' => 'CHANNEL',
                'description' => 'alipay / wxpay / qqpay',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        $base = rtrim($this->config['yibao_base_url'] ?? 'https://yibaopay.cc', '/');
        $payload = [
            'pid' => $this->config['yibao_merchant_id'] ?? '',
            'type' => $this->config['yibao_channel'] ?? '',
            'out_trade_no' => $order['trade_no'],
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'name' => $order['trade_no'],
            'money' => $order['total_amount'] / 100,
        ];

        if (empty($payload['pid']) || empty($this->config['yibao_secret_key'])) {
            throw new ApiException(__('Payment gateway configuration error'));
        }

        ksort($payload);
        reset($payload);
        $payload['sign'] = md5(stripslashes(urldecode(http_build_query($payload))) . $this->config['yibao_secret_key']);
        $payload['sign_type'] = 'MD5';

        $endpoint = $base . '/submit.php';
        $url = $endpoint . '?' . http_build_query($payload);

        // Try to exchange for a cashier link if the gateway supports JSON submission
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp && !$err) {
            $json = json_decode($resp, true);
            if (is_array($json)) {
                $code = $json['code'] ?? ($json['status'] ?? '');
                if (in_array($code, ['1', 1, 'SUCCESS', 'success', 'OK', 200], true)) {
                    $link = $json['data']['payUrl']
                        ?? $json['data']['url']
                        ?? $json['payUrl']
                        ?? $json['url']
                        ?? '';
                    if (!empty($link)) {
                        return [
                            'type' => 1,
                            'data' => $link,
                        ];
                    }
                }
            }
        }

        return [
            'type' => 1,
            'data' => $url,
        ];
    }

    public function notify($params): array|bool
    {
        if (!isset($params['sign'])) {
            return false;
        }

        $sign = $params['sign'];
        $data = $params;
        unset($data['sign'], $data['sign_type']);
        ksort($data);
        reset($data);

        $calcSign = md5(stripslashes(urldecode(http_build_query($data))) . ($this->config['yibao_secret_key'] ?? ''));
        if ($calcSign !== $sign) {
            return false;
        }

        $tradeNo = $params['out_trade_no'] ?? ($params['trade_no'] ?? '');
        if (empty($tradeNo)) {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $params['trade_no'] ?? '',
        ];
    }
}
