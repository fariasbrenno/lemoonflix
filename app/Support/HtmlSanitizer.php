<?php

namespace App\Support;

class HtmlSanitizer
{
    /**
     * Lista de tags permitidas para conteúdo textual (evita XSS).
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'a', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'code', 'span', 'div',
    ];

    private const MEDIA_TAGS = ['img', 'iframe'];

    /**
     * Sanitiza HTML para exibição segura (remove script, eventos, javascript:).
     */
    public static function sanitize(?string $html, bool $allowMedia = true): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        if (! class_exists(\DOMDocument::class)) {
            return self::fallbackSanitize($html, $allowMedia);
        }

        $allowedTags = array_fill_keys(array_merge(self::ALLOWED_TAGS, $allowMedia ? self::MEDIA_TAGS : []), true);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body><div id="__root__">' . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8') . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('__root__');
        if (! $root) {
            return '';
        }

        self::sanitizeNode($root, $allowedTags, $allowMedia);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private static function fallbackSanitize(string $html, bool $allowMedia): string
    {
        $tags = '<' . implode('><', array_merge(self::ALLOWED_TAGS, $allowMedia ? self::MEDIA_TAGS : [])) . '>';
        $html = strip_tags($html, $tags);
        $html = self::removeEventHandlers($html);
        $html = self::removeUnsafeUrls($html);

        return trim($html);
    }

    /**
     * @param array<string, bool> $allowedTags
     */
    private static function sanitizeNode(\DOMNode $node, array $allowedTags, bool $allowMedia): void
    {
        if ($node instanceof \DOMComment) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);

            if (! isset($allowedTags[$tag])) {
                if (in_array($tag, ['script', 'style', 'svg', 'math', 'object', 'embed', 'link', 'meta'], true)) {
                    $node->parentNode?->removeChild($node);
                    return;
                }
                for ($child = $node->firstChild; $child !== null;) {
                    $next = $child->nextSibling;
                    self::sanitizeNode($child, $allowedTags, $allowMedia);
                    $child = $next;
                }
                self::unwrapNode($node);
                return;
            }

            self::sanitizeAttributes($node, $tag, $allowMedia);
        }

        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;
            self::sanitizeNode($child, $allowedTags, $allowMedia);
            $child = $next;
        }
    }

    private static function unwrapNode(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function sanitizeAttributes(\DOMElement $element, string $tag, bool $allowMedia): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'target', 'rel', 'title'],
            'img' => $allowMedia ? ['src', 'alt', 'title', 'loading'] : [],
            'iframe' => $allowMedia ? ['src', 'title', 'allow', 'allowfullscreen', 'frameborder'] : [],
            default => ['data-align', 'data-size'],
        };

        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);

            if (str_starts_with($name, 'on') || ! in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if ($name === 'href' && ! self::isSafeLinkUrl($value)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if ($tag === 'img' && $name === 'src' && ! self::isSafeImageUrl($value)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if ($tag === 'iframe' && $name === 'src' && ! self::isSafeVideoEmbedUrl($value)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if ($name === 'data-align' && ! in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if ($name === 'data-size' && ! in_array($value, ['small', 'normal', 'large'], true)) {
                $element->removeAttributeNode($attr);
                continue;
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'img') {
            if (! $element->hasAttribute('src')) {
                $element->parentNode?->removeChild($element);
                return;
            }
            $element->setAttribute('loading', 'lazy');
        }

        if ($tag === 'iframe') {
            if (! $element->hasAttribute('src')) {
                $element->parentNode?->removeChild($element);
                return;
            }
            $element->setAttribute('allowfullscreen', 'allowfullscreen');
            $element->setAttribute('loading', 'lazy');
        }
    }

    private static function removeEventHandlers(string $html): string
    {
        return (string) preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/i', '', $html);
    }

    private static function removeUnsafeUrls(string $html): string
    {
        return (string) preg_replace(
            '/(href|src)\s*=\s*["\']\s*(javascript|data:text\/html)\s*:[^"\']*["\']/i',
            '$1="#"',
            $html
        );
    }

    private static function isSafeLinkUrl(string $url): bool
    {
        if ($url === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
            return false;
        }

        return preg_match('/^(https?:\/\/|mailto:|tel:|\/)/i', $url) === 1;
    }

    private static function isSafeImageUrl(string $url): bool
    {
        if ($url === '' || preg_match('/^\s*(javascript|data:text\/html|vbscript):/i', $url)) {
            return false;
        }

        return preg_match('/^(https?:\/\/|\/|data:image\/(?:png|jpe?g|gif|webp);base64,)/i', $url) === 1;
    }

    private static function isSafeVideoEmbedUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return false;
        }

        $allowedHosts = [
            'www.youtube.com',
            'youtube.com',
            'www.youtube-nocookie.com',
            'youtube-nocookie.com',
            'player.vimeo.com',
            'fast.wistia.net',
            'fast.wistia.com',
            'www.loom.com',
            'loom.com',
            'iframe.mediadelivery.net',
            'embed.cloudflarestream.com',
            'iframe.videodelivery.net',
            'player.hotmart.com',
            'videos.hotmart.com',
        ];

        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }
}
