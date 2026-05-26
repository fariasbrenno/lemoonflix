<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\UtmifyService;
use App\Support\OrderReportingAmounts;
use Tests\TestCase;

class OrderReportingAmountsTest extends TestCase
{
    public function test_total_cents_brl_uses_settlement_from_cajupay_metadata(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 4.86,
            'currency' => 'USD',
            'email' => 'intl@example.com',
            'metadata' => [
                'settlement_amount_cents' => 2700,
                'settlement_currency' => 'BRL',
                'fx_rate' => '5.555555',
            ],
        ]);

        $this->assertSame(2700, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_total_cents_brl_uses_order_amount_when_currency_is_brl(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97.00,
            'currency' => 'BRL',
            'email' => 'br@example.com',
        ]);

        $this->assertSame(9700, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_utmify_payload_uses_settlement_brl_not_usd_charge(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 4.86,
            'currency' => 'USD',
            'email' => 'utmify-intl@example.com',
            'metadata' => [
                'settlement_amount_cents' => 2700,
                'settlement_currency' => 'BRL',
                'checkout_payment_method' => 'google_pay',
            ],
        ]);

        $payload = app(UtmifyService::class)->buildPayload($order, 'paid');

        $this->assertSame(2700, $payload['commission']['totalPriceInCents']);
        $this->assertSame(2700, $payload['products'][0]['priceInCents']);
        $this->assertSame('credit_card', $payload['paymentMethod']);
    }
}
