<?php

namespace App\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The only HTML owner content may contain, for the few rich text fields:
 * paragraphs, line breaks, emphasis, lists and links to safe URLs. Every
 * other element is unwrapped to its text (script, style and similar are
 * dropped with their content); every attribute except a safe link's href is
 * removed. Applied when content is saved and again when it is rendered.
 */
final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a'];

    private const DROPPED = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math', 'template', 'noscript', 'textarea', 'select', 'option', 'button', 'input', 'form', 'link', 'meta', 'base', 'head', 'title', 'img', 'video', 'audio', 'source', 'picture', 'canvas'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body><div id="cg-sanitizer-root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($document))->query('//div[@id="cg-sanitizer-root"]')->item(0);
        if (! $root instanceof DOMElement) {
            return e(strip_tags($html));
        }

        self::cleanChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private static function cleanChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                // Comments, CDATA, processing instructions.
                $node->removeChild($child);

                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, self::DROPPED, true)) {
                $node->removeChild($child);

                continue;
            }

            self::cleanChildren($child);

            if (! in_array($name, self::ALLOWED, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $href = $name === 'a' ? $child->getAttribute('href') : null;
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }

            if ($name === 'a') {
                if ($href !== null && SafeUrl::isSafe($href)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'nofollow noopener noreferrer');
                } else {
                    // A link without a safe target is just its text.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
            }
        }
    }
}
