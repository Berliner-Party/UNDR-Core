<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Global HTML-escaping + prose shims. Pure functions (no dependencies). Each is
// function_exists-guarded so a site may predefine its own variant before the
// Composer autoloader runs (the site's wins). Identical across all UNDR sites.
// ---------------------------------------------------------------------------

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('attr')) {
    function attr($v): string { return h(is_scalar($v) ? (string) $v : json_encode($v)); }
}

if (!function_exists('nl2br_safe')) {
    function nl2br_safe(string $s): string { return nl2br(h($s), false); }
}

if (!function_exists('prose_html')) {
    /**
     * Render a long-form text field as proper HTML paragraphs.
     * Splits on blank lines (\n\n+) for paragraph breaks; converts single \n inside
     * a paragraph to <br>. Avoids the white-space:pre-line + nl2br double-spacing.
     */
    function prose_html(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $html = '';
        foreach (preg_split('/\n{2,}/', $s) as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $html .= '<p>' . nl2br(h($p), false) . '</p>';
        }
        return $html;
    }
}
