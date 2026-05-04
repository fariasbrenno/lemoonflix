<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';

const emit = defineEmits(['saved', 'close']);

const base = '/webhook-entrada';

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const ok = ref('');

const endpoints = ref([]);
const products = ref([]);

const showForm = ref(false);
const editingId = ref(null);

const form = ref({
    name: '',
    product_id: '',
    product_offer_id: null,
    subscription_plan_id: null,
    is_active: true,
    signing_secret: '',
    field_map_json: JSON.stringify(
        {
            email: 'email',
            name: 'name',
            cpf: 'cpf',
            phone: 'phone',
            external_id: 'external_id',
        },
        null,
        2
    ),
});

const selectedProduct = computed(() => products.value.find((p) => p.id === form.value.product_id) || null);

async function loadAll() {
    loading.value = true;
    error.value = '';
    ok.value = '';
    try {
        const [epRes, prRes] = await Promise.all([
            axios.get(`${base}/api/endpoints`),
            axios.get(`${base}/api/products`),
        ]);
        endpoints.value = epRes.data?.data || [];
        products.value = prRes.data?.data || [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível carregar os webhooks de entrada.';
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editingId.value = null;
    form.value = {
        name: '',
        product_id: products.value[0]?.id || '',
        product_offer_id: null,
        subscription_plan_id: null,
        is_active: true,
        signing_secret: '',
        field_map_json: JSON.stringify(
            {
                email: 'email',
                name: 'name',
                cpf: 'cpf',
                phone: 'phone',
                external_id: 'external_id',
            },
            null,
            2
        ),
    };
    showForm.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.value = {
        name: row.name,
        product_id: row.product_id,
        product_offer_id: row.product_offer_id,
        subscription_plan_id: row.subscription_plan_id,
        is_active: row.is_active,
        signing_secret: '',
        field_map_json: JSON.stringify(row.field_map && Object.keys(row.field_map).length ? row.field_map : { email: 'email' }, null, 2),
    };
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
}

function parseFieldMap() {
    try {
        const raw = JSON.parse(form.value.field_map_json);
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
        return raw;
    } catch {
        return null;
    }
}

async function save() {
    const fieldMap = parseFieldMap();
    if (!fieldMap) {
        error.value = 'JSON de mapeamento de campos inválido.';
        return;
    }
    saving.value = true;
    error.value = '';
    ok.value = '';
    try {
        const payload = {
            name: form.value.name,
            product_id: form.value.product_id,
            product_offer_id: form.value.product_offer_id || null,
            subscription_plan_id: form.value.subscription_plan_id || null,
            is_active: form.value.is_active,
            field_map: fieldMap,
        };
        if (form.value.signing_secret?.trim()) {
            payload.signing_secret = form.value.signing_secret.trim();
        }
        if (editingId.value) {
            await axios.put(`${base}/api/endpoints/${editingId.value}`, payload);
            ok.value = 'Endpoint atualizado.';
        } else {
            await axios.post(`${base}/api/endpoints`, payload);
            ok.value = 'Endpoint criado. Copie a URL abaixo.';
        }
        showForm.value = false;
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function remove(id) {
    if (!confirm('Remover este endpoint? A URL deixará de funcionar.')) return;
    try {
        await axios.delete(`${base}/api/endpoints/${id}`);
        ok.value = 'Removido.';
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao remover.';
    }
}

async function regenerate(id) {
    if (!confirm('Gerar novo token? A URL antiga para de funcionar.')) return;
    try {
        await axios.post(`${base}/api/endpoints/${id}/regenerate-token`);
        ok.value = 'Novo token gerado.';
        await loadAll();
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao regenerar.';
    }
}

async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        ok.value = 'Copiado.';
    } catch {
        ok.value = '';
    }
}

loadAll();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Webhook de entrada</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Receba <code class="rounded bg-zinc-200 px-1 text-xs dark:bg-zinc-700">POST</code> JSON de um checkout externo. Um pedido
                concluído é criado, o aluno vinculado à área de membros e o e‑mail de acesso enviado (comportamento igual a venda aprovada).
            </p>
            <ul class="mt-2 list-inside list-disc text-xs text-zinc-600 dark:text-zinc-400">
                <li>
                    URL pública:
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">POST …/webhooks/inbound/&lt;token&gt;</code>
                    (token exibido após salvar; use HTTPS em produção).
                </li>
                <li>Campo obrigatório no JSON: <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">email</code> (ou caminho configurado no mapeamento).</li>
                <li>
                    Opcional:
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">external_id</code> para idempotência (mesmo valor não cria pedido duplicado).
                </li>
                <li>
                    Assinatura (opcional): cabeçalho
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">X-Webhook-Signature: sha256=&lt;hmac_hex&gt;</code> com o corpo bruto em
                    HMAC-SHA256 usando o secret configurado.
                </li>
            </ul>
        </div>

        <div v-if="loading" class="text-sm text-zinc-500 dark:text-zinc-400">Carregando…</div>

        <div v-if="error" class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300">
            {{ error }}
        </div>
        <div v-if="ok" class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200">
            {{ ok }}
        </div>

        <div v-if="!loading" class="flex flex-wrap gap-2">
            <Button type="button" @click="openCreate">Novo endpoint</Button>
            <Button type="button" variant="outline" @click="loadAll">Atualizar</Button>
        </div>

        <div v-if="showForm" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ editingId ? 'Editar' : 'Criar' }} endpoint</div>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome</label>
            <input
                v-model="form.name"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Ex.: Hotmart"
            />
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Produto (área de membros)</label>
            <select
                v-model="form.product_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Oferta (opcional)</label>
            <select
                v-model="form.product_offer_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option :value="null">— Nenhuma —</option>
                <option v-for="o in selectedProduct?.offers || []" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Plano de assinatura (opcional)</label>
            <select
                v-model="form.subscription_plan_id"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
            >
                <option :value="null">— Nenhum —</option>
                <option v-for="s in selectedProduct?.subscription_plans || []" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-zinc-800 dark:text-zinc-200">
                <input v-model="form.is_active" type="checkbox" class="rounded border-zinc-300" /> Ativo
            </label>
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                >Secret para assinatura HMAC (opcional; em branco = sem verificação)</label
            >
            <input
                v-model="form.signing_secret"
                type="password"
                autocomplete="new-password"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                placeholder="Deixe vazio ou defina ao criar/editar"
            />
            <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400"
                >Mapeamento JSON: chave interna → caminho no payload (dot notation)</label
            >
            <textarea
                v-model="form.field_map_json"
                rows="8"
                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 font-mono text-xs dark:border-zinc-600 dark:bg-zinc-900"
            ></textarea>
            <div class="flex gap-2">
                <Button type="button" :disabled="saving" @click="save">Salvar</Button>
                <Button type="button" variant="outline" @click="closeForm">Cancelar</Button>
            </div>
        </div>

        <div v-if="!loading && !showForm" class="space-y-3">
            <div v-if="!endpoints.length" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhum endpoint ainda.</div>
            <div
                v-for="row in endpoints"
                :key="row.id"
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-white">{{ row.name }}</div>
                        <div class="mt-1 break-all font-mono text-xs text-zinc-600 dark:text-zinc-400">{{ row.url }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Token: {{ row.url_token_masked }}</div>
                        <div class="mt-1 text-xs text-zinc-500">
                            Status: {{ row.is_active ? 'Ativo' : 'Inativo' }} · Secret:
                            {{ row.signing_secret_set ? 'definido' : 'não definido' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" size="sm" variant="outline" @click="copyText(row.url)">Copiar URL</Button>
                        <Button type="button" size="sm" variant="outline" @click="openEdit(row)">Editar</Button>
                        <Button type="button" size="sm" variant="outline" @click="regenerate(row.id)">Novo token</Button>
                        <Button type="button" size="sm" variant="destructive" @click="remove(row.id)">Remover</Button>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <Button type="button" variant="ghost" @click="emit('close')">Fechar</Button>
        </div>
    </div>
</template>
