<?php
/**
 * HURON-PERTH CHILDREN'S AID SOCIETY LMS
 * Central brand configuration.
 *
 * Single source of truth for site name, logo, favicon and the CSS color
 * palette. To rebrand for a new client:
 *   1. Set SITE_NAME / LOGO_FILENAME / FAVICON_FILENAME in .env.
 *   2. Set the BRAND_* color variables in .env (or edit the defaults below).
 *   3. Upload the new logo to content/.
 * No per-page CSS or code changes are needed — every page pulls its :root
 * palette from renderBrandStyles().
 *
 * @package PP_LMS
 */

if (!function_exists('ppBrand')) {
    /**
     * @return array{name:string, logo:string, favicon:string, colors:array<string,string>}
     */
    function ppBrand(): array
    {
        $colors = [
            'primary'       => '#006F53',
            'primary-hover' => '#60B49A',
            'accent'        => '#A3D7C5',
            'danger'        => '#E4E348',
            'bg-body'       => '#F4F9F7',
            'bg-card'       => '#FFFFFF',
            'text-main'     => '#1A2E2A',
            'text-muted'    => '#8FA89E',
            'border'        => '#A3D7C5',
            'radius'        => '12px',
            'sidebar-width' => '280px',
            'admin-accent'  => '#60B49A',
        ];
        // .env overrides: BRAND_PRIMARY, BRAND_BG_BODY, BRAND_RADIUS, ...
        foreach ($colors as $key => $default) {
            $env = getenv('BRAND_' . strtoupper(str_replace('-', '_', $key)));
            if ($env !== false && $env !== '') {
                $colors[$key] = $env;
            }
        }

        return [
            'name'    => getenv('SITE_NAME') ?: 'Huron-Perth Children\'s Aid Society',
            'logo'    => getenv('LOGO_FILENAME') ?: 'hpcas.png',
            'favicon' => getenv('FAVICON_FILENAME') ?: 'hpcas.png',
            'colors'  => $colors,
        ];
    }
}

if (!function_exists('renderBrandStyles')) {
    /**
     * Emit the :root CSS custom properties for the active brand.
     * Call inside a page's <style> block in place of a hard-coded palette:
     *
     *     <style>
     *         <?php renderBrandStyles(); ?>
     *         .page-specific-css { ... }
     *     </style>
     *
     * Emits the --bg / --text short aliases too (used by some standalone pages).
     */
    function renderBrandStyles(): void
    {
        $b = ppBrand();
        $c = $b['colors'];
        $css = ':root {';
        foreach ($c as $k => $v) {
            $css .= "\n    --$k: $v;";
        }
        $css .= "\n    --bg: " . $c['bg-body'] . ';';
        $css .= "\n    --text: " . $c['text-main'] . ';';
        $css .= "\n}";
        echo $css;
    }
}
