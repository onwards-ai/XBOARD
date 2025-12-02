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
                'label' => 'PAY METHOD',
                'description' => '1000=Alipay H5 (default)',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        $base = rtrim($this->config['yibao_base_url'] ?? 'https://yibaopay.cc', '/');
        $payMethod = $this->config['yibao_channel'] ?? '1000';
        $legacyMap = [
            'alipay' => '1000',
            'wxpay' => '2000',
            'qqpay' => '3000',
        ];
        $payload = [
            'mch_id' => $this->config['yibao_merchant_id'] ?? '',
            'pay_method' => $legacyMap[$payMethod] ?? $payMethod,
            'out_order_sn' => $order['trade_no'],
            'money' => sprintf('%.2f', $order['total_amount'] / 100),
            'notify_url' => $order['notify_url'],
            'return_url' => $order['return_url'],
            'client_ip' => $order['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
            'time' => (string) time(),
            'extra' => $order['trade_no'],
        ];

        if (empty($payload['mch_id']) || empty($this->config['yibao_secret_key'])) {
            throw new ApiException(__('Payment gateway configuration error'));
        }

        $payload['sign'] = $this->buildSign($payload);

        $endpoint = $base . '/prod/api/pay/create';
        $response = $this->postJson($endpoint, $payload);
        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new ApiException('YibaoPay create order failed: invalid response');
        }

        $payUrl = $data['data']['pay_url'] ?? ($data['data']['payUrl'] ?? null);

        if ((int) ($data['code'] ?? 0) !== 200 || empty($payUrl)) {
            $message = $data['msg'] ?? $data['message'] ?? 'request failed';
            throw new ApiException('YibaoPay create order failed: ' . $message);
        }

        return [
            'type' => 1,
            'data' => $payUrl,
        ];
    }

    public function notify($params): array|bool
    {
        if (!isset($params['sign'])) {
            return false;
        }

        $sign = $params['sign'];
        $calcSign = $this->buildSign($params);
        if ($calcSign !== $sign) {
            return false;
        }

        $tradeNo = $params['out_order_sn'] ?? ($params['out_trade_no'] ?? ($params['trade_no'] ?? ''));
        if (empty($tradeNo)) {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $params['order_sn'] ?? ($params['trade_no'] ?? ''),
        ];
    }

    private function buildSign(array $params): string
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($key === 'sign' || $value === '' || $value === null) {
                continue;
            }
            $filtered[$key] = $value;
        }

        ksort($filtered);

        $pairs = [];
        foreach ($filtered as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $baseString = implode('&', $pairs) . '&key=' . ($this->config['yibao_secret_key'] ?? '');

        return strtoupper(md5($baseString));
    }

    private function postJson(string $url, array $payload): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new ApiException('YibaoPay request error: ' . $err);
        }

        return $resp ?: '';
    }
}
