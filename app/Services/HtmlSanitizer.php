<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'h2', 'h3',
        'a', 'img', 'ul', 'ol', 'li', 'blockquote',
    ];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = $this->encodeNonAscii($html);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $this->purgeNodes($dom);

        $html = $dom->saveHTML();

        return $this->decodeNonAscii($html !== false ? $html : '');
    }

    private function purgeNodes(DOMDocument $dom): void
    {
        $nodes = $dom->getElementsByTagName('*');

        $toRemove = [];
        $elements = iterator_to_array($nodes);

        foreach ($elements as $node) {
            $tag = strtolower($node->nodeName ?? '');

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $toRemove[] = $node;

                continue;
            }

            $this->sanitizeAttributes($node);
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function sanitizeAttributes(DOMElement $node): void
    {
        $allowed = match (strtolower($node->nodeName ?? '')) {
            'a' => ['href', 'rel', 'target'],
            'img' => ['src', 'alt', 'width', 'height'],
            default => [],
        };

        foreach (iterator_to_array($node->attributes) as $attribute) {
            if (! in_array($attribute->nodeName, $allowed, true)) {
                $node->removeAttribute($attribute->nodeName);
            }
        }

        if ($node->hasAttribute('href') && ! $this->isSafeUrl($node->getAttribute('href'))) {
            $node->removeAttribute('href');
        }

        if ($node->hasAttribute('src') && ! $this->isSafeUrl($node->getAttribute('src'))) {
            $node->removeAttribute('src');
        }
    }

    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        return (bool) preg_match('#^https?://#i', $url);
    }

    private function encodeNonAscii(string $html): string
    {
        return mb_encode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        );
    }

    private function decodeNonAscii(string $html): string
    {
        return mb_decode_numericentity(
            $html,
            [0x80, 0x10FFFF, 0, 0x1FFFFF],
            'UTF-8'
        );
    }
}
