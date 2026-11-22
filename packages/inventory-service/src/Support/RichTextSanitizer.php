<?php

declare(strict_types=1);

namespace NM\Services\InventoryService\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

final class RichTextSanitizer
{
    private const MAX_LENGTH = 1_000_000;
    private const MAX_INLINE_IMAGE_SIZE = 500_000;
    private const MAX_DEPTH = 100;
    private const MAX_NODES = 10_000;

    private const TAGS = [
        'p', 'div', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code',
        'ul', 'ol', 'li', 'a', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins',
        'sub', 'sup', 'span', 'mark', 'small', 'hr', 'img', 'table', 'thead', 'tbody', 'tfoot',
        'tr', 'th', 'td', 'colgroup', 'col', 'caption', 'figure', 'figcaption',
    ];

    private const DROP_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'title', 'meta',
        'link', 'head', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'audio',
        'video', 'canvas', 'applet', 'frame', 'frameset', 'base',
    ];

    private const VOID_TAGS = ['br', 'hr', 'img', 'col'];

    /** @var array<string, list<string>> */
    private const ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'referrerpolicy'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'col' => ['span'],
        'ol' => ['start', 'type', 'reversed'],
        'ul' => ['data-checklist'],
        'li' => ['data-checked'],
        '*' => ['style', 'dir', 'lang'],
    ];

    private const STYLE_PROPERTIES = [
        'color', 'background-color', 'font-size', 'font-family', 'font-weight', 'font-style',
        'text-decoration', 'text-decoration-line', 'text-align', 'line-height', 'margin',
        'margin-left', 'margin-right', 'padding-left', 'width', 'height', 'max-width', 'float',
        'display', 'vertical-align', 'letter-spacing', 'text-indent',
    ];

    private const BAD_CSS_VALUE = '/url\s*\(|image-set|image\s*\(|element\s*\(|cross-fade|paint\s*\(|var\s*\(|attr\s*\(|env\s*\(|expression|javascript:|vbscript:|data:|@import|behaviou?r|binding|\/\*|\*\/|[<>\\\\{}]/i';
    private const SAFE_CSS_CHARACTERS = '/^[\w\s#%.,()\'"+\/-]*$/u';
    private const SAFE_COLOR = '/^(#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([\d\s.,%\/a-z]+\)|[a-z]+)$/i';
    private const SAFE_FONT_FAMILY = '/^[\p{L}\p{N}\s,\'"_-]{1,120}$/u';

    public static function sanitize(string $html, bool $rejectInvalidInlineImages = false): string
    {
        if (preg_match('//u', $html) !== 1) {
            throw new InvalidArgumentException('Le contenu doit être encodé en UTF-8.');
        }
        if (self::utf8Length($html) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Le contenu dépasse la taille maximale autorisée.');
        }
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="rich-text-content-root">'
                    . $html
                    . '</div></body></html>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
        if (!$loaded) {
            throw new InvalidArgumentException('Le contenu ne peut pas être traité.');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $root = null;
        if ($body !== null) {
            foreach ($body->childNodes as $child) {
                if ($child instanceof DOMElement
                    && $child->tagName === 'div'
                    && $child->getAttribute('id') === 'rich-text-content-root') {
                    $root = $child;
                    break;
                }
            }
        }
        if (!$root instanceof DOMElement) {
            throw new InvalidArgumentException('Le contenu ne peut pas être traité.');
        }

        $nodeCount = 0;
        $safeHtml = '';
        foreach ($root->childNodes as $child) {
            $safeHtml .= self::serializeNode($child, 0, $nodeCount, $rejectInvalidInlineImages);
        }

        return $safeHtml;
    }

    public static function hasContent(string $html): bool
    {
        if (preg_match('/<(?:img|hr|table)(?:\s|>)/i', $html) === 1) {
            return true;
        }

        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $text = preg_replace('/[\s\p{Z}\x{200b}-\x{200f}\x{2028}\x{2029}\x{feff}]+/u', '', $text);

        return $text !== null && $text !== '';
    }

    private static function serializeNode(
        DOMNode $node,
        int $depth,
        int &$nodeCount,
        bool $rejectInvalidInlineImages
    ): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return htmlspecialchars(
                $node->nodeValue ?? '',
                ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
        }
        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP_TAGS, true)) {
            return '';
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('Le contenu est trop imbriqué.');
        }
        if (!in_array($tag, self::TAGS, true) && $tag !== 'font') {
            return self::serializeChildren($node, $depth + 1, $nodeCount, $rejectInvalidInlineImages);
        }

        ++$nodeCount;
        if ($nodeCount > self::MAX_NODES) {
            throw new InvalidArgumentException('Le contenu contient trop d’éléments.');
        }

        $outputTag = $tag === 'font' ? 'span' : $tag;
        $attributes = $tag === 'font'
            ? self::fontAttributes($node)
            : self::safeAttributes($node, $tag, $rejectInvalidInlineImages);
        if ($tag === 'img' && !isset($attributes['src'])) {
            return '';
        }
        if ($tag === 'a' && ($attributes['target'] ?? '') === '_blank') {
            $attributes['rel'] = 'noopener noreferrer';
        }

        $html = '<' . $outputTag;
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars(
                $value,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            ) . '"';
        }
        $html .= '>';
        if (in_array($outputTag, self::VOID_TAGS, true)) {
            return $html;
        }

        return $html . self::serializeChildren($node, $depth + 1, $nodeCount, $rejectInvalidInlineImages)
            . '</' . $outputTag . '>';
    }

    private static function serializeChildren(
        DOMNode $node,
        int $depth,
        int &$nodeCount,
        bool $rejectInvalidInlineImages
    ): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::serializeNode($child, $depth, $nodeCount, $rejectInvalidInlineImages);
        }

        return $html;
    }

    /** @return array<string, string> */
    private static function safeAttributes(
        DOMElement $element,
        string $tag,
        bool $rejectInvalidInlineImages
    ): array
    {
        $allowed = array_merge(self::ATTRIBUTES[$tag] ?? [], self::ATTRIBUTES['*']);
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            if ($name === 'href' && !self::safeHref($value)) {
                continue;
            }
            if ($name === 'src' && !self::safeSource($value)) {
                if ($rejectInvalidInlineImages && str_starts_with(strtolower(trim($value)), 'data:')) {
                    throw new InvalidArgumentException(
                        'Une image intégrée doit être une image raster valide de 500 Ko maximum.'
                    );
                }
                continue;
            }
            if ($name === 'style') {
                $value = self::sanitizeStyle($value);
                if ($value === '') {
                    continue;
                }
            }
            if ($name === 'target' && $value !== '_blank') {
                continue;
            }
            if ($name === 'data-checked' && ($value !== 'true' || $tag !== 'li')) {
                continue;
            }
            if ($name === 'data-checklist' && $tag !== 'ul') {
                continue;
            }
            if (in_array($name, ['width', 'height'], true)
                && preg_match('/^\d+(?:\.\d+)?%?$/', $value) !== 1) {
                continue;
            }
            if (in_array($name, ['colspan', 'rowspan', 'span', 'start'], true)
                && preg_match('/^\d{1,3}$/', $value) !== 1) {
                continue;
            }
            if ($name === 'dir' && preg_match('/^(ltr|rtl)$/i', $value) !== 1) {
                continue;
            }
            if ($name === 'lang' && preg_match('/^[a-z-]{2,12}$/i', $value) !== 1) {
                continue;
            }
            if ($name === 'loading' && !in_array($value, ['lazy', 'eager'], true)) {
                continue;
            }
            if ($name === 'referrerpolicy'
                && !in_array($value, [
                    'no-referrer', 'no-referrer-when-downgrade', 'same-origin', 'origin',
                    'strict-origin', 'strict-origin-when-cross-origin',
                ], true)) {
                continue;
            }
            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /** @return array<string, string> */
    private static function fontAttributes(DOMElement $element): array
    {
        $style = [];
        $size = $element->getAttribute('size');
        $fontSizes = [1 => '10px', 2 => '13px', 3 => '16px', 4 => '18px', 5 => '24px', 6 => '32px'];
        if (isset($fontSizes[(int) $size]) && preg_match('/^[1-6]$/', $size) === 1) {
            $style[] = 'font-size: ' . $fontSizes[(int) $size];
        }

        $color = $element->getAttribute('color');
        if ($color !== '' && preg_match('/^[#\w(),.\s%-]{1,40}$/u', $color) === 1) {
            $style[] = 'color: ' . $color;
        }
        $font = $element->getAttribute('face');
        if ($font !== '' && preg_match('/^[\w\s,\'"-]{1,80}$/u', $font) === 1) {
            $style[] = 'font-family: ' . $font;
        }
        $style[] = $element->getAttribute('style');
        $safeStyle = self::sanitizeStyle(implode(';', $style));

        return $safeStyle === '' ? [] : ['style' => $safeStyle];
    }

    private static function sanitizeStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            $separator = strpos($declaration, ':');
            if ($separator === false || $separator < 1) {
                continue;
            }
            $property = strtolower(trim(substr($declaration, 0, $separator)));
            $value = trim((string) preg_replace('/\s*!important\s*$/i', '', substr($declaration, $separator + 1)));
            if (!in_array($property, self::STYLE_PROPERTIES, true)
                || $value === ''
                || strlen($value) > 200
                || preg_match(self::BAD_CSS_VALUE, $value) === 1
                || preg_match(self::SAFE_CSS_CHARACTERS, $value) !== 1) {
                continue;
            }
            if (in_array($property, ['color', 'background-color'], true)
                && preg_match(self::SAFE_COLOR, $value) !== 1) {
                continue;
            }
            if ($property === 'font-family' && preg_match(self::SAFE_FONT_FAMILY, $value) !== 1) {
                continue;
            }
            if ($property === 'display' && !in_array(strtolower($value), ['block', 'inline'], true)) {
                continue;
            }
            if ($property === 'float' && !in_array(strtolower($value), ['left', 'right', 'none'], true)) {
                continue;
            }
            $safe[] = $property . ': ' . $value;
        }

        return implode('; ', $safe);
    }

    private static function safeHref(string $url): bool
    {
        $url = self::stripControls($url);
        if ($url === '' || str_contains($url, '\\')) {
            return $url === '';
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) !== 1) {
            return true;
        }

        $scheme = strtolower($matches[1]);
        if (in_array($scheme, ['http', 'https'], true)) {
            return self::hasValidHttpHost($url);
        }
        if ($scheme === 'mailto') {
            return preg_match('/^mailto:[^\s@?&<>"\']+@[^\s@?&<>"\']+\.[^\s@?&<>"\']+$/i', $url) === 1;
        }
        if ($scheme === 'tel') {
            return preg_match('/^tel:[+\d().-]{3,30}$/i', $url) === 1;
        }

        return false;
    }

    private static function safeSource(string $url): bool
    {
        $url = self::stripControls($url);
        if ($url === '' || str_contains($url, '\\')) {
            return false;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) !== 1) {
            return true;
        }

        $scheme = strtolower($matches[1]);
        if (in_array($scheme, ['http', 'https'], true)) {
            return self::hasValidHttpHost($url);
        }
        if (preg_match('/^data:image\/(png|jpe?g|gif|webp|avif|bmp);base64,([a-z0-9+\/=]+)$/i', $url, $matches) !== 1) {
            return false;
        }

        $image = base64_decode($matches[2], true);
        if (!is_string($image) || strlen($image) > self::MAX_INLINE_IMAGE_SIZE) {
            return false;
        }
        $imageInfo = @getimagesizefromstring($image);
        if (!is_array($imageInfo) || !isset($imageInfo['mime'])) {
            return false;
        }
        $declaredMime = match (strtolower($matches[1])) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            default => '',
        };

        return $declaredMime !== '' && strtolower((string) $imageInfo['mime']) === $declaredMime;
    }

    private static function stripControls(string $value): string
    {
        return (string) preg_replace('/[\x00-\x20\x7f-\x9f\x{200b}-\x{200f}\x{2028}\x{2029}\x{feff}]+/u', '', $value);
    }

    private static function hasValidHttpHost(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private static function utf8Length(string $value): int
    {
        return strlen((string) preg_replace('/[\x80-\xbf]/', '', $value));
    }
}
