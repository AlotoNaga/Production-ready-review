<?php
/**
 * Nagaland Me Experts Child Theme — functions.php
 *
 * @package nagaland-me-experts-child
 * @version 1.2.11
 *
 * v1.2.11:
 *   - Mobile logo polish: wordmark and pills now white-space:nowrap
 *     so NAGALAND / ME / EXPERTS never break across lines inside
 *     their boxes. Smaller font + badge sizes on <600px and <380px
 *     breakpoints; on very narrow screens the gold EXPERTS pill
 *     gracefully hides (badge still carries the EXPERTS identity).
 *
 * v1.2.10:
 *   - Mobile logo: JS injection now targets every Astra header
 *     container (desktop + mobile + above + below), de-duplicated
 *     via data-attribute so each wrap gets exactly one logo.
 *   - Closed the thin white gap between header and first hero on
 *     dark-background pages. Body bg now transparent + zero top
 *     margin/padding on all first-child wrappers below masthead.
 *     Gold header divider retained and now sits flush against
 *     the hero as an intentional premium gold rule.
 *
 * v1.2.9:
 *   - Added JavaScript DOM-injection fallback for Astra Header
 *     Builder layouts that have no Site Identity element placed
 *     at all. JS finds the rendered header wrap and inserts the
 *     compact-light logo as the first child of the first header
 *     row. Guarantees the logo appears regardless of Astra layout.
 *
 * v1.2.8:
 *   - Bumped get_custom_logo / has_custom_logo filter priorities to 999
 *     so nothing else can override our logo injection.
 *   - Added Astra-specific fallback: astra_masthead_branding action
 *     renders the logo even if the Astra header-builder layout skips
 *     the core get_custom_logo path.
 *
 * v1.2.7:
 *   - Auto-inject [nme_logo] into Astra site header via WordPress
 *     get_custom_logo / has_custom_logo filters. No manual shortcode
 *     paste required. Hides Astra's default site-title and tagline
 *     so they don't duplicate. Disable with:
 *         add_filter( 'nme_auto_header_logo', '__return_false' );
 *
 * v1.2.6:
 *   - New [nme_logo] shortcode — premium brand mark for experts.nagaland.me
 *     distinct from the parent nagaland.me magenta/play logo. Inline SVG
 *     badge (forest→emerald gradient + gold checkmark + ascending arrow)
 *     paired with NAGALAND.ME wordmark and gold "EXPERTS" pill/kicker.
 *     Variants: full | compact | inline | mark. Themes: dark | light.
 *
 * v1.2.5:
 *   - CSS-only cleanup: remove "stacked paper / piles of pages" artifact
 *     caused by Astra's nested container wrappers (site-content >
 *     ast-container > article > content-area) each rendering their own
 *     padding/border/shadow. Flattens every wrapper to a single seamless
 *     surface. Additive-only — no existing rules modified.
 *
 * v1.2.4:
 *   - New [nme_about_hero]        cream editorial hero with gold divider
 *   - New [nme_about_values]      6 SVG value cards (replaces emoji grid)
 *   - New [nme_about_family]      6 sister-site cards with real logos + links
 *   - New [nme_trust_hero]        forest + gold-glow hero (distinct from home)
 *   - New [nme_trust_verify]      6 SVG verification cards
 *   - New [nme_trust_guarantees]  6 SVG-check buyer-guarantee cards
 *   - Added SVG icons: handshake, gem, scales, seedling, certificate,
 *                       id-card, credit-card, folder, user-check
 *
 * v1.2.3:
 *   - New [nme_why_choose_us] shortcode for homepage (buyer-facing, 6 SVG cards)
 *
 * v1.2.2:
 *   - New [nme_expert_hero] with emerald→forest diagonal gradient
 *   - New [nme_expert_why] with 6 brand SVG icons (no emoji)
 *   - New [nme_expert_founding] with SVG check marks
 *   - New [nme_expert_cta] for final apply section (no emoji)
 *   - [nme_categories] now uses brand SVG icons + accepts title/subtitle atts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NME_CHILD_VERSION', '1.2.11' );
define( 'NME_CHILD_DIR', get_stylesheet_directory() );
define( 'NME_CHILD_URL', get_stylesheet_directory_uri() );

/* ============================================================
   1. ENQUEUE STYLES & SCRIPTS
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'nme_child_enqueue_assets', 15 );
function nme_child_enqueue_assets() {

    wp_enqueue_style(
        'astra-parent-style',
        get_template_directory_uri() . '/style.css',
        array(),
        wp_get_theme( 'astra' )->get( 'Version' )
    );

    wp_enqueue_style(
        'nme-child-style',
        get_stylesheet_uri(),
        array( 'astra-parent-style' ),
        NME_CHILD_VERSION
    );

    if ( file_exists( NME_CHILD_DIR . '/assets/js/main.js' ) ) {
        wp_enqueue_script(
            'nme-child-main',
            NME_CHILD_URL . '/assets/js/main.js',
            array( 'jquery' ),
            NME_CHILD_VERSION,
            true
        );
    }
}

/* ============================================================
   2. NORMALIZE NME SHORTCODES — THE ACTUAL FIX
   Multi-line shortcodes break WordPress's parser. This filter:
   1. Finds every [nme_*] shortcode
   2. Converts curly quotes to straight quotes
   3. Strips <p> and <br> WordPress auto-inserts
   4. Collapses multi-line attributes to single line
   Runs BEFORE WordPress parses shortcodes (priority 7, before do_shortcode at 11).
   ============================================================ */
add_filter( 'the_content', 'nme_normalize_shortcodes', 7 );
function nme_normalize_shortcodes( $content ) {
    if ( strpos( $content, '[nme_' ) === false ) {
        return $content;
    }

    return preg_replace_callback(
        '/\[nme_[a-z_]+[^\]]*\]/s',
        function( $match ) {
            $shortcode = $match[0];

            // 1. Convert curly quotes to straight quotes
            $shortcode = str_replace(
                array( '“', '”', '‘', '’', '&#8220;', '&#8221;', '&#8216;', '&#8217;' ),
                '"',
                $shortcode
            );

            // 2. Strip <p> and <br> tags WordPress auto-inserts
            $shortcode = preg_replace( '/<\/?p[^>]*>/', '', $shortcode );
            $shortcode = preg_replace( '/<br\s*\/?>/', '', $shortcode );

            // 3. Collapse all whitespace (newlines, tabs, multiple spaces) to single space
            $shortcode = preg_replace( '/\s+/', ' ', $shortcode );

            return $shortcode;
        },
        $content
    );
}

/* ============================================================
   3. THEME SUPPORT & SETUP
   ============================================================ */
add_action( 'after_setup_theme', 'nme_child_theme_setup' );
function nme_child_theme_setup() {

    register_nav_menus( array(
        'primary' => __( 'Primary Menu', 'nagaland-me-experts-child' ),
        'footer'  => __( 'Footer Menu', 'nagaland-me-experts-child' ),
    ) );

    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'custom-logo', array(
        'height'      => 60,
        'width'       => 200,
        'flex-height' => true,
        'flex-width'  => true,
    ) );
    add_theme_support( 'html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ) );
}

