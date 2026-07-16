<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Events\AccessDeliveryReady;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendAccessEmailOnOrderCompleted
{
    public function __construct(
        protected AccessEmailService $accessEmailService
    ) {}

    public function handle(OrderCompleted $event): void
    {
        $order = $event->order;
        Log::info('SendAccessEmailOnOrderCompleted: disparando envio de e-mail de acesso.', ['order_id' => $order->id]);

        try {
            // Dispara evento de WhatsApp (AutoZap) com dados prontos de acesso (best-effort).
            $access = $this->accessEmailService->getAccessDataForOrder($order);
            if (is_array($access)) {
                AccessDeliveryReady::dispatch($order, $access);
            }

            $sent = $this->accessEmailService->sendForOrder($order);
            if (! $sent) {
                Log::warning('SendAccessEmailOnOrderCompleted: sendForOrder retornou false.', ['order_id' => $order->id]);
            }

            $this->sendOrderBumpEmails($order);
        } catch (\Throwable $e) {
            Log::error('SendAccessEmailOnOrderCompleted: exceção ao enviar e-mail.', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Envia o e-mail de acesso próprio de cada order bump (produtos extras do pedido),
     * usando o modelo de e-mail configurado em cada produto.
     */
    private function sendOrderBumpEmails($order): void
    {
        $order->loadMissing(['user', 'orderItems.product']);

        $user = $order->user;
        if (! $user) {
            return;
        }

        $mainProductId = $order->product_id;
        $alreadySent = [$mainProductId];

        foreach ($order->orderItems as $item) {
            $product = $item->product;
            if (! $product || in_array($product->id, $alreadySent, true)) {
                continue;
            }
            $alreadySent[] = $product->id;

            // Evita reenvio caso o evento OrderCompleted dispare mais de uma vez.
            $lockKey = 'access_email_sent.' . $order->id . '.product.' . $product->id;
            if (! Cache::add($lockKey, 1, now()->addDays(7))) {
                continue;
            }

            try {
                $sent = $this->accessEmailService->sendForUserProduct($user, $product);
                if (! $sent) {
                    Cache::forget($lockKey);
                    Log::warning('SendAccessEmailOnOrderCompleted: falha ao enviar e-mail do bump.', [
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Cache::forget($lockKey);
                Log::error('SendAccessEmailOnOrderCompleted: exceção no e-mail do bump.', [
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
