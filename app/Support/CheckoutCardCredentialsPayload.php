<?php

namespace App\Support;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;

class CheckoutCardCredentialsPayload
{
    /**
     * Resolve card-related Inertia props from checkout_payment_methods list.
     *
     * @param  array<int, array{id: string, gateway_slug?: string}>  $paymentMethods
     * @return array<string, mixed>
     */
    public static function forMethods(?int $tenantId, array $paymentMethods): array
    {
        $cardMethod = CheckoutPaymentMethodsBuilder::findMethod($paymentMethods, 'card');
        $slug = $cardMethod['gateway_slug'] ?? null;

        $payload = [
            'card_gateway_slug' => $slug,
            'card_payee_code' => '',
            'card_efi_sandbox' => false,
            'card_stripe_publishable_key' => '',
            'card_stripe_sandbox' => false,
            'card_stripe_link_enabled' => true,
            'card_mercadopago_public_key' => '',
            'card_mercadopago_sandbox' => false,
            'card_pagarme_public_key' => '',
            'card_pagarme_api_base_url' => rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/'),
            'card_gateway_keys' => [],
        ];

        if ($slug === null || $slug === '') {
            return $payload;
        }

        foreach ($paymentMethods as $m) {
            if (($m['id'] ?? '') !== 'card') {
                continue;
            }
            $methodSlug = $m['gateway_slug'] ?? '';
            if ($methodSlug === '') {
                continue;
            }
            $cred = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', $methodSlug)
                ->where('is_connected', true)
                ->first();
            if (! $cred) {
                continue;
            }
            $creds = $cred->getDecryptedCredentials();
            if ($methodSlug === 'efi') {
                $payload['card_payee_code'] = (string) ($creds['payee_code'] ?? '');
                $payload['card_efi_sandbox'] = ! empty($creds['sandbox']);
            }
            if ($methodSlug === 'stripe') {
                $payload['card_stripe_publishable_key'] = (string) ($creds['publishable_key'] ?? '');
                $payload['card_stripe_sandbox'] = ! empty($creds['sandbox']);
                $payload['card_stripe_link_enabled'] = isset($creds['link_enabled'])
                    ? (bool) $creds['link_enabled']
                    : true;
            }
            if ($methodSlug === 'mercadopago') {
                $payload['card_mercadopago_public_key'] = (string) ($creds['public_key'] ?? '');
                $payload['card_mercadopago_sandbox'] = ! empty($creds['sandbox']);
            }
            if ($methodSlug === 'pagarme') {
                $payload['card_pagarme_public_key'] = (string) ($creds['public_key'] ?? '');
            }

            $gateway = GatewayRegistry::get($methodSlug);
            $keys = $gateway['checkout_payload_keys'] ?? null;
            if (is_array($keys) && $keys !== []) {
                $payload['card_gateway_keys'][$methodSlug] = [];
                foreach ($keys as $key) {
                    if (is_string($key) && array_key_exists($key, $creds)) {
                        $payload['card_gateway_keys'][$methodSlug][$key] = $creds[$key];
                    }
                }
                if ($methodSlug === 'pagarme') {
                    $payload['card_gateway_keys'][$methodSlug]['api_base_url'] = $payload['card_pagarme_api_base_url'];
                }
            }
        }

        return $payload;
    }
}
