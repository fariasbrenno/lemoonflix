<?php

namespace App\Http\Controllers;

use App\Models\PixelXIntegration;
use App\Models\PixelXIntegrationLog;
use App\Models\Product;
use App\Support\PixelXPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class PixelXIntegrationController extends Controller
{
    private const PIXEL_X_EVENTS = [
        'pedido_pendente',
        'pedido_pago',
        'pagamento_recusado',
        'reembolso',
        'pix_gerado',
        'boleto_gerado',
        'carrinho_abandonado',
        'assinatura_criada',
        'assinatura_renovada',
        'assinatura_cancelada',
    ];

    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $integrations = PixelXIntegration::forTenant($tenantId)
            ->with('products:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (PixelXIntegration $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'url' => $i->url,
                'has_token' => (bool) $i->token,
                'events' => $i->events ?? [],
                'is_active' => $i->is_active,
                'products' => $i->products->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->toArray(),
            ]);

        $products = Product::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->toArray();

        return response()->json([
            'integrations' => $integrations,
            'pixel_x_events' => self::PIXEL_X_EVENTS,
            'products' => $products,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'token' => ['nullable', 'string', 'max:1024'],
            'events' => ['required', 'array'],
            'events.*' => [Rule::in(self::PIXEL_X_EVENTS)],
            'is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['required', 'string', 'exists:products,id'],
        ]);

        $tenantId = auth()->user()->tenant_id;

        $integration = PixelXIntegration::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'url' => $validated['url'],
            'token' => $validated['token'] ?? null,
            'events' => array_values(array_unique($validated['events'])),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['product_ids'])) {
            $integration->products()->sync($validated['product_ids']);
        }

        $integration->load('products:id,name');

        return response()->json([
            'integration' => [
                'id' => $integration->id,
                'name' => $integration->name,
                'url' => $integration->url,
                'has_token' => (bool) $integration->token,
                'events' => $integration->events ?? [],
                'is_active' => $integration->is_active,
                'products' => $integration->products->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->toArray(),
            ],
        ], 201);
    }

    public function update(Request $request, PixelXIntegration $pixelX): JsonResponse
    {
        $this->authorizeIntegration($pixelX);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'token' => ['nullable', 'string', 'max:1024'],
            'events' => ['required', 'array'],
            'events.*' => [Rule::in(self::PIXEL_X_EVENTS)],
            'is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['required', 'string', 'exists:products,id'],
        ]);

        $updateData = [
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => array_values(array_unique($validated['events'])),
            'is_active' => $validated['is_active'] ?? true,
        ];

        if (array_key_exists('token', $validated) && (string) $validated['token'] !== '') {
            $updateData['token'] = $validated['token'];
        }

        $pixelX->update($updateData);

        if (array_key_exists('product_ids', $validated)) {
            $pixelX->products()->sync($validated['product_ids'] ?? []);
        }

        $pixelX->load('products:id,name');

        return response()->json([
            'integration' => [
                'id' => $pixelX->id,
                'name' => $pixelX->name,
                'url' => $pixelX->url,
                'has_token' => (bool) $pixelX->token,
                'events' => $pixelX->events ?? [],
                'is_active' => $pixelX->is_active,
                'products' => $pixelX->products->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])->toArray(),
            ],
        ]);
    }

    public function destroy(PixelXIntegration $pixelX): Response
    {
        $this->authorizeIntegration($pixelX);

        $pixelX->delete();

        return response()->noContent();
    }

    public function test(Request $request, PixelXIntegration $pixelX): JsonResponse
    {
        $this->authorizeIntegration($pixelX);

        $events = $pixelX->events ?? [];
        $slug = ! empty($events) ? $events[0] : 'pedido_pago';

        try {
            $token = $pixelX->token ?? '';
        } catch (\Throwable) {
            $token = '';
        }

        $body = PixelXPayloadBuilder::samplePayload($slug, $token);

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($pixelX->url, $body);

            $responseStatus = $response->status();
            $responseBody = $response->body();
            $success = $response->successful();

            PixelXIntegrationLog::create([
                'pixel_x_integration_id' => $pixelX->id,
                'event' => $slug,
                'event_label' => $slug,
                'request_payload' => $body,
                'response_status' => $responseStatus,
                'response_body' => strlen($responseBody) > 2000 ? substr($responseBody, 0, 2000) . '…' : $responseBody,
                'success' => $success,
                'error_message' => $success ? null : 'HTTP ' . $responseStatus,
                'source' => 'test',
            ]);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Evento de teste enviado com sucesso. Verifique se sua URL recebeu o payload.',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'A URL retornou status ' . $responseStatus . '. Verifique se o endpoint está configurado corretamente.',
            ], 422);
        } catch (\Throwable $e) {
            PixelXIntegrationLog::create([
                'pixel_x_integration_id' => $pixelX->id,
                'event' => $slug,
                'event_label' => $slug,
                'request_payload' => $body,
                'response_status' => null,
                'response_body' => null,
                'success' => false,
                'error_message' => $e->getMessage(),
                'source' => 'test',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function logs(PixelXIntegration $pixelX): JsonResponse
    {
        $this->authorizeIntegration($pixelX);

        $logs = $pixelX->logs()->limit(50)->get()->map(fn (PixelXIntegrationLog $log) => [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => $log->event_label,
            'response_status' => $log->response_status,
            'success' => $log->success,
            'error_message' => $log->error_message,
            'source' => $log->source,
            'created_at' => $log->created_at->toIso8601String(),
        ]);

        return response()->json(['logs' => $logs]);
    }

    public function showLog(PixelXIntegration $pixelX, int $log): JsonResponse
    {
        $this->authorizeIntegration($pixelX);

        $logEntry = $pixelX->logs()->findOrFail($log);

        return response()->json([
            'log' => [
                'id' => $logEntry->id,
                'event' => $logEntry->event,
                'event_label' => $logEntry->event_label,
                'request_payload' => $logEntry->request_payload,
                'response_status' => $logEntry->response_status,
                'response_body' => $logEntry->response_body,
                'success' => $logEntry->success,
                'error_message' => $logEntry->error_message,
                'source' => $logEntry->source,
                'created_at' => $logEntry->created_at->toIso8601String(),
            ],
        ]);
    }

    private function authorizeIntegration(PixelXIntegration $pixelX): void
    {
        if ($pixelX->tenant_id !== auth()->user()->tenant_id) {
            abort(404);
        }
    }
}
