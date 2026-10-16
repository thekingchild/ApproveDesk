<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'blockquote',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'code', 'pre', 'a',
    ];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="approvedesk-rich-text-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('approvedesk-rich-text-root');
        if ($root === null) {
            return '';
        }

        // Rebuild into a fresh document from the allow-list. Every text node is
        // created anew, so content parsed inside raw-text elements (xmp, noembed,
        // plaintext, ...) is always escaped on output (RV-SEC-03).
        $output = new DOMDocument('1.0', 'UTF-8');
        $container = $output->createElement('div');
        $output->appendChild($container);
        $this->copyChildren($root, $container, $output);

        $clean = '';
        foreach ($container->childNodes as $child) {
            $clean .= $output->saveHTML($child);
        }

        return trim($clean);
    }

    private function copyChildren(DOMNode $source, DOMNode $target, DOMDocument $output): void
    {
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMText) {
                $target->appendChild($output->createTextNode($child->textContent));

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->copyChildren($child, $target, $output);

                continue;
            }

            $element = $output->createElement($tag);
            $this->copyAttributes($child, $element, $tag);
            $target->appendChild($element);
            $this->copyChildren($child, $element, $output);
        }
    }

    private function copyAttributes(DOMElement $source, DOMElement $element, string $tag): void
    {
        if ($tag !== 'a') {
            return;
        }

        $href = trim($source->getAttribute('href'));
        if ($href !== '' && $this->safeLink($href)) {
            $element->setAttribute('href', $href);
        }

        if ($source->hasAttribute('title')) {
            $element->setAttribute('title', $source->getAttribute('title'));
        }

        if ($source->getAttribute('target') === '_blank') {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function safeLink(string $href): bool
    {
        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return true;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }
}
