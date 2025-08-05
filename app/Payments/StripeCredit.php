<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\OrderService;
use Stripe\Charge;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeCredit implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'stripe_pk_live' => [
                'label' => 'PUBLISHABLE KEY',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_sk_live' => [
                'label' => 'SECRET KEY',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_webhook_secret' => [
                'label' => 'WEBHOOK SECRET',
                'description' => '',
                'type' => 'input',
            ],
            'stripe_currency' => [
                'label' => 'CURRENCY',
                'description' => 'default USD',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order): array
    {
        Stripe::setApiKey($this->config['stripe_sk_live']);

        try {
            $charge = Charge::create([
                'amount' => $order['total_amount'],
                'currency' => $this->config['stripe_currency'] ?? 'usd',
                'source' => $order['stripe_token'],
                'metadata' => [
                    'trade_no' => $order['trade_no'],
                    'user_id' => $order['user_id'],
                ],
            ]);
        } catch (\Exception $e) {
            throw new ApiException(__('Payment gateway request failed'));
        }

        if (($charge['status'] ?? '') !== 'succeeded') {
            throw new ApiException(__('Payment failed. Please check your credit card information'));
        }

        $model = Order::where('trade_no', $order['trade_no'])->first();
        if ($model) {
            $service = new OrderService($model);
            if (!$service->paid($charge['id'])) {
                throw new ApiException(__('Payment gateway request failed'));
            }
        }

        return [
            'type' => -1,
            'data' => true,
        ];
    }

    public function notify($params): array|bool
    {
        $payload = request()->getContent();
        $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!empty($this->config['stripe_webhook_secret'])) {
            try {
                $event = Webhook::constructEvent(
                    $payload,
                    $sig,
                    $this->config['stripe_webhook_secret']
                );
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $event = json_decode($payload, true);
        }

        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        if ($type === 'charge.succeeded') {
            return [
                'trade_no' => $object['metadata']['trade_no'] ?? '',
                'callback_no' => $object['id'] ?? '',
            ];
        }

        if ($type === 'checkout.session.completed') {
            return [
                'trade_no' => $object['metadata']['trade_no'] ?? '',
                'callback_no' => $object['payment_intent'] ?? ($object['id'] ?? ''),
            ];
        }

        return false;
    }
}