/* ============================================================
   4. BRAND CUSTOMIZATIONS
   ============================================================ */
add_filter( 'astra_site_branding_markup', 'nme_add_experts_tag', 99 );
function nme_add_experts_tag( $html ) {
    if ( strpos( $html, 'nme-experts-tag' ) === false ) {
        $tag = '<span class="nme-experts-tag">EXPERTS</span>';
        $html = '<div class="nme-brand-wrap">' . $html . $tag . '</div>';
    }
    return $html;
}

add_filter( 'astra_footer_copyright', 'nme_custom_footer_copyright', 100 );
function nme_custom_footer_copyright( $content ) {
    $year = date( 'Y' );
    return '© ' . $year . ' Nagaland Me Experts | A venture of Nagaland Me | GST: 13DIHPA5679B1ZK | Dimapur, Nagaland, India';
}

add_action( 'wp_head', 'nme_hide_astra_footer_css' );
function nme_hide_astra_footer_css() {
    echo '<style>
        .ast-small-footer-section,
        .ast-small-footer .ast-container,
        .ast-footer-copyright,
        .site-footer,
        .footer-adv,
        .ast-footer-overlay,
        .site-below-footer-wrap,
        .site-primary-footer-wrap {
            display: none !important;
        }
    </style>';
}

add_action( 'wp_footer', 'nme_render_custom_footer' );
function nme_render_custom_footer() {
    $year = date( 'Y' );
    ?>
    <div class="nme-custom-footer-bar" style="background: #081410; color: rgba(255,255,255,0.7); padding: 20px 16px; text-align: center; font-size: 0.85rem; border-top: 4px solid #D4A843; font-family: 'Outfit', sans-serif;">
        © <?php echo esc_html( $year ); ?> Nagaland Me Experts &nbsp;|&nbsp;
        A venture of Nagaland Me &nbsp;|&nbsp;
        GST: 13DIHPA5679B1ZK &nbsp;|&nbsp;
        Dimapur, Nagaland, India
    </div>
    <?php
}

/* ============================================================
   5. SHORTCODES
   ============================================================ */

