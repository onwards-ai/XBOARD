<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;

class Paypal implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'client_id' => [
                'label' => 'CLIENT ID',
                'description' => '',
                'type' => 'input',
            ],
            'client_secret' => [
                'label' => 'CLIENT SECRET',
                'description' => '',
                'type' => 'input',
            ],
            'webhook_id' => [
                'label' => 'WEBHOOK ID',
                'description' => '',
                'type' => 'input',
            ],
            'mode' => [
                'label' => 'MODE',
                'description' => 'live or sandbox',
                'type' => 'input',
            ],
            'currency' => [
                'label' => 'CURRENCY',
                'description' => 'default USD',
                'type' => 'input',
            ],
        ];
    }

    private function baseUrl(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function getAccessToken(): string
    {
        $url = $this->baseUrl() . '/v1/oauth2/token';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => ($this->config['client_id'] ?? '') . ':' . ($this->config['client_secret'] ?? ''),
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($resp, true);
        return $json['access_token'] ?? '';
    }

    public function pay($order): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            throw new ApiException('Paypal authorization error');
        }
        $url = $this->baseUrl() . '/v2/checkout/orders';
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order['trade_no'],
                'amount' => [
                    'currency_code' => $this->config['currency'] ?? 'USD',
                    'value' => sprintf('%.2f', $order['total_amount'] / 100),
                ],
            ]],
            'application_context' => [
                'return_url' => $order['return_url'],
                'cancel_url' => $order['return_url'],
            ],
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
        $json = json_decode($resp, true);
        $approve = '';
        if (isset($json['links'])) {
            foreach ($json['links'] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approve = $link['href'];
                    break;
                }
            }
        }
        if (!$approve) {
            throw new ApiException('PayPal create order failed');
        }
        return [
            'type' => 1,
            'data' => $approve,
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $json = json_decode($payload, true);
        if (!$json) {
            // maybe redirect with token
            if (isset($params['token'])) {
                $orderId = $params['token'];
                $token = $this->getAccessToken();
                if ($token) {
                    $url = $this->baseUrl() . '/v2/checkout/orders/' . $orderId;
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $token,
                        ],
                    ]);
                    $resp = curl_exec($ch);
                    curl_close($ch);
                    $detail = json_decode($resp, true);
                    if (($detail['status'] ?? '') === 'COMPLETED') {
                        $trade = $detail['purchase_units'][0]['reference_id'] ?? '';
                        return [
                            'trade_no' => $trade,
                            'callback_no' => $orderId,
                        ];
                    }
                }
            }
            return false;
        }

        // verify webhook signature
        $headers = [
            'transmission_id' => request()->header('paypal-transmission-id'),
            'transmission_time' => request()->header('paypal-transmission-time'),
            'cert_url' => request()->header('paypal-cert-url'),
            'auth_algo' => request()->header('paypal-auth-algo'),
            'transmission_sig' => request()->header('paypal-transmission-sig'),
            'webhook_id' => $this->config['webhook_id'] ?? '',
            'webhook_event' => $json,
        ];
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }
        $verifyUrl = $this->baseUrl() . '/v1/notifications/verify-webhook-signature';
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($headers),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($resp, true);
        if (($result['verification_status'] ?? '') !== 'SUCCESS') {
            return false;
        }

        $eventType = $json['event_type'] ?? '';
        $resource = $json['resource'] ?? [];
        if (in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'])) {
            $trade = $resource['purchase_units'][0]['reference_id'] ?? ($resource['custom_id'] ?? '');
            $callback = $resource['id'] ?? '';
            if ($trade && $callback) {
                return [
                    'trade_no' => $trade,
                    'callback_no' => $callback,
                ];
            }
        }
        return false;
    }
}

