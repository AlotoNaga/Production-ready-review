<?php
/**
 * Plugin Name:       Nagaland Me Experts — Payments
 * Plugin URI:        https://experts.nagaland.me
 * Description:       Razorpay Route payments, escrow, and order management for Nagaland Me Experts marketplace. Splits payments between platform and experts with escrow protection.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Nagaland Me
 * Author URI:        https://nagaland.me
 * License:           GPL v2 or later
 * Text Domain:       nme-payments
 *
 * @package NagalandMeExpertsPayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direct access protection
}

/* ============================================================
   PLUGIN CONSTANTS
   ============================================================ */
define( 'NMEP_VERSION',         '2.0.0' );
define( 'NMEP_DB_VERSION',      '2.0.0' );
define( 'NMEP_PLUGIN_FILE',     __FILE__ );
define( 'NMEP_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'NMEP_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'NMEP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
   DEPENDENCY CHECK — REQUIRES BASE PLUGIN v0.1
   ============================================================ */
add_action( 'admin_init', 'nmep_check_dependencies' );
function nmep_check_dependencies() {
    if ( ! class_exists( 'NME_Database' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Nagaland Me Experts Payments</strong> requires the base plugin <strong>Nagaland Me Experts</strong> (v0.1+) to be installed and activated first.</p></div>';
        } );

        if ( is_plugin_active( NMEP_PLUGIN_BASENAME ) ) {
            deactivate_plugins( NMEP_PLUGIN_BASENAME );
            if ( isset( $_GET['activate'] ) ) {
                unset( $_GET['activate'] );
            }
        }
    }
}

/* ============================================================
   AUTOLOAD INCLUDES
   ============================================================ */
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-logger.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-compat.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-database.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-events.php';       // v2.0 central event bus
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-migrations.php';   // v2.0 schema evolution
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-settings.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-razorpay-client.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-linked-accounts.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-services.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-uploads.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-orders.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-checkout.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-escrow.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-refunds.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-disputes.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-order-workflow.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-reviews.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-messages.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-expert-profiles.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-seo.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-webhooks.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-emails.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-guard.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-shortcodes.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-cron.php';
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-lifecycle-crons.php'; // v2.0 reminders + SLA crons
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-payouts.php';         // v2.0 payout ledger
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-payment-retry.php';   // v2.0 payment-retry flow
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-upi-payouts.php';     // v2.0 UPI / RazorpayX payouts
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-invoices.php';        // v2.0 tax invoices
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-2fa.php';             // v2.0 TOTP 2FA
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-gdpr.php';            // v2.0 export / erasure
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-audit-log.php';       // v2.0 structured audit log
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-tiers.php';           // v2.0 expert tier system
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-reviews-v2.php';      // v2.0 two-way reviews + multi-criteria
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-metrics.php';         // v2.0 response rate / online status
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-dispute-sla.php';     // v2.0 72h dispute SLA + evidence
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-service-extras.php';  // v2.0 gig add-ons / extras
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-expert-profile-v2.php'; // v2.0 subcats + portfolio + langs + certs
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-rest-api.php';        // v2.0 REST API v1 for Expo app
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-sms.php';             // v2.0 MSG91 SMS + WhatsApp
require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-moderation.php';      // v2.0 blocked-word filter + attachment validation
require_once NMEP_PLUGIN_DIR . 'admin/class-nmep-admin.php';
require_once NMEP_PLUGIN_DIR . 'admin/class-nmep-admin-tools.php';        // v2.0 exports + impersonation + moderation

/* ============================================================
   v1.5.5 MODULES — Coupons, Buyer Premium, Pre-purchase Inbox
   Loaded after core so they can hook into nmep_checkout_pricing,
   nmep_after_payment_captured, etc.
   file_exists guards let the core plugin boot even if one module
   file is temporarily absent during phased rollout.
   ============================================================ */
if ( file_exists( NMEP_PLUGIN_DIR . 'includes/class-nmep-coupons.php' ) ) {
    require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-coupons.php';
}
if ( file_exists( NMEP_PLUGIN_DIR . 'includes/class-nmep-coupons-v2.php' ) ) {
    require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-coupons-v2.php';
}
if ( file_exists( NMEP_PLUGIN_DIR . 'includes/class-nmep-buyer-premium.php' ) ) {
    require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-buyer-premium.php';
}
if ( file_exists( NMEP_PLUGIN_DIR . 'includes/class-nmep-inbox.php' ) ) {
    require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-inbox.php';
}
if ( file_exists( NMEP_PLUGIN_DIR . 'includes/class-nmep-buyer-account-auto.php' ) ) {
    require_once NMEP_PLUGIN_DIR . 'includes/class-nmep-buyer-account-auto.php';
}

/* ============================================================
   ACTIVATION
   ============================================================ */