add_shortcode( 'nme_hero', 'nme_hero_shortcode' );
function nme_hero_shortcode( $atts ) {
    $defaults = array(
        'title'              => 'Grow Your YouTube & Facebook Channels with Verified Experts',
        'highlight'          => 'from Nagaland',
        'subtitle'           => 'Monetization stuck? Videos not going viral? Need thumbnails, video editing, or tax-form help? Hire a verified expert from Nagaland, Northeast India —',
        'subtitle_highlight' => 'pay only when you\'re happy.',
        'cta1_text'          => 'Find an Expert',
        'cta1_url'           => '/categories/',
        'cta2_text'          => 'Become an Expert & Start Earning',
        'cta2_url'           => '/become-an-expert/',
    );

    if ( ! is_array( $atts ) ) {
        $atts = array();
    }

    $a = shortcode_atts( $defaults, $atts, 'nme_hero' );

    ob_start(); ?>
    <section class="nme-hero">
        <div class="nme-container">
            <h1>
                <?php echo wp_kses_post( $a['title'] ); ?>
                <?php if ( ! empty( $a['highlight'] ) ) : ?>
                    <span class="nme-gold-text"><?php echo wp_kses_post( $a['highlight'] ); ?></span>
                <?php endif; ?>
            </h1>
            <?php if ( ! empty( $a['subtitle'] ) || ! empty( $a['subtitle_highlight'] ) ) : ?>
                <p class="lead">
                    <?php echo wp_kses_post( $a['subtitle'] ); ?>
                    <?php if ( ! empty( $a['subtitle_highlight'] ) ) : ?>
                        <span class="nme-emerald-text"><?php echo wp_kses_post( $a['subtitle_highlight'] ); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="nme-hero-cta">
                <?php if ( ! empty( $a['cta1_text'] ) && ! empty( $a['cta1_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta1_url'] ); ?>" class="nme-btn nme-btn-gold">
                        <?php echo esc_html( $a['cta1_text'] ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( ! empty( $a['cta2_text'] ) && ! empty( $a['cta2_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta2_url'] ); ?>" class="nme-btn nme-btn-outline" style="background: rgba(255,255,255,0.1) !important; color: #fff !important; border-color: #fff !important;">
                        <?php echo esc_html( $a['cta2_text'] ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_trust_strip', 'nme_trust_strip_shortcode' );
function nme_trust_strip_shortcode() {
    ob_start(); ?>
    <section class="nme-trust-strip">
        <div class="nme-container">
            <div class="nme-grid nme-grid-4">
                <div>
                    <div class="nme-trust-stat">100%</div>
                    <div class="nme-trust-label">Secure Escrow</div>
                </div>
                <div>
                    <div class="nme-trust-stat">8</div>
                    <div class="nme-trust-label">Service Categories</div>
                </div>
                <div>
                    <div class="nme-trust-stat">24/7</div>
                    <div class="nme-trust-label">Buyer Protection</div>
                </div>
                <div>
                    <div class="nme-trust-stat">500K+</div>
                    <div class="nme-trust-label">Trusted Audience</div>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/**
 * Brand SVG icon set — single source of truth.
 * Keys referenced from shortcodes via nme_svg_icon().
 */
function nme_svg_icon( $name ) {
    $icons = array(
        'wallet'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4z"/></svg>',
        'shield'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
        'chart'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17 9 11l4 4 8-8"/><path d="M14 7h7v7"/></svg>',
        'globe'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
        'star'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="12 2 15.1 8.6 22 9.6 17 14.5 18.2 21.5 12 18.2 5.8 21.5 7 14.5 2 9.6 8.9 8.6 12 2"/></svg>',
        'phone'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7 12.8 12.8 0 0 0 .7 2.8 2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5 12.8 12.8 0 0 0 2.8.7 2 2 0 0 1 1.8 2.1z"/></svg>',
        'check'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>',
        'document'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6M9 9h2"/></svg>',
        'coin'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M15 9.5a3 3 0 0 0-3-1.5c-1.7 0-3 1-3 2.2s1.3 1.8 3 2.1c1.7.3 3 .9 3 2.1S13.7 16 12 16a3 3 0 0 1-3-1.5"/></svg>',
        'facebook'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
        'rocket'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.7-.8.7-2.2 0-3a2.1 2.1 0 0 0-3 0z"/><path d="M12 15 9 12a9 9 0 0 1 3-7 12 12 0 0 1 8-4 12 12 0 0 1-4 8 9 9 0 0 1-7 3z"/><path d="M9 12H5l1.5-3a2 2 0 0 1 1.8-1H11M12 15v4l3-1.5a2 2 0 0 0 1-1.8V12"/></svg>',
        'palette'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22a10 10 0 1 1 10-10c0 2.8-2.2 4-5 4h-2a2 2 0 0 0-2 2 2 2 0 0 1-2 2 1 1 0 0 1-1 1z"/><circle cx="7.5" cy="10.5" r="1"/><circle cx="12" cy="7" r="1"/><circle cx="16.5" cy="10.5" r="1"/></svg>',
        'video'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="14" height="12" rx="2"/><path d="m22 8-6 4 6 4z"/></svg>',
        'bell'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>',
        'clipboard'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 13h6M9 17h4"/></svg>',
        'bar-chart'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><rect x="6" y="13" width="3" height="8"/><rect x="11" y="8" width="3" height="13"/><rect x="16" y="4" width="3" height="17"/></svg>',
        'handshake'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 17 8 14l-3 3 3 3z"/><path d="m14 11 3-3 3 3-3 3z"/><path d="M8 14 4 10a2 2 0 0 1 0-3l3-3a2 2 0 0 1 3 0l2 2 2-2a2 2 0 0 1 3 0l4 4a2 2 0 0 1 0 3l-3 3"/><path d="m11 17 3 3 3-3"/></svg>',
        'gem'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3-4 6 10 13L22 9l-4-6z"/><path d="M2 9h20M7 9l5 13M17 9l-5 13M12 3v6"/></svg>',
        'scales'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18M5 7h14M8 21h8"/><path d="m5 7-3 7a3 3 0 0 0 6 0z"/><path d="m19 7-3 7a3 3 0 0 0 6 0z"/></svg>',
        'seedling'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21v-9"/><path d="M12 12c0-4 3-7 7-7 0 4-3 7-7 7z"/><path d="M12 12c0-3-2-6-6-6 0 3 2 6 6 6z"/><path d="M5 21h14"/></svg>',
        'certificate' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20l4-2 4 2v-4"/><circle cx="12" cy="10" r="2.5"/></svg>',
        'id-card'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M5 17c.5-2 2-3 4-3s3.5 1 4 3M14 10h5M14 13h5M14 16h3"/></svg>',
        'credit-card' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>',
        'folder'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
        'user-check'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="8" r="4"/><path d="M2 21c0-4 3.5-7 8-7s8 3 8 7"/><path d="m16 11 2 2 4-4"/></svg>',
        'arrow-right' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    );
    return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

add_shortcode( 'nme_categories', 'nme_categories_shortcode' );
function nme_categories_shortcode( $atts ) {
    if ( ! is_array( $atts ) ) {
        $atts = array();
    }
    $a = shortcode_atts( array(
        'title'    => 'Browse by Category',
        'subtitle' => "Eight focused categories built for Northeast India's creators.",
    ), $atts, 'nme_categories' );

    // Slugs match wp_nme_categories.slug exactly (seeded by NME_Categories::
    // default_categories() in nagaland-me-experts/includes/class-categories.php).
    // Each card links to /services/?category=<slug> which sc_browse_services()
    // in nme-payments reads and filters on.
    $categories = array(
        array( 'icon' => 'coin',      'slug' => 'youtube-monetization',  'title' => 'YouTube Monetization & AdSense', 'desc' => 'AdSense PIN, monetization eligibility, ads not showing fixes.' ),
        array( 'icon' => 'facebook',  'slug' => 'facebook-monetization', 'title' => 'Facebook Page Monetization',     'desc' => 'In-stream ads, Reels Play bonus, brand collab setup.' ),
        array( 'icon' => 'rocket',    'slug' => 'channel-setup',         'title' => 'Channel Setup & Optimization',   'desc' => 'New channels, branding, SEO, About page optimization.' ),
        array( 'icon' => 'palette',   'slug' => 'thumbnail-design',      'title' => 'Thumbnail Design',               'desc' => 'Custom thumbnails, packs, A/B test variants, templates.' ),
        array( 'icon' => 'video',     'slug' => 'video-editing',         'title' => 'Video Editing',                  'desc' => 'Reels, Shorts, vlogs, tutorials, color grading, captions.' ),
        array( 'icon' => 'bell',      'slug' => 'animations',            'title' => 'Subscribe & Bell Animations',    'desc' => 'Custom subscribe, bell, end screen, intro and outro animations.' ),
        array( 'icon' => 'clipboard', 'slug' => 'tax-pin-help',          'title' => 'Tax & PIN Verification Help',    'desc' => 'Indian creator tax, W-8BEN forms, AdSense PIN guidance.' ),
        array( 'icon' => 'bar-chart', 'slug' => 'channel-audit',         'title' => 'Channel Audit & Strategy',       'desc' => 'Full audits, growth strategy, niche selection, 1-on-1 calls.' ),
    );

    // Look up how many active services each category currently has, so every
    // card can show an honest depth badge ("3 experts available" vs "Coming
    // soon"). One LEFT JOIN query, 8 rows max, indexed, sub-millisecond. If
    // the query fails (e.g. plugin disabled, table missing), $counts stays
    // empty and every card falls back to "Coming soon" — never fatal.
    $counts  = array();
    global $wpdb;
    $cat_tbl = $wpdb->prefix . 'nme_categories';
    $svc_tbl = $wpdb->prefix . 'nmep_services';
    $rows    = $wpdb->get_results( "
        SELECT c.slug, COUNT(s.id) AS cnt
        FROM $cat_tbl c
        LEFT JOIN $svc_tbl s ON s.category_id = c.id AND s.status = 'active'
        WHERE c.is_active = 1
        GROUP BY c.slug
    " );
    if ( is_array( $rows ) ) {
        foreach ( $rows as $r ) {
            $counts[ $r->slug ] = (int) $r->cnt;
        }
    }

    ob_start(); ?>
    <section class="nme-section">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2><?php echo esc_html( $a['title'] ); ?></h2>
                <p><?php echo esc_html( $a['subtitle'] ); ?></p>
            </div>
            <div class="nme-grid nme-grid-4">
                <?php foreach ( $categories as $cat ) :
                    $count        = isset( $counts[ $cat['slug'] ] ) ? (int) $counts[ $cat['slug'] ] : 0;
                    $has_services = $count > 0;
                    $badge        = $has_services
                        ? ( $count === 1 ? '1 expert available' : $count . ' experts available' )
                        : 'Coming soon';
                    $url          = esc_url( home_url( '/services/?category=' . rawurlencode( $cat['slug'] ) ) );
                ?>
                    <?php if ( $has_services ) : ?>
                    <a href="<?php echo $url; ?>" class="nme-card" style="text-decoration:none; color:inherit;">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $cat['icon'] ); ?></div>
                        <h3><?php echo esc_html( $cat['title'] ); ?></h3>
                        <p><?php echo esc_html( $cat['desc'] ); ?></p>
                        <div style="margin-top:12px; color:var(--nme-emerald,#10B981); font-weight:600; font-size:0.9rem;">
                            <?php echo esc_html( $badge ); ?> <span aria-hidden="true">→</span>
                        </div>
                    </a>
                    <?php else : ?>
                    <div class="nme-card" style="opacity:0.75;">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $cat['icon'] ); ?></div>
                        <h3><?php echo esc_html( $cat['title'] ); ?></h3>
                        <p><?php echo esc_html( $cat['desc'] ); ?></p>
                        <div style="margin-top:12px; color:var(--nme-text-light,#6B7280); font-weight:500; font-size:0.85rem;">
                            <?php echo esc_html( $badge ); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- Expert page v1.2.2 shortcodes ---------- */

add_shortcode( 'nme_expert_hero', 'nme_expert_hero_shortcode' );
function nme_expert_hero_shortcode( $atts ) {
    $defaults = array(
        'title'     => "You've got the skills.",
        'highlight' => "We've got the buyers.",
        'subtitle'  => 'Set your prices. Work on your terms. Get paid the moment the buyer says yes.',
        'cta1_text' => 'Apply Now',
        'cta1_url'  => '#apply',
        'cta2_text' => 'See How It Works',
        'cta2_url'  => '/how-it-works/',
    );
    if ( ! is_array( $atts ) ) { $atts = array(); }
    $a = shortcode_atts( $defaults, $atts, 'nme_expert_hero' );

    ob_start(); ?>
    <section class="nme-hero nme-hero-expert">
        <div class="nme-container">
            <h1>
                <?php echo wp_kses_post( $a['title'] ); ?>
                <?php if ( ! empty( $a['highlight'] ) ) : ?>
                    <span class="nme-gold-text"><?php echo wp_kses_post( $a['highlight'] ); ?></span>
                <?php endif; ?>
            </h1>
            <?php if ( ! empty( $a['subtitle'] ) ) : ?>
                <p class="lead"><?php echo wp_kses_post( $a['subtitle'] ); ?></p>
            <?php endif; ?>
            <div class="nme-hero-cta">
                <?php if ( ! empty( $a['cta1_text'] ) && ! empty( $a['cta1_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta1_url'] ); ?>" class="nme-btn nme-btn-gold"><?php echo esc_html( $a['cta1_text'] ); ?></a>
                <?php endif; ?>
                <?php if ( ! empty( $a['cta2_text'] ) && ! empty( $a['cta2_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta2_url'] ); ?>" class="nme-btn nme-btn-outline" style="background: rgba(255,255,255,0.1) !important; color: #fff !important; border-color: #fff !important;"><?php echo esc_html( $a['cta2_text'] ); ?></a>
                <?php endif; ?>
            </div>
            <ul class="nme-hero-trust">
                <li><span class="nme-hero-trust-icon"><?php echo nme_svg_icon( 'check' ); ?></span> No upfront fees</li>
                <li><span class="nme-hero-trust-icon"><?php echo nme_svg_icon( 'check' ); ?></span> Paid on delivery</li>
                <li><span class="nme-hero-trust-icon"><?php echo nme_svg_icon( 'check' ); ?></span> Set your own rates</li>
            </ul>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_expert_why', 'nme_expert_why_shortcode' );
function nme_expert_why_shortcode() {
    $cards = array(
        array( 'icon' => 'wallet', 'title' => 'You keep more',
            'desc' => "15% commission — that's it. Fiverr takes 20%, Upwork takes up to 20%. And if you're one of our first 20 experts? Zero commission for 3 months." ),
        array( 'icon' => 'shield', 'title' => 'No more chasing payments',
            'desc' => 'Every buyer pays upfront. The money sits in escrow before you start working. No ghosting, no excuses, no "I\'ll pay next week."' ),
        array( 'icon' => 'chart',  'title' => 'We bring the clients',
            'desc' => 'You focus on your craft. We market the platform to our 500K+ audience. When someone needs help, they land on your listing.' ),
        array( 'icon' => 'globe',  'title' => 'Built for you, not Silicon Valley',
            'desc' => 'INR pricing. UPI payouts. Nagamese support. This platform was made by someone from Nagaland, for people from Nagaland.' ),
        array( 'icon' => 'star',   'title' => 'Your reputation grows with you',
            'desc' => 'Complete orders, earn reviews, climb tiers, get more visibility. Top experts get homepage placement and faster payouts.' ),
        array( 'icon' => 'phone',  'title' => 'Real support, not a ticket queue',
            'desc' => "WhatsApp the founder directly if something's wrong. Try doing that on Fiverr." ),
    );

    ob_start(); ?>
    <section class="nme-section">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>Why this is different from freelancing alone</h2>
            </div>
            <div class="nme-grid nme-grid-3">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="nme-card">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $c['icon'] ); ?></div>
                        <h3><?php echo esc_html( $c['title'] ); ?></h3>
                        <p><?php echo esc_html( $c['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_expert_founding', 'nme_expert_founding_shortcode' );
function nme_expert_founding_shortcode() {
    $benefits = array(
        array( 'strong' => 'Permanent "Founding Expert" badge', 'rest' => ' — visible on every listing, forever' ),
        array( 'strong' => '0% commission for 3 months',        'rest' => ' — keep every rupee you earn' ),
        array( 'strong' => 'Top placement in search',           'rest' => ' — your listings show first' ),
        array( 'strong' => 'Homepage feature',                  'rest' => ' — rotating spotlight for founding experts' ),
        array( 'strong' => 'Direct line to the founder',        'rest' => ' — your feedback shapes what we build' ),
        array( 'strong' => 'Promotion on Aloto Naga TV',        'rest' => ' — 500K subscribers see your work' ),
    );
    $requirements = array(
        'Skilled in creator services — thumbnails, editing, monetization, channel growth, animations, tax help',
        'Based in Northeast India (preferred, not required)',
        'Can show past work — portfolio, channel, or examples',
        'Valid PAN card and bank account',
        'Government photo ID (Aadhaar, Voter ID, or Passport)',
        'Willing to deliver quality work on time, every time',
    );

    ob_start(); ?>
    <section class="nme-section nme-section-cream">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>Founding Expert Program</h2>
                <p>The first 20 experts get permanent benefits. Once they're gone, they're gone.</p>
            </div>
            <div class="nme-grid nme-grid-2">
                <div class="nme-card">
                    <span class="nme-badge">EXCLUSIVE — LIMITED SPOTS</span>
                    <h3 class="nme-mt-2">What you get</h3>
                    <ul class="nme-checklist nme-checklist-gold">
                        <?php foreach ( $benefits as $b ) : ?>
                            <li><span class="nme-check-icon"><?php echo nme_svg_icon( 'check' ); ?></span><span><strong><?php echo esc_html( $b['strong'] ); ?></strong><?php echo esc_html( $b['rest'] ); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="nme-card">
                    <span class="nme-badge nme-badge-emerald">REQUIREMENTS</span>
                    <h3 class="nme-mt-2">Who we're looking for</h3>
                    <ul class="nme-checklist">
                        <?php foreach ( $requirements as $r ) : ?>
                            <li><span class="nme-check-icon"><?php echo nme_svg_icon( 'check' ); ?></span><span><?php echo esc_html( $r ); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_expert_cta', 'nme_expert_cta_shortcode' );
function nme_expert_cta_shortcode( $atts ) {
    $defaults = array(
        'title'    => 'Ready? It takes 5 minutes.',
        'subtitle' => 'Fill out the form. We review every application personally and respond within 3–5 business days.',
        'card_h'   => 'Quick online application',
        'card_p'   => "Your skills, your portfolio, your payout details. That's all we need to get started.",
        'button'   => 'Apply Now',
        'url'      => '/expert-register/',
        'wa_label' => 'Questions? WhatsApp us at',
        'wa_num'   => '+91 63833 59495',
        'wa_url'   => 'https://wa.me/916383359495',
        'anchor'   => 'apply',
    );
    if ( ! is_array( $atts ) ) { $atts = array(); }
    $a = shortcode_atts( $defaults, $atts, 'nme_expert_cta' );

    ob_start(); ?>
    <section class="nme-section nme-section-forest" id="<?php echo esc_attr( $a['anchor'] ); ?>">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2 style="color: var(--nme-white) !important;"><?php echo esc_html( $a['title'] ); ?></h2>
                <p style="color: rgba(255,255,255,0.85); font-size: 1.15rem; max-width: 640px; margin: 0 auto;"><?php echo esc_html( $a['subtitle'] ); ?></p>
            </div>
            <div class="nme-card nme-cta-card">
                <div class="nme-cta-icon nme-icon-svg"><?php echo nme_svg_icon( 'document' ); ?></div>
                <h3><?php echo esc_html( $a['card_h'] ); ?></h3>
                <p class="nme-cta-card-lead"><?php echo esc_html( $a['card_p'] ); ?></p>
                <div class="nme-hero-cta" style="margin-top: 8px;">
                    <a href="<?php echo esc_url( $a['url'] ); ?>" class="nme-btn nme-btn-gold"><?php echo esc_html( $a['button'] ); ?></a>
                </div>
                <p class="nme-cta-wa">
                    <?php echo esc_html( $a['wa_label'] ); ?>
                    <a href="<?php echo esc_url( $a['wa_url'] ); ?>"><?php echo esc_html( $a['wa_num'] ); ?></a>
                </p>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_how_it_works', 'nme_how_it_works_shortcode' );
function nme_how_it_works_shortcode() {
    $steps = array(
        array( 'num' => '1', 'title' => 'Find an Expert', 'desc' => 'Browse verified experts by category, price, rating, and delivery time.' ),
        array( 'num' => '2', 'title' => 'Place Your Order', 'desc' => 'Pay securely via Razorpay. Funds are held in escrow until you approve the work.' ),
        array( 'num' => '3', 'title' => 'Get Your Work', 'desc' => 'The expert delivers within the agreed timeframe. Request revisions if needed.' ),
        array( 'num' => '4', 'title' => 'Approve & Release', 'desc' => 'Once satisfied, approve to release payment. Both sides leave reviews.' ),
    );

    ob_start(); ?>
    <section class="nme-section nme-section-cream">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>How It Works</h2>
                <p>Simple, safe, and transparent — every step protected.</p>
            </div>
            <div class="nme-grid nme-grid-4">
                <?php foreach ( $steps as $step ) : ?>
                    <div class="nme-card nme-text-center">
                        <div class="nme-card-icon" style="margin: 0 auto 20px; background: var(--nme-forest); color: var(--nme-gold); font-family: var(--nme-font-heading); font-weight: 700;">
                            <?php echo $step['num']; ?>
                        </div>
                        <h3><?php echo esc_html( $step['title'] ); ?></h3>
                        <p><?php echo esc_html( $step['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_shortcode( 'nme_cta_section', 'nme_cta_section_shortcode' );
function nme_cta_section_shortcode( $atts ) {
    $defaults = array(
        'title'       => 'Ready to grow your channel?',
        'subtitle'    => 'Join hundreds of creators getting expert help on Nagaland Me Experts.',
        'button_text' => 'Find an Expert',
        'button_url'  => '/categories/',
    );

    if ( ! is_array( $atts ) ) {
        $atts = array();
    }

    $a = shortcode_atts( $defaults, $atts, 'nme_cta_section' );

    ob_start(); ?>
    <section class="nme-section nme-section-forest nme-text-center">
        <div class="nme-container">
            <h2 style="color: var(--nme-white) !important;"><?php echo wp_kses_post( $a['title'] ); ?></h2>
            <p style="color: rgba(255,255,255,0.85); font-size: 1.15rem; max-width: 600px; margin: 16px auto 32px;">
                <?php echo wp_kses_post( $a['subtitle'] ); ?>
            </p>
            <a href="<?php echo esc_url( $a['button_url'] ); ?>" class="nme-btn nme-btn-gold">
                <?php echo esc_html( $a['button_text'] ); ?>
            </a>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- Homepage: Why choose us (buyer-facing) ---------- */
add_shortcode( 'nme_why_choose_us', 'nme_why_choose_us_shortcode' );
function nme_why_choose_us_shortcode( $atts ) {
    if ( ! is_array( $atts ) ) { $atts = array(); }
    $a = shortcode_atts( array(
        'title'    => 'Why choose Nagaland Me Experts',
        'subtitle' => 'Built for creators who are tired of getting burned by random freelancers.',
    ), $atts, 'nme_why_choose_us' );

    $cards = array(
        array( 'icon' => 'shield', 'title' => 'KYC-verified experts',
            'desc' => 'Every expert is identity-checked with government ID and a registered business. No anonymous accounts, no fake profiles.' ),
        array( 'icon' => 'wallet', 'title' => 'Escrow-protected payments',
            'desc' => 'Your money sits safely in escrow and only releases when you approve the work. Not happy? Raise a dispute and get a refund.' ),
        array( 'icon' => 'globe',  'title' => 'Built for Northeast India',
            'desc' => 'INR pricing, UPI payouts, GST-compliant invoices, and support in your language. Not a one-size-fits-all global platform.' ),
        array( 'icon' => 'star',   'title' => 'Real ratings, real reviews',
            'desc' => 'See honest ratings, delivery times, and past work before you hire. Top-rated experts rise to the top — no pay-to-play.' ),
        array( 'icon' => 'chart',  'title' => 'Fast turnaround',
            'desc' => 'Most orders deliver in 24–72 hours. Track every step from your dashboard with clear timelines and revisions.' ),
        array( 'icon' => 'phone',  'title' => 'Human support on WhatsApp',
            'desc' => 'Message the founder directly if something goes sideways. You will never hit a bot, a ticket queue, or a "we\'ll get back to you in 7 days."' ),
    );

    ob_start(); ?>
    <section class="nme-section nme-section-cream">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2><?php echo esc_html( $a['title'] ); ?></h2>
                <p><?php echo esc_html( $a['subtitle'] ); ?></p>
            </div>
            <div class="nme-grid nme-grid-3">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="nme-card">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $c['icon'] ); ?></div>
                        <h3><?php echo esc_html( $c['title'] ); ?></h3>
                        <p><?php echo esc_html( $c['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- About page: hero (editorial cream) ---------- */
add_shortcode( 'nme_about_hero', 'nme_about_hero_shortcode' );
function nme_about_hero_shortcode( $atts ) {
    if ( ! is_array( $atts ) ) { $atts = array(); }
    $a = shortcode_atts( array(
        'kicker'   => 'NAGALAND  ·  DIMAPUR  ·  EST 2025',
        'title'    => 'The people behind this',
        'subtitle' => "We're not a Silicon Valley startup. We're from Dimapur.",
    ), $atts, 'nme_about_hero' );

    ob_start(); ?>
    <section class="nme-about-hero">
        <div class="nme-container" style="max-width: 880px;">
            <div class="nme-kicker"><?php echo esc_html( $a['kicker'] ); ?></div>
            <h1 class="nme-about-hero-title"><?php echo esc_html( $a['title'] ); ?></h1>
            <span class="nme-divider-gold" aria-hidden="true"></span>
            <p class="nme-about-hero-subtitle"><?php echo esc_html( $a['subtitle'] ); ?></p>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- About page: values (replaces emoji grid) ---------- */
add_shortcode( 'nme_about_values', 'nme_about_values_shortcode' );
function nme_about_values_shortcode() {
    $cards = array(
        array( 'icon' => 'handshake',   'title' => 'Local first, always',
            'desc' => "We build for Northeast India before anyone else. If it doesn't work for a creator in Mon or Mokokchung, it doesn't ship." ),
        array( 'icon' => 'shield',      'title' => "Trust isn't optional",
            'desc' => 'Every expert is verified. Every payment is escrowed. Every dispute is reviewed by a person, not a bot.' ),
        array( 'icon' => 'gem',         'title' => 'Fewer, better experts',
            'desc' => "We'd rather have 50 experts who deliver great work than 500 who don't. We reject more than we accept." ),
        array( 'icon' => 'scales',      'title' => 'Everyone should win',
            'desc' => 'Affordable for buyers. Profitable for experts. Sustainable for us. If any leg breaks, the whole thing falls.' ),
        array( 'icon' => 'seedling',    'title' => 'This is a long game',
            'desc' => "We're building for decades. Every decision favors lasting value over quick wins." ),
        array( 'icon' => 'certificate', 'title' => 'We do things right',
            'desc' => 'GST-registered. RBI-compliant escrow. DPDP Act 2023 compliant. No shortcuts.' ),
    );

    ob_start(); ?>
    <section class="nme-section">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>What we believe in</h2>
            </div>
            <div class="nme-grid nme-grid-3">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="nme-card">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $c['icon'] ); ?></div>
                        <h3><?php echo esc_html( $c['title'] ); ?></h3>
                        <p><?php echo esc_html( $c['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- About page: Nagaland Me family ---------- */
add_shortcode( 'nme_about_family', 'nme_about_family_shortcode' );
function nme_about_family_shortcode() {
    $sites = array(
        array(
            'url'   => 'https://nagaland.me/',
            'logo'  => 'https://nagaland.me/wp-content/uploads/2026/03/nagaland-Me-official-logo-1.png',
            'name'  => 'nagaland.me',
            'desc'  => 'Our parent brand. The story of Nagaland Me starts here.',
        ),
        array(
            'url'   => 'https://nagalandai.com/',
            'logo'  => 'https://nagalandai.com/wp-content/uploads/2025/12/Nagaland-AI-Favicon.png',
            'name'  => 'nagalandai.com',
            'desc'  => "India's first state-level AI chatbot — built for Nagaland.",
        ),
        array(
            'url'   => 'https://nagalandprofiles.com/',
            'logo'  => 'https://nagalandprofiles.com/wp-content/uploads/2026/03/Nagaland-Profiles-Logo.png',
            'name'  => 'nagalandprofiles.com',
            'desc'  => "Verified profile directory for Nagaland's people and businesses.",
        ),
        array(
            'url'   => 'https://nagalanddictionary.com/',
            'logo'  => 'https://nagalanddictionary.com/wp-content/uploads/2025/10/Site-Icon.png',
            'name'  => 'nagalanddictionary.com',
            'desc'  => 'Naga tribal language dictionary with audio pronunciation.',
        ),
        array(
            'url'   => 'https://nagalandnewstoday.com/',
            'logo'  => 'https://nagalandnewstoday.com/wp-content/uploads/2026/03/Nagaland-News-Today-officially-logo.png',
            'name'  => 'nagalandnewstoday.com',
            'desc'  => 'Independent news covering Nagaland and the Northeast.',
        ),
        array(
            'url'   => 'https://helpnagaland.com/',
            'logo'  => 'https://helpnagaland.com/wp-content/uploads/2025/10/cropped-ChatGPT-Image-Sep-8-2025-10_47_06-PM.png',
            'name'  => 'helpnagaland.com',
            'desc'  => 'Civic reporting — helping citizens raise issues and get heard.',
        ),
    );

    ob_start(); ?>
    <section class="nme-section nme-section-cream">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>The Nagaland Me family</h2>
                <p>This marketplace is one piece of a bigger puzzle. Here's everything we run.</p>
            </div>
            <div class="nme-grid nme-grid-3">
                <?php foreach ( $sites as $s ) : ?>
                    <a class="nme-card nme-family-card" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener">
                        <div class="nme-family-logo-wrap">
                            <img src="<?php echo esc_url( $s['logo'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?> logo" loading="lazy" />
                        </div>
                        <h3 class="nme-family-name"><?php echo esc_html( $s['name'] ); ?></h3>
                        <p><?php echo esc_html( $s['desc'] ); ?></p>
                        <span class="nme-family-visit">
                            Visit site
                            <span class="nme-family-arrow"><?php echo nme_svg_icon( 'arrow-right' ); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- Trust & Safety: hero (forest + gold glow) ---------- */
add_shortcode( 'nme_trust_hero', 'nme_trust_hero_shortcode' );
function nme_trust_hero_shortcode( $atts ) {
    $defaults = array(
        'title'     => "Your money doesn't move",
        'highlight' => 'until you say so.',
        'subtitle'  => 'Every payment is held in escrow. Every expert is verified. Every dispute is reviewed by a real person.',
        'cta1_text' => 'See How Escrow Works',
        'cta1_url'  => '#escrow',
        'cta2_text' => 'Our Refund Policy',
        'cta2_url'  => '/refund-policy/',
    );
    if ( ! is_array( $atts ) ) { $atts = array(); }
    $a = shortcode_atts( $defaults, $atts, 'nme_trust_hero' );

    ob_start(); ?>
    <section class="nme-hero nme-hero-trust">
        <div class="nme-container">
            <div class="nme-trust-badge">
                <span class="nme-trust-badge-icon"><?php echo nme_svg_icon( 'shield' ); ?></span>
                <span>Escrow-protected · RBI-licensed · KYC-verified</span>
            </div>
            <h1>
                <?php echo wp_kses_post( $a['title'] ); ?>
                <?php if ( ! empty( $a['highlight'] ) ) : ?>
                    <span class="nme-gold-text"><?php echo wp_kses_post( $a['highlight'] ); ?></span>
                <?php endif; ?>
            </h1>
            <?php if ( ! empty( $a['subtitle'] ) ) : ?>
                <p class="lead"><?php echo wp_kses_post( $a['subtitle'] ); ?></p>
            <?php endif; ?>
            <div class="nme-hero-cta">
                <?php if ( ! empty( $a['cta1_text'] ) && ! empty( $a['cta1_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta1_url'] ); ?>" class="nme-btn nme-btn-gold"><?php echo esc_html( $a['cta1_text'] ); ?></a>
                <?php endif; ?>
                <?php if ( ! empty( $a['cta2_text'] ) && ! empty( $a['cta2_url'] ) ) : ?>
                    <a href="<?php echo esc_url( $a['cta2_url'] ); ?>" class="nme-btn nme-btn-outline" style="background: rgba(255,255,255,0.1) !important; color: #fff !important; border-color: #fff !important;"><?php echo esc_html( $a['cta2_text'] ); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- Trust & Safety: verify cards ---------- */
add_shortcode( 'nme_trust_verify', 'nme_trust_verify_shortcode' );
function nme_trust_verify_shortcode() {
    $cards = array(
        array( 'icon' => 'id-card',     'title' => 'Government ID',
            'desc' => 'Aadhaar, Voter ID, or Passport. Must match their profile. No anonymous sellers.' ),
        array( 'icon' => 'credit-card', 'title' => 'PAN & Bank verified',
            'desc' => 'Real PAN card. Real bank account. Verified before they can receive a single rupee.' ),
        array( 'icon' => 'folder',      'title' => 'Portfolio reviewed',
            'desc' => "Past work checked for quality. If it's not good enough, they don't get in." ),
        array( 'icon' => 'user-check',  'title' => 'Human approval',
            'desc' => 'A person — not an algorithm — reviews every application. We reject more than we accept.' ),
        array( 'icon' => 'phone',       'title' => 'Phone & email verified',
            'desc' => 'OTP on both. No burner accounts.' ),
        array( 'icon' => 'star',        'title' => 'Ongoing accountability',
            'desc' => 'Bad reviews and missed deadlines drop your tier. Consistent problems get you removed.' ),
    );

    ob_start(); ?>
    <section class="nme-section nme-section-cream">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>How we verify experts</h2>
                <p>Nobody gets on this platform by filling a form. Every expert is checked.</p>
            </div>
            <div class="nme-grid nme-grid-3">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="nme-card">
                        <div class="nme-card-icon nme-icon-svg"><?php echo nme_svg_icon( $c['icon'] ); ?></div>
                        <h3><?php echo esc_html( $c['title'] ); ?></h3>
                        <p><?php echo esc_html( $c['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ---------- Trust & Safety: buyer guarantees ---------- */
add_shortcode( 'nme_trust_guarantees', 'nme_trust_guarantees_shortcode' );
function nme_trust_guarantees_shortcode() {
    $cards = array(
        array( 'title' => "Money-back if they don't deliver",
            'desc' => 'Expert disappeared? Missed the deadline? Delivered something unusable? Full refund. No argument.' ),
        array( 'title' => 'Revisions included',
            'desc' => "Most services include free revisions. If it's not quite right, ask for changes before you approve." ),
        array( 'title' => 'Fair dispute resolution',
            'desc' => "Can't agree with the expert? Our admin team reviews the evidence and decides. Within 3–5 days." ),
        array( 'title' => 'Everything on record',
            'desc' => "All messages stay on the platform. If there's a dispute, we have the full conversation." ),
        array( 'title' => 'Razorpay-secured payments',
            'desc' => 'RBI-licensed. PCI DSS compliant. Your card details never touch our servers.' ),
        array( 'title' => 'Your data stays private',
            'desc' => "DPDP Act 2023 compliant. We don't sell or share your information. Period." ),
    );

    ob_start(); ?>
    <section class="nme-section">
        <div class="nme-container">
            <div class="nme-text-center nme-mb-4">
                <h2>Buyer guarantees</h2>
            </div>
            <div class="nme-grid nme-grid-2">
                <?php foreach ( $cards as $c ) : ?>
                    <div class="nme-card nme-guarantee-card">
                        <div class="nme-guarantee-head">
                            <span class="nme-check-icon nme-check-icon-lg"><?php echo nme_svg_icon( 'check' ); ?></span>
                            <h3><?php echo esc_html( $c['title'] ); ?></h3>
                        </div>
                        <p><?php echo esc_html( $c['desc'] ); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

/* ============================================================
   [nme_logo]  — Brand logo for experts.nagaland.me
   variant : full (badge + kicker + wordmark + tagline) [default]
             compact (badge + wordmark on one line — header use)
             inline  (wordmark only, no badge — footer / emails)
             mark    (badge only — favicon, mobile menu)
   theme   : dark  (default — forest text on light backgrounds)
             light (cream text on dark/forest backgrounds)
   link    : yes (default — wraps logo in homepage <a>) | no
   href    : URL to link to (defaults to home_url)
   ============================================================ */
add_shortcode( 'nme_logo', 'nme_logo_shortcode' );
function nme_logo_shortcode( $atts ) {
    static $instance = 0;
    $instance++;

    $atts = shortcode_atts( array(
        'variant' => 'full',
        'theme'   => 'dark',
        'link'    => 'yes',
        'href'    => home_url( '/' ),
    ), $atts, 'nme_logo' );

    $variant = in_array( $atts['variant'], array( 'full', 'compact', 'inline', 'mark' ), true ) ? $atts['variant'] : 'full';
    $theme   = ( $atts['theme'] === 'light' ) ? 'light' : 'dark';
    $gid     = 'nme-logo-grad-' . $instance;
    $cid     = 'nme-logo-check-' . $instance;

    ob_start();
    ?>
    <span class="nme-logo nme-logo--<?php echo esc_attr( $variant ); ?> nme-logo--<?php echo esc_attr( $theme ); ?>">
        <?php if ( $atts['link'] === 'yes' ) : ?>
            <a class="nme-logo-link" href="<?php echo esc_url( $atts['href'] ); ?>" aria-label="Nagaland Me Experts — home">
        <?php endif; ?>

        <?php if ( $variant !== 'inline' ) : ?>
            <span class="nme-logo-badge-wrap">
                <svg class="nme-logo-badge-svg" viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Nagaland Me Experts badge">
                    <defs>
                        <linearGradient id="<?php echo esc_attr( $gid ); ?>" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="#10B981"/>
                            <stop offset="55%" stop-color="#0A7558"/>
                            <stop offset="100%" stop-color="#0F2419"/>
                        </linearGradient>
                        <linearGradient id="<?php echo esc_attr( $cid ); ?>" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#FAF7F0"/>
                            <stop offset="100%" stop-color="#D4A843"/>
                        </linearGradient>
                    </defs>
                    <!-- Badge -->
                    <rect x="4" y="4" width="88" height="88" rx="22" fill="url(#<?php echo esc_attr( $gid ); ?>)"/>
                    <!-- Subtle top sheen -->
                    <path d="M 4 26 Q 4 4 26 4 L 70 4 Q 92 4 92 26 L 92 42 Q 50 30 4 42 Z" fill="rgba(255,255,255,0.09)"/>
                    <!-- Thin gold ring -->
                    <rect x="5" y="5" width="86" height="86" rx="21" fill="none" stroke="#D4A843" stroke-width="1.25" opacity="0.55"/>
                    <!-- Bold checkmark (verified expert) -->
                    <path d="M 26 50 L 41 64 L 70 30" stroke="url(#<?php echo esc_attr( $cid ); ?>)" stroke-width="9" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    <!-- Ascending arrow accent (echoes parent logo arrow) -->
                    <g transform="translate(64, 16)" opacity="0.95">
                        <path d="M 0 14 L 14 0" stroke="#D4A843" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                        <path d="M 5 0 L 14 0 L 14 9" stroke="#D4A843" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </g>
                </svg>
            </span>
        <?php endif; ?>

        <?php if ( $variant !== 'mark' ) : ?>
            <span class="nme-logo-text">
                <?php if ( $variant === 'full' ) : ?>
                    <span class="nme-logo-kicker">EXPERTS</span>
                <?php endif; ?>
                <span class="nme-logo-wordmark">
                    <span class="nme-logo-name">NAGALAND</span><span class="nme-logo-dot">.</span><span class="nme-logo-pill">ME</span>
                    <?php if ( $variant === 'compact' ) : ?>
                        <span class="nme-logo-pill nme-logo-pill-gold">EXPERTS</span>
                    <?php endif; ?>
                </span>
                <?php if ( $variant === 'full' ) : ?>
                    <span class="nme-logo-tagline">Verified experts. Trusted work.</span>
                <?php endif; ?>
            </span>
        <?php endif; ?>

        <?php if ( $atts['link'] === 'yes' ) : ?>
            </a>
        <?php endif; ?>
    </span>
    <?php
    return ob_get_clean();
}


/* ============================================================
   Auto-inject [nme_logo] into the Astra site header.
   Uses WordPress core `get_custom_logo` / `has_custom_logo`
   filters so Astra (and any theme) renders our SVG lockup in
   place of the uploaded logo image. Zero configuration needed.

   To disable and go back to Astra's default logo behaviour:
       add_filter( 'nme_auto_header_logo', '__return_false' );
   ============================================================ */
add_filter( 'has_custom_logo', 'nme_auto_has_custom_logo', 999 );
function nme_auto_has_custom_logo( $has_logo ) {
    if ( ! apply_filters( 'nme_auto_header_logo', true ) ) {
        return $has_logo;
    }
    return true;
}

add_filter( 'get_custom_logo', 'nme_auto_custom_logo', 999 );
function nme_auto_custom_logo( $html ) {
    if ( ! apply_filters( 'nme_auto_header_logo', true ) ) {
        return $html;
    }
    $inner = do_shortcode( '[nme_logo variant="compact" theme="light" link="no"]' );
    return sprintf(
        '<a href="%s" class="custom-logo-link nme-custom-logo" rel="home" aria-label="%s">%s</a>',
        esc_url( home_url( '/' ) ),
        esc_attr__( 'Nagaland Me Experts — home', 'nagaland-me-experts-child' ),
        $inner
    );
}

/* Fallback: some Astra header-builder layouts bypass get_custom_logo
   entirely. Hook Astra's own branding action so the logo still shows. */
add_action( 'astra_masthead_branding', 'nme_astra_branding_fallback', 5 );
function nme_astra_branding_fallback() {
    if ( ! apply_filters( 'nme_auto_header_logo', true ) ) {
        return;
    }
    static $rendered = false;
    if ( $rendered ) {
        return;
    }
    $rendered = true;
    echo '<div class="nme-astra-branding-fallback">';
    echo do_shortcode( '[nme_logo variant="compact" theme="light"]' );
    echo '</div>';
}

/* ============================================================
   v1.2.9 — DOM-injection fallback (guaranteed)
   If the Astra Header Builder has no Site Identity element placed
   (or uses a layout that skips every PHP hook above), this
   JavaScript finds the rendered header and injects our logo as
   the first child of the first header row. Zero configuration.
   ============================================================ */
add_action( 'wp_footer', 'nme_header_logo_js_inject', 1 );
function nme_header_logo_js_inject() {
    if ( ! apply_filters( 'nme_auto_header_logo', true ) ) {
        return;
    }
    $logo_html = do_shortcode( '[nme_logo variant="compact" theme="light"]' );
    ?>
    <script>
    (function () {
        var LOGO_HTML = <?php echo wp_json_encode( $logo_html ); ?>;

        function injectInto(wrap) {
            if (!wrap || wrap.dataset.nmeLogoInjected === '1') { return; }
            wrap.dataset.nmeLogoInjected = '1';

            var existingSlot = wrap.querySelector(
                '.site-branding, .ast-builder-site-identity, .ast-site-identity'
            );

            if (existingSlot) {
                existingSlot.innerHTML = '<div class="nme-header-injected">' + LOGO_HTML + '</div>';
                existingSlot.style.display = 'flex';
                existingSlot.style.alignItems = 'center';
                return;
            }

            var row = wrap.querySelector(
                '.ast-builder-grid-row-container-inner, ' +
                '.ast-builder-grid-row, ' +
                '.main-header-bar, ' +
                '.main-header-bar-wrap, ' +
                '.ast-primary-header-bar .ast-container, ' +
                '.ast-flex.main-header-container, ' +
                '.ast-main-header, ' +
                '.ast-mobile-header-bar, ' +
                '.ast-mobile-header-content'
            );
            if (!row) { row = wrap.firstElementChild; }
            if (!row) { return; }

            var el = document.createElement('div');
            el.className = 'nme-header-injected nme-header-injected-standalone';
            el.innerHTML = LOGO_HTML;
            row.insertBefore(el, row.firstChild);
        }

        function run() {
            var wraps = document.querySelectorAll(
                'header.site-header, ' +
                '#masthead, ' +
                '.ast-main-header-wrap, ' +
                '.ast-above-header-wrap, ' +
                '.ast-mobile-header-wrap, ' +
                '#ast-mobile-header, ' +
                '#ast-desktop-header, ' +
                '.ast-builder-mobile-header-wrap, ' +
                '.ast-builder-header-wrap'
            );
            wraps.forEach(injectInto);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
        window.addEventListener('load', run);
        window.addEventListener('resize', run);
    })();
    </script>
    <?php
}

add_action( 'admin_head', 'nme_admin_brand_color' );
function nme_admin_brand_color() {
    echo '<style>
        #wpadminbar .ab-empty-item,
        #wpadminbar .ab-item { color: #D4A843; }
    </style>';
}

/* ============================================================
   7. SECURITY HARDENING
   ============================================================ */
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );