<?php

namespace App\Support;

use App\Models\Order;

/**
 * Valores em BRL (centavos) para integrações que exigem moeda brasileira (ex.: Utmify).
 *
 * Pedidos internacionais podem ter orders.currency = USD com settlement em BRL no metadata
 * (webhook CajuPay). Nesses casos, o valor de cobrança em USD não deve ir para a Utmify.
 */
class OrderReportingAmounts
{
    /**
     * Total do pedido em centavos BRL para relatórios externos.
     */
    public static function totalCentsBrl(Order $order): int
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $settlementCents = $meta['settlement_amount_cents'] ?? null;
        $settlementCurrency = MoneyMinorUnits::normalizeCurrencyCode((string) ($meta['settlement_currency'] ?? ''));
        if (is_numeric($settlementCents) && (int) $settlementCents > 0 && $settlementCurrency === 'BRL') {
            return (int) $settlementCents;
        }

        $totalAmount = $order->lineItemsTotalAmount();

        if ($order->getCurrencyOrDefault() === 'BRL') {
            return (int) round($totalAmount * 100);
        }

        $fx = $meta['fx_rate'] ?? null;
        if ($fx !== null && $fx !== '' && is_numeric($fx) && (float) $fx > 0) {
            return (int) round($totalAmount * (float) $fx * 100);
        }

        $displayCurrency = strtoupper(trim((string) ($meta['display_currency'] ?? '')));
        if ($displayCurrency === 'BRL' && isset($meta['display_amount']) && is_numeric($meta['display_amount'])) {
            $display = (float) $meta['display_amount'];
            if ($display > 0) {
                return (int) round($display * 100);
            }
        }

        return (int) round($totalAmount * 100);
    }

    /**
     * Rateio proporcional de uma linha (produto / bump) no total em centavos BRL.
     */
    public static function lineCentsBrl(Order $order, float $lineAmount): int
    {
        $totalCents = self::totalCentsBrl($order);
        $linesTotal = $order->lineItemsTotalAmount();

        if ($linesTotal <= 0 || $lineAmount <= 0) {
            return $totalCents;
        }

        return max(1, (int) round($totalCents * ($lineAmount / $linesTotal)));
    }
}