register_activation_hook( __FILE__, 'nmep_activate' );
function nmep_activate() {
    // Verify base plugin
    if ( ! class_exists( 'NME_Database' ) ) {
        deactivate_plugins( NMEP_PLUGIN_BASENAME );
        wp_die(
            'Nagaland Me Experts Payments requires the base plugin "Nagaland Me Experts" to be installed and activated first.',
            'Plugin Activation Error',
            array( 'back_link' => true )
        );
    }

    // Create tables
    NMEP_Database::create_tables();

    // v2.0 — apply additive migrations (new tables, new columns, new indexes)
    if ( class_exists( 'NMEP_Migrations' ) ) {
        NMEP_Migrations::run();
    }

    // v1.5.5 — create coupons tables on activation if module is present
    if ( class_exists( 'NMEP_Coupons' ) && method_exists( 'NMEP_Coupons', 'create_tables' ) ) {
        NMEP_Coupons::create_tables();
        update_option( 'nmep_coupons_db_version', '1.0.0', false );
    }

    // Set default options
    NMEP_Settings::set_defaults();

    // Schedule cron jobs
    NMEP_Cron::schedule();
    if ( class_exists( 'NMEP_Lifecycle_Crons' ) ) {
        NMEP_Lifecycle_Crons::schedule();
    }

    // Create required pages
    nmep_create_required_pages();

    // Set version
    update_option( 'nmep_version', NMEP_VERSION );
    update_option( 'nmep_db_version', NMEP_DB_VERSION );

    // Flush rewrite rules for any custom endpoints
    flush_rewrite_rules();

    // Audit log (compat class loads before this is reachable in normal init flow,
    // but on activation the compat class may not yet be loaded — use direct call defensively)
    if ( class_exists( 'NME_Database' ) && method_exists( 'NME_Database', 'audit_log' ) ) {
        NME_Database::audit_log( 'payments_plugin_activated', 'system', null, array( 'version' => NMEP_VERSION ) );
    }
}

/**
 * Create required WordPress pages if they don't exist
 */
