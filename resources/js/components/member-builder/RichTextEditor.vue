<script setup>
import { nextTick, ref, watch } from 'vue';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    Heading2,
    Heading3,
    Image,
    Italic,
    Link2,
    List,
    ListOrdered,
    Pilcrow,
    Quote,
    Video,
} from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: String, default: '' },
    allowMedia: { type: Boolean, default: false },
    uploadUrl: { type: String, default: '' },
    placeholder: { type: String, default: 'Escreva o conteúdo...' },
    minHeight: { type: String, default: '180px' },
});

const emit = defineEmits(['update:modelValue']);

const editor = ref(null);
const imageInput = ref(null);
const uploadingImage = ref(false);
let isApplyingExternalValue = false;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function normalizeStoredHtml(value) {
    const raw = String(value ?? '');
    if (!raw.trim()) return '';
    if (/<[a-z][\s\S]*>/i.test(raw)) return sanitizeEditorHtml(raw, props.allowMedia);
    return raw
        .split(/\n{2,}/)
        .map((part) => `<p>${escapeHtml(part).replace(/\n/g, '<br>')}</p>`)
        .join('');
}

function allowedElementNames(allowMedia) {
    const names = [
        'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'A', 'UL', 'OL', 'LI',
        'H1', 'H2', 'H3', 'H4', 'BLOCKQUOTE', 'PRE', 'CODE', 'SPAN', 'DIV',
    ];
    if (allowMedia) names.push('IMG', 'IFRAME');
    return new Set(names);
}

function safeLink(url) {
    return /^(https?:\/\/|mailto:|tel:|\/)/i.test(String(url || '').trim());
}

function safeImage(url) {
    return /^(https?:\/\/|\/|data:image\/(?:png|jpe?g|gif|webp);base64,)/i.test(String(url || '').trim());
}

function safeVideo(url) {
    try {
        const parsed = new URL(String(url || '').trim());
        const host = parsed.hostname.toLowerCase();
        return [
            'youtube.com',
            'www.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
            'player.vimeo.com',
            'fast.wistia.net',
            'fast.wistia.com',
            'loom.com',
            'www.loom.com',
            'iframe.mediadelivery.net',
            'embed.cloudflarestream.com',
            'iframe.videodelivery.net',
            'player.hotmart.com',
            'videos.hotmart.com',
        ].some((allowed) => host === allowed || host.endsWith(`.${allowed}`));
    } catch (_) {
        return false;
    }
}

function cleanElementAttributes(el, allowMedia) {
    const tag = el.tagName;
    const allowedAttrs = {
        A: ['href', 'target', 'rel', 'title'],
        IMG: allowMedia ? ['src', 'alt', 'title', 'loading'] : [],
        IFRAME: allowMedia ? ['src', 'title', 'allow', 'allowfullscreen', 'frameborder'] : [],
    }[tag] ?? ['data-align', 'data-size'];

    for (const attr of [...el.attributes]) {
        const name = attr.name.toLowerCase();
        const value = attr.value.trim();
        if (name.startsWith('on') || !allowedAttrs.includes(name)) {
            el.removeAttribute(attr.name);
            continue;
        }
        if (name === 'href' && !safeLink(value)) el.removeAttribute(attr.name);
        if (tag === 'IMG' && name === 'src' && !safeImage(value)) el.removeAttribute(attr.name);
        if (tag === 'IFRAME' && name === 'src' && !safeVideo(value)) el.removeAttribute(attr.name);
        if (name === 'data-align' && !['left', 'center', 'right', 'justify'].includes(value)) el.removeAttribute(attr.name);
        if (name === 'data-size' && !['small', 'normal', 'large'].includes(value)) el.removeAttribute(attr.name);
    }

    const textAlign = el.style?.textAlign;
    if (['left', 'center', 'right', 'justify'].includes(textAlign)) {
        el.setAttribute('data-align', textAlign);
    }
    el.removeAttribute('style');
    el.removeAttribute('class');

    if (tag === 'A' && el.getAttribute('href')) {
        el.setAttribute('target', '_blank');
        el.setAttribute('rel', 'noopener noreferrer');
    }
    if (tag === 'IMG') {
        if (!el.getAttribute('src')) el.remove();
        else el.setAttribute('loading', 'lazy');
    }
    if (tag === 'IFRAME') {
        if (!el.getAttribute('src')) el.remove();
        else {
            el.setAttribute('allowfullscreen', 'allowfullscreen');
            el.setAttribute('loading', 'lazy');
        }
    }
}