function nmep_create_required_pages() {
    $pages = array(
        'services'         => array( 'title' => 'Services',         'content' => '[nmep_browse_services]' ),
        'checkout'         => array( 'title' => 'Checkout',         'content' => '[nmep_checkout]' ),
        'order-thank-you'  => array( 'title' => 'Order Confirmed',  'content' => '[nmep_thank_you]' ),
        'order-track'      => array( 'title' => 'Track My Order',   'content' => '[nmep_track_order]' ),
        'my-orders'        => array( 'title' => 'My Orders',        'content' => '[nmep_my_orders]' ),
        'expert-dashboard' => array( 'title' => 'Expert Dashboard', 'content' => '[nmep_expert_dashboard]' ),
        'service'          => array( 'title' => 'Service',          'content' => '[nmep_service_view]' ),
    );

    $page_map = get_option( 'nmep_pages', array() );
    if ( ! is_array( $page_map ) ) {
        $page_map = array();
    }

    foreach ( $pages as $slug => $data ) {
        $existing = get_page_by_path( $slug );
        if ( $existing ) {
            $page_map[ $slug ] = (int) $existing->ID;
            continue;
        }

        $page_id = wp_insert_post( array(
            'post_title'   => $data['title'],
            'post_name'    => $slug,
            'post_content' => $data['content'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => 1,
        ) );

        if ( ! is_wp_error( $page_id ) && $page_id ) {
            $page_map[ $slug ] = (int) $page_id;
        }
    }

    update_option( 'nmep_pages', $page_map, false );
}

/* ============================================================
   DEACTIVATION
   ============================================================ */
register_deactivation_hook( __FILE__, 'nmep_deactivate' );
function nmep_deactivate() {
    NMEP_Cron::unschedule();
    if ( class_exists( 'NMEP_Lifecycle_Crons' ) ) {
        NMEP_Lifecycle_Crons::unschedule();
    }
    flush_rewrite_rules();
}

/* ============================================================
   INITIALIZE PLUGIN
   ============================================================ */
add_action( 'plugins_loaded', 'nmep_init', 20 ); // Priority 20 to load AFTER base plugin
function nmep_init() {
    // Ensure base plugin is loaded
    if ( ! class_exists( 'NME_Database' ) ) {
        return;
    }

    // Initialize all components
    NMEP_Settings::init();
    NMEP_Linked_Accounts::init();
    NMEP_Services::init();
    NMEP_Uploads::init();
    NMEP_Orders::init();
    NMEP_Checkout::init();
    NMEP_Escrow::init();
    NMEP_Refunds::init();
    NMEP_Disputes::init();
    NMEP_Order_Workflow::init();
    NMEP_Reviews::init();
    NMEP_Messages::init();
    NMEP_Expert_Profiles::init();
    NMEP_SEO::init();
    NMEP_Webhooks::init();
    NMEP_Shortcodes::init();
    NMEP_Cron::init();

    // v2.0 — lifecycle reminder + dispute SLA crons
    if ( class_exists( 'NMEP_Lifecycle_Crons' ) ) {
        NMEP_Lifecycle_Crons::init();
        // Self-heal: if a prior activation missed scheduling (e.g. direct file upload),
        // ensure the hooks are scheduled on first admin load.
        if ( is_admin() ) {
            NMEP_Lifecycle_Crons::schedule();
        }
    }

    // v2.0 — payout ledger + payment retry flow + UPI payouts
    if ( class_exists( 'NMEP_Payouts' ) )        { NMEP_Payouts::init(); }
    if ( class_exists( 'NMEP_Payment_Retry' ) )  { NMEP_Payment_Retry::init(); }
    if ( class_exists( 'NMEP_UPI_Payouts' ) )    { NMEP_UPI_Payouts::init(); }
    if ( class_exists( 'NMEP_Invoices' ) )       { NMEP_Invoices::init(); }
    if ( class_exists( 'NMEP_2FA' ) )            { NMEP_2FA::init(); }
    if ( class_exists( 'NMEP_GDPR' ) )           { NMEP_GDPR::init(); }
    if ( class_exists( 'NMEP_Audit_Log' ) )      { NMEP_Audit_Log::init(); }
    if ( class_exists( 'NMEP_Tiers' ) )          { NMEP_Tiers::init(); }
    if ( class_exists( 'NMEP_Reviews_V2' ) )     { NMEP_Reviews_V2::init(); }
    if ( class_exists( 'NMEP_Metrics' ) )        { NMEP_Metrics::init(); }
    if ( class_exists( 'NMEP_Dispute_SLA' ) )    { NMEP_Dispute_SLA::init(); }
    if ( class_exists( 'NMEP_Service_Extras' ) ) { NMEP_Service_Extras::init(); }
    if ( class_exists( 'NMEP_Expert_Profile_V2' ) ) { NMEP_Expert_Profile_V2::init(); }
    if ( class_exists( 'NMEP_REST_API' ) )       { NMEP_REST_API::init(); }
    if ( class_exists( 'NMEP_SMS' ) )            { NMEP_SMS::init(); }

    // v1.5.5 modules (loaded only if present — see require_once block above)
    if ( class_exists( 'NMEP_Coupons' ) )        { NMEP_Coupons::init(); }
    if ( class_exists( 'NMEP_Coupons_V2' ) )     { NMEP_Coupons_V2::init(); }
    if ( class_exists( 'NMEP_Buyer_Premium' ) )  { NMEP_Buyer_Premium::init(); }
    if ( class_exists( 'NMEP_Inbox' ) )          { NMEP_Inbox::init(); }
    if ( class_exists( 'NMEP_Buyer_Account_Auto' ) ) { NMEP_Buyer_Account_Auto::init(); }

    if ( is_admin() ) {
        NMEP_Admin::init();
        if ( class_exists( 'NMEP_Admin_Tools' ) ) { NMEP_Admin_Tools::init(); }
    }

    // Database upgrade check — runs on every admin load when version is behind.
    // create_tables() is idempotent via dbDelta; NMEP_Migrations::run() adds v2.0 schema.
    $installed_db_version = get_option( 'nmep_db_version', '0.0.0' );
    if ( version_compare( $installed_db_version, NMEP_DB_VERSION, '<' ) ) {
        NMEP_Database::create_tables();
        if ( class_exists( 'NMEP_Migrations' ) ) {
            NMEP_Migrations::run();
        }
        // NMEP_Migrations::run() writes nmep_db_version itself on success, but
        // fall through to keep the legacy behaviour for 1.x → 1.5.x upgrades.
        if ( version_compare( get_option( 'nmep_db_version', '0.0.0' ), NMEP_DB_VERSION, '<' ) ) {
            update_option( 'nmep_db_version', NMEP_DB_VERSION );
        }
    }
}

/* ============================================================
   FRONTEND ASSETS
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'nmep_enqueue_assets' );
function nmep_enqueue_assets() {
    wp_enqueue_style(
        'nmep-frontend',
        NMEP_PLUGIN_URL . 'assets/css/frontend.css',
        array(),
        NMEP_VERSION
    );

    wp_enqueue_script(
        'nmep-frontend',
        NMEP_PLUGIN_URL . 'assets/js/frontend.js',
        array( 'jquery' ),
        NMEP_VERSION,
        true
    );

    wp_localize_script( 'nmep-frontend', 'nmep_data', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'nmep_ajax' ),
        'home_url' => home_url(),
    ) );
}

/* ============================================================
   ADMIN ASSETS
   ============================================================ */
add_action( 'admin_enqueue_scripts', 'nmep_enqueue_admin_assets' );
function nmep_enqueue_admin_assets( $hook ) {
    // Only load on plugin admin pages
    if ( strpos( $hook, 'nmep' ) === false && strpos( $hook, 'nme-payments' ) === false ) {
        return;
    }

    wp_enqueue_style(
        'nmep-admin',
        NMEP_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        NMEP_VERSION
    );

    wp_enqueue_script(
        'nmep-admin',
        NMEP_PLUGIN_URL . 'assets/js/admin.js',
        array( 'jquery' ),
        NMEP_VERSION,
        true
    );
}

/* ============================================================
   HELPER FUNCTIONS (GLOBAL)
   ============================================================ */

/**
 * Get plugin setting
 */
function nmep_get_setting( $key, $default = null ) {
    return NMEP_Settings::get( $key, $default );
}

/**
 * Get a page URL by its slug from our auto-created pages
 */
function nmep_get_page_url( $slug, $fallback = '/' ) {
    $pages = get_option( 'nmep_pages', array() );
    if ( ! empty( $pages[ $slug ] ) ) {
        $url = get_permalink( (int) $pages[ $slug ] );
        if ( $url ) {
            return $url;
        }
    }
    return home_url( $fallback );
}

/**
 * Format INR amount
 */
function nmep_format_inr( $amount ) {
    return '₹' . number_format( (float) $amount, 2 );
}

/**
 * Convert rupees to paise (Razorpay uses paise)
 */
function nmep_to_paise( $rupees ) {
    return (int) round( ( (float) $rupees ) * 100 );
}

/**
 * Convert paise to rupees
 */
function nmep_to_rupees( $paise ) {
    return (float) $paise / 100;
}

/**
 * v1.5.4: Render a service cover image OR auto-generated forest-green placeholder
 * Used everywhere a service cover is shown (browse cards, service page, etc.)
 *
 * @param object $service Service row
 * @param string $aspect  CSS aspect-ratio value (default '16/9')
 * @param array  $options Optional: 'class' for extra CSS class, 'expert_name' for sub-text
 */
function nmep_render_service_cover( $service, $aspect = '16/9', $options = array() ) {
    $extra_class = $options['class'] ?? '';
    $expert_name = $options['expert_name'] ?? '';
    $title = $service->title ?? 'Service';

    if ( ! empty( $service->cover_image ) ) {
        // Real photo provided
        printf(
            '<div class="nmep-cover %s" style="aspect-ratio: %s; background: var(--nme-bg-soft, #F9FAFB) url(\'%s\') center/cover no-repeat; border-radius: 12px;"></div>',
            esc_attr( $extra_class ),
            esc_attr( $aspect ),
            esc_url( $service->cover_image )
        );
        return;
    }

    // Auto-generated placeholder - forest-green gradient with title
    // Use a deterministic hue offset based on title so each service has slightly unique color
    $hue_offset = abs( crc32( $title ) ) % 30 - 15; // -15 to +15 degree variation
    $color1 = '#0F2419';
    $color2 = '#0A7558';

    $title_safe = esc_html( $title );
    $name_safe  = esc_html( $expert_name );

    ?>
    <div class="nmep-cover nmep-cover-placeholder <?php echo esc_attr( $extra_class ); ?>"
         style="aspect-ratio: <?php echo esc_attr( $aspect ); ?>;
                background: linear-gradient(135deg, <?php echo $color1; ?> 0%, <?php echo $color2; ?> 100%);
                border-radius: 12px;
                position: relative;
                overflow: hidden;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 8% 10%;">
        <!-- Decorative gold corner accent -->
        <div style="position: absolute; top: 12px; right: 16px; color: #D4A843; font-size: 0.7rem; font-weight: 600; letter-spacing: 1px; opacity: 0.85;">★ NAGALAND ME EXPERTS</div>

        <!-- Title -->
        <div style="text-align: center; color: #ffffff; line-height: 1.25;">
            <div style="font-family: 'DM Serif Display', Georgia, serif;
                        font-size: clamp(1rem, 4vw, 2rem);
                        font-weight: 400;
                        margin-bottom: 8px;
                        text-shadow: 0 2px 12px rgba(0,0,0,0.25);">
                <?php echo $title_safe; ?>
            </div>
            <?php if ( $name_safe ) : ?>
                <div style="font-size: clamp(0.7rem, 1.6vw, 0.9rem); color: #D4A843; opacity: 0.95; letter-spacing: 0.5px;">by <?php echo $name_safe; ?></div>
            <?php endif; ?>
        </div>

        <!-- Decorative bottom-left dot -->
        <div style="position: absolute; bottom: 14px; left: 18px; width: 8px; height: 8px; background: #D4A843; border-radius: 50%; opacity: 0.7;"></div>
    </div>
    <?php
}