function sanitizeEditorHtml(html, allowMedia = props.allowMedia) {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '');
    const allowed = allowedElementNames(allowMedia);

    function walk(node) {
        for (const child of [...node.childNodes]) {
            if (child.nodeType === Node.COMMENT_NODE) {
                child.remove();
                continue;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) continue;
            if (!allowed.has(child.tagName)) {
                if (['SCRIPT', 'STYLE', 'SVG', 'MATH', 'OBJECT', 'EMBED', 'LINK', 'META'].includes(child.tagName)) {
                    child.remove();
                    continue;
                }
                walk(child);
                child.replaceWith(...child.childNodes);
                walk(node);
                continue;
            }
            cleanElementAttributes(child, allowMedia);
            if (child.parentNode) walk(child);
        }
    }

    walk(template.content);
    return template.innerHTML.trim();
}

function applyModelValue() {
    const el = editor.value;
    if (!el) return;
    const normalized = normalizeStoredHtml(props.modelValue);
    if (el.innerHTML === normalized) return;
    isApplyingExternalValue = true;
    el.innerHTML = normalized;
    nextTick(() => {
        isApplyingExternalValue = false;
    });
}

watch(() => props.modelValue, applyModelValue, { immediate: true });

function emitCurrent(clean = false) {
    if (!editor.value || isApplyingExternalValue) return;
    if (clean) editor.value.innerHTML = sanitizeEditorHtml(editor.value.innerHTML, props.allowMedia);
    emit('update:modelValue', editor.value.innerHTML.trim());
}

function focusEditor() {
    editor.value?.focus();
}

function command(name, value = null) {
    focusEditor();
    document.execCommand(name, false, value);
    emitCurrent();
}

function setBlock(tag) {
    command('formatBlock', tag);
}

function setAlign(align) {
    focusEditor();
    document.execCommand('justifyLeft', false, null);
    const selection = window.getSelection();
    const node = selection?.anchorNode;
    const element = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
    const block = element?.closest?.('p,h1,h2,h3,h4,blockquote,li,div');
    if (block) block.setAttribute('data-align', align);
    else document.execCommand(`justify${align.charAt(0).toUpperCase()}${align.slice(1)}`, false, null);
    emitCurrent(true);
}

function insertHtml(html) {
    focusEditor();
    document.execCommand('insertHTML', false, sanitizeEditorHtml(html, props.allowMedia));
    emitCurrent();
}

function createLink() {
    const url = window.prompt('URL do link:');
    if (!url || !safeLink(url)) return;
    const label = window.getSelection()?.toString();
    if (label) {
        command('createLink', url.trim());
        emitCurrent(true);
        return;
    }
    insertHtml(`<a href="${escapeHtml(url.trim())}" target="_blank" rel="noopener noreferrer">${escapeHtml(url.trim())}</a>`);
}

function videoEmbedUrl(url) {
    const u = String(url || '').trim();
    let match = u.match(/(?:youtube\.com\/watch\?.*v=|youtu\.be\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]+)/i);
    if (match) return `https://www.youtube.com/embed/${match[1]}`;
    match = u.match(/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/i);
    if (match) return `https://www.youtube.com/embed/${match[1]}`;
    match = u.match(/vimeo\.com\/(?:video\/)?(\d+)/i);
    if (match) return `https://player.vimeo.com/video/${match[1]}`;
    match = u.match(/player\.vimeo\.com\/video\/(\d+)/i);
    if (match) return `https://player.vimeo.com/video/${match[1]}`;
    match = u.match(/wistia\.(?:com|net)\/(?:medias|embed\/iframe)\/([a-zA-Z0-9]+)/i);
    if (match) return `https://fast.wistia.net/embed/iframe/${match[1]}`;
    match = u.match(/loom\.com\/share\/([a-zA-Z0-9]+)/i);
    if (match) return `https://www.loom.com/embed/${match[1]}`;
    match = u.match(/loom\.com\/embed\/([a-zA-Z0-9]+)/i);
    if (match) return `https://www.loom.com/embed/${match[1]}`;
    return safeVideo(u) ? u : '';
}

function insertVideo() {
    if (!props.allowMedia) return;
    const url = window.prompt('URL do vídeo (YouTube, Vimeo, Wistia, Loom etc.):');
    const embed = videoEmbedUrl(url);
    if (!embed) {
        alert('Não foi possível incorporar esse vídeo. Use uma URL compatível ou um link de embed seguro.');
        return;
    }
    insertHtml(`<div><iframe src="${escapeHtml(embed)}" title="Vídeo incorporado" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div><p><br></p>`);
}

function insertImageUrl() {
    if (!props.allowMedia) return;
    const url = window.prompt('URL da imagem:');
    if (!url || !safeImage(url)) return;
    insertHtml(`<p><img src="${escapeHtml(url.trim())}" alt=""></p><p><br></p>`);
}

async function onImageSelected(event) {
    const file = event.target?.files?.[0];
    if (!file || !props.uploadUrl) return;
    if (!file.type?.startsWith('image/')) {
        alert('Selecione uma imagem válida.');
        return;
    }
    uploadingImage.value = true;
    try {
        const body = new FormData();
        body.append('file', file);
        const res = await fetch(props.uploadUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });
        const data = await res.json();
        if (!res.ok || !data?.url) throw new Error(data?.message || 'Falha ao enviar imagem.');
        insertHtml(`<p><img src="${escapeHtml(data.url)}" alt="${escapeHtml(file.name)}"></p><p><br></p>`);
    } catch (e) {
        alert(e.message || 'Falha ao enviar imagem.');
    } finally {
        uploadingImage.value = false;
        if (imageInput.value) imageInput.value.value = '';
    }
}

function handlePaste(event) {
    event.preventDefault();
    const html = event.clipboardData?.getData('text/html');
    const text = event.clipboardData?.getData('text/plain') ?? '';
    if (html) {
        insertHtml(sanitizeEditorHtml(html, props.allowMedia));
        return;
    }
    insertHtml(
        text
            .split(/\n{2,}/)
            .map((part) => `<p>${escapeHtml(part).replace(/\n/g, '<br>')}</p>`)
            .join('')
    );
}
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-zinc-300 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center gap-1 border-b border-zinc-200 bg-zinc-50 p-1.5 dark:border-zinc-700 dark:bg-zinc-800/70">
            <button type="button" class="rt-btn" title="Parágrafo" @click="setBlock('p')"><Pilcrow class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Título" @click="setBlock('h2')"><Heading2 class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Subtítulo" @click="setBlock('h3')"><Heading3 class="h-4 w-4" /></button>
            <span class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700" />
            <button type="button" class="rt-btn" title="Negrito" @click="command('bold')"><Bold class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Itálico" @click="command('italic')"><Italic class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Lista" @click="command('insertUnorderedList')"><List class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Lista numerada" @click="command('insertOrderedList')"><ListOrdered class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Citação" @click="setBlock('blockquote')"><Quote class="h-4 w-4" /></button>
            <span class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700" />
            <button type="button" class="rt-btn" title="Alinhar à esquerda" @click="setAlign('left')"><AlignLeft class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Centralizar" @click="setAlign('center')"><AlignCenter class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Alinhar à direita" @click="setAlign('right')"><AlignRight class="h-4 w-4" /></button>
            <button type="button" class="rt-btn" title="Justificar" @click="setAlign('justify')"><AlignJustify class="h-4 w-4" /></button>
            <span class="mx-1 h-5 w-px bg-zinc-200 dark:bg-zinc-700" />
            <button type="button" class="rt-btn" title="Inserir link" @click="createLink"><Link2 class="h-4 w-4" /></button>
            <template v-if="allowMedia">
                <button type="button" class="rt-btn" title="Inserir imagem por URL" @click="insertImageUrl"><Image class="h-4 w-4" /></button>
                <button type="button" class="rt-btn" :disabled="uploadingImage || !uploadUrl" title="Enviar imagem" @click="imageInput?.click()">
                    {{ uploadingImage ? '...' : '+' }}
                </button>
                <button type="button" class="rt-btn" title="Incorporar vídeo" @click="insertVideo"><Video class="h-4 w-4" /></button>
                <input ref="imageInput" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
            </template>
        </div>
        <div
            ref="editor"
            class="rich-text-editor lesson-rich-content max-w-none overflow-y-auto px-3 py-2 text-sm text-zinc-900 outline-none dark:text-zinc-100"
            :style="{ minHeight }"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :data-placeholder="placeholder"
            @input="emitCurrent(false)"
            @blur="emitCurrent(true)"
            @paste="handlePaste"
        />
    </div>
</template>
