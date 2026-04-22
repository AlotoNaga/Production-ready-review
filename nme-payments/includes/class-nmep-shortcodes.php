<?php
/**
 * NMEP_Shortcodes
 * All frontend shortcodes for the marketplace.
 *
 * @version 1.5.6 (adds Message Expert button in service page mini-card)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NMEP_Shortcodes {

    public static function init() {
        // Service-related (Batch B)
        add_shortcode( 'nmep_expert_dashboard',  array( __CLASS__, 'sc_expert_dashboard' ) );
        add_shortcode( 'nmep_service_view',      array( __CLASS__, 'sc_service_view' ) );
        add_shortcode( 'nmep_browse_services',   array( __CLASS__, 'sc_browse_services' ) );
        add_shortcode( 'nmep_featured_services', array( __CLASS__, 'sc_featured_services' ) );

        // Buyer flow (Batch C)
        add_shortcode( 'nmep_checkout',    array( __CLASS__, 'sc_checkout' ) );
        add_shortcode( 'nmep_thank_you',   array( __CLASS__, 'sc_thank_you' ) );
        add_shortcode( 'nmep_track_order', array( __CLASS__, 'sc_track_order' ) );
        add_shortcode( 'nmep_my_orders',   array( __CLASS__, 'sc_my_orders' ) );

        // URL rewrite for /service/{slug}/
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_service_url' ) );
    }

    /* ============================================================
       URL ROUTING
       ============================================================ */

    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^service/([^/]+)/?$',
            'index.php?nmep_service_slug=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^services/category/([^/]+)/?$',
            'index.php?nmep_category_slug=$matches[1]',
            'top'
        );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'nmep_service_slug';
        $vars[] = 'nmep_category_slug';
        return $vars;
    }

    public static function handle_service_url() {
        $slug = get_query_var( 'nmep_service_slug' );
        if ( ! $slug ) return;

        $service = NMEP_Services::get_by_slug( $slug );
        if ( ! $service || $service->status !== NMEP_Services::STATUS_ACTIVE ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        // Track view
        do_action( 'nmep_service_viewed', $service->id );

        // Render page wrapped in theme
        get_header();
        echo self::render_single_service( $service );
        get_footer();
        exit;
    }

    /* ============================================================
       EXPERT DASHBOARD (full Batch B)
       ============================================================ */

    public static function sc_expert_dashboard( $atts ) {
        self::prevent_page_cache();
        if ( ! is_user_logged_in() ) {
            return self::render_login_required( 'Please log in to access your Expert Dashboard.' );
        }

        $expert = NMEP_Compat::get_expert_by_user( get_current_user_id() );
        if ( ! $expert ) {
            return self::render_message_box( 'You are not registered as an expert.', 'You must apply and be approved to access this dashboard.', '/become-an-expert/', 'Apply Now' );
        }

        if ( $expert->status !== 'approved' ) {
            $msg = 'Your expert application is currently <strong>' . esc_html( $expert->status ) . '</strong>.';
            if ( $expert->status === 'pending' ) {
                $msg .= ' We review applications within 3-5 business days.';
            } elseif ( $expert->status === 'rejected' ) {
                $msg .= ' Reason: ' . esc_html( $expert->rejection_reason ?: 'Not specified' );
            }
            return self::render_message_box( 'Application Status', $msg );
        }

        // Determine view mode
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $service_id = isset( $_GET['edit_service'] ) ? (int) $_GET['edit_service'] : 0;
        $service_id = $service_id ?: ( isset( $_GET['service_id'] ) ? (int) $_GET['service_id'] : 0 );

        if ( $action === 'kyc' && class_exists( 'NME_KYC' ) ) {
            return NME_KYC::render_kyc_page();
        }

        if ( $action === 'new' || $service_id > 0 ) {
            return self::render_service_editor( $expert, $service_id );
        }

        return self::render_dashboard_home( $expert );
    }

    /**
     * Main dashboard view
     */
    private static function render_dashboard_home( $expert ) {
        $services = NMEP_Services::get_for_expert( $expert->id );

        $counts = array(
            'active'  => 0,
            'pending' => 0,
            'draft'   => 0,
            'paused'  => 0,
            'rejected' => 0,
        );
        foreach ( $services as $s ) {
            $key = $s->status;
            if ( isset( $counts[ $key ] ) ) $counts[ $key ]++;
            elseif ( $s->status === 'pending_review' ) $counts['pending']++;
        }

        $linked_account = NMEP_Linked_Accounts::get_for_expert( $expert->id );

        ob_start();
        echo self::render_status_messages();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 1200px;">

                <!-- Header card -->
                <div class="nme-card" style="display: flex; align-items: center; gap: 24px; margin-bottom: 24px;">
                    <?php if ( $expert->profile_photo ) : ?>
                        <img src="<?php echo esc_url( $expert->profile_photo ); ?>" style="width: 72px; height: 72px; border-radius: 50%; border: 3px solid var(--nme-gold);">
                    <?php endif; ?>
                    <div style="flex:1;">
                        <h2 style="margin:0;">Welcome back, <?php echo esc_html( $expert->full_name ); ?></h2>
                        <p style="margin: 4px 0 0; color: var(--nme-text-light);">
                            <?php echo NMEP_Compat::get_tier_badge( $expert->tier ); ?>
                            <?php if ( $expert->is_founding_expert ) : ?>
                                <span class="nme-badge" style="background: var(--nme-gold); color: var(--nme-forest);">★ FOUNDING EXPERT</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $expert->user_id ) ) { echo apply_filters( 'nmep_expert_profile_after_name', '', $expert ); } ?>
                            <?php if ( class_exists( 'NMEP_Expert_Profiles' ) ) :
                                $public_url = NMEP_Expert_Profiles::get_expert_url( $expert );
                            ?>
                                · <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" style="color: var(--nme-emerald);">View My Public Profile →</a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <?php
                        // Messages — the buyer inbox shortcode ([nmep_expert_inbox])
                        // already exists at /expert-inbox/, but nothing on the
                        // dashboard pointed at it, so experts only found out
                        // about buyer messages via email.
                        if ( class_exists( 'NMEP_Inbox' ) ) :
                            $unread    = (int) NMEP_Inbox::unread_count_for_expert( (int) $expert->id );
                            $inbox_url = NMEP_Inbox::expert_inbox_url();
                            ?>
                            <a href="<?php echo esc_url( $inbox_url ); ?>" class="nme-btn<?php echo $unread > 0 ? ' nme-btn-gold' : ''; ?>">
                                ✉️ Messages<?php if ( $unread > 0 ) : ?><span style="background:#EF4444;color:#fff;border-radius:999px;padding:1px 8px;font-size:0.75rem;font-weight:700;margin-left:6px;"><?php echo (int) $unread; ?></span><?php endif; ?>
                            </a>
                        <?php endif; ?>
                        <a href="?action=new" class="nme-btn nme-btn-gold">+ Create New Service</a>
                    </div>
                </div>

                <!-- KYC / Linked Account warning if not ready -->
                <?php if ( ! $linked_account || empty( $linked_account->razorpay_account_id ) ) : ?>
                    <div class="nme-card" style="border-left: 4px solid #F59E0B; background: #FFF8E1;">
                        <h3 style="margin-top:0; color: #92400E;">⏳ Payment account being set up</h3>
                        <p>Your Razorpay account is being created. You can build your service listings while we set this up. Orders cannot be placed until your payment account is verified — usually within 24 hours.</p>
                    </div>
                <?php endif; ?>

                <?php if ( class_exists( 'NME_KYC' ) && method_exists( 'NME_KYC', 'get_dashboard_prompt' ) ) { echo NME_KYC::get_dashboard_prompt( $expert ); } ?>

                <!-- Stats grid -->
                <?php
                global $wpdb;
                $orders_table = NMEP_Database::table( 'orders' );

                $total_earnings = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(expert_amount), 0) FROM $orders_table
                     WHERE expert_id = %d AND status IN ('completed', 'auto_released')",
                    $expert->id
                ) );

                $pending_earnings = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(expert_amount), 0) FROM $orders_table
                     WHERE expert_id = %d AND status IN ('paid', 'in_progress', 'delivered', 'revision') AND escrow_status = 'holding'",
                    $expert->id
                ) );

                $completed_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM $orders_table WHERE expert_id = %d AND status IN ('completed', 'auto_released')",
                    $expert->id
                ) );

                $expert_reviews = NMEP_Reviews::get_for_expert( $expert->id, 1 );
                $review_stats = $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) AS total, AVG(rating) AS avg_rating
                     FROM " . NMEP_Database::table( 'reviews' ) . "
                     WHERE expert_id = %d AND status = 'published'",
                    $expert->id
                ) );
                ?>

                <div class="nme-grid nme-grid-4">
                    <div class="nme-card nme-text-center" style="border-top: 4px solid var(--nme-emerald, #10B981);">
                        <div style="font-size: 1.4rem; font-weight: 700; color: var(--nme-emerald, #10B981); line-height: 1.2;"><?php echo nmep_format_inr( $total_earnings ); ?></div>
                        <div style="color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; margin-top: 6px;">Total Earnings</div>
                    </div>
                    <div class="nme-card nme-text-center" style="border-top: 4px solid #F59E0B;">
                        <div style="font-size: 1.4rem; font-weight: 700; color: #F59E0B; line-height: 1.2;"><?php echo nmep_format_inr( $pending_earnings ); ?></div>
                        <div style="color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; margin-top: 6px;">In Escrow</div>
                    </div>
                    <div class="nme-card nme-text-center">
                        <div style="font-size: 2rem; font-weight: 700; color: var(--nme-forest);"><?php echo $completed_count; ?></div>
                        <div style="color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; margin-top: 6px;">Completed Orders</div>
                    </div>
                    <div class="nme-card nme-text-center">
                        <?php if ( $review_stats && $review_stats->total > 0 ) : ?>
                            <div style="font-size: 1.6rem; font-weight: 700; color: #F59E0B; line-height: 1.2;">
                                ⭐ <?php echo number_format( (float) $review_stats->avg_rating, 1 ); ?>
                            </div>
                            <div style="color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; margin-top: 6px;"><?php echo (int) $review_stats->total; ?> reviews</div>
                        <?php else : ?>
                            <div style="font-size: 2rem; font-weight: 700; color: #9CA3AF;">—</div>
                            <div style="color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; margin-top: 6px;">No reviews yet</div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // ACTIVE ORDERS SECTION (Batch D)
                $active_orders = NMEP_Orders::get_for_expert( $expert->id, 50 );
                $needs_action = array_filter( $active_orders, function( $o ) {
                    return in_array( $o->status, array( NMEP_Orders::STATUS_PAID, NMEP_Orders::STATUS_IN_PROGRESS, NMEP_Orders::STATUS_REVISION ), true );
                } );
                if ( ! empty( $needs_action ) ) :
                ?>
                    <div class="nme-card nme-mt-3" style="border-left: 4px solid #F59E0B;">
                        <h2 style="margin-top: 0;">⏳ Orders Needing Your Attention (<?php echo count( $needs_action ); ?>)</h2>
                        <?php foreach ( $needs_action as $o ) :
                            $is_revision = $o->status === NMEP_Orders::STATUS_REVISION;
                            $due_date = strtotime( $o->delivery_due_at );
                            $is_overdue = $due_date < time();
                        ?>
                            <div style="background: var(--nme-bg-soft, #F9FAFB); padding: 16px; border-radius: 8px; margin-bottom: 12px; <?php echo $is_overdue ? 'border-left: 3px solid #EF4444;' : ''; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: start; gap: 16px; flex-wrap: wrap;">
                                    <div style="flex: 1; min-width: 240px;">
                                        <h4 style="margin: 0 0 6px;"><?php echo esc_html( $o->service_title ); ?></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: var(--nme-text-light, #6B7280);">
                                            <?php echo esc_html( $o->order_number ); ?> · <?php echo esc_html( $o->buyer_name ); ?>
                                            · <?php echo esc_html( $o->package_title ); ?>
                                        </p>
                                        <?php if ( $is_revision ) : ?>
                                            <p style="margin: 6px 0 0; color: #F59E0B; font-weight: 600;">🔄 Revision requested (#<?php echo (int) $o->revisions_used; ?>/<?php echo (int) $o->revisions_allowed; ?>)</p>
                                        <?php endif; ?>
                                        <?php if ( $is_overdue ) : ?>
                                            <p style="margin: 6px 0 0; color: #EF4444; font-weight: 600;">⚠️ Overdue (was due <?php echo esc_html( date( 'd M', $due_date ) ); ?>)</p>
                                        <?php else : ?>
                                            <p style="margin: 6px 0 0; font-size: 0.85rem; color: var(--nme-text-light, #6B7280);">Due <?php echo esc_html( date( 'd M Y', $due_date ) ); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div style="text-align: right;">
                                        <div style="font-weight: 700; color: var(--nme-emerald, #10B981);"><?php echo nmep_format_inr( $o->expert_amount ); ?></div>
                                        <div style="font-size: 0.75rem; color: var(--nme-text-light, #6B7280);">Your earnings</div>
                                    </div>
                                </div>

                                <?php if ( ! empty( $o->buyer_requirements ) ) : ?>
                                    <details style="margin-top: 12px;">
                                        <summary style="cursor: pointer; color: var(--nme-emerald, #10B981);">View buyer's requirements</summary>
                                        <div style="background: #fff; padding: 12px; border-radius: 6px; margin-top: 8px; white-space: pre-line; font-size: 0.9rem;"><?php echo esc_html( $o->buyer_requirements ); ?></div>
                                    </details>
                                <?php endif; ?>

                                <?php
                                // If revision, show buyer's revision message
                                if ( $is_revision ) :
                                    global $wpdb;
                                    $revision_msg = $wpdb->get_row( $wpdb->prepare(
                                        "SELECT * FROM " . NMEP_Database::table( 'order_messages' ) . "
                                         WHERE order_id = %d AND is_revision_request = 1
                                         ORDER BY created_at DESC LIMIT 1",
                                        $o->id
                                    ) );
                                    if ( $revision_msg ) :
                                ?>
                                    <div style="background: #FEF3C7; padding: 12px; border-radius: 6px; margin-top: 12px; border-left: 3px solid #F59E0B;">
                                        <strong>Buyer says:</strong>
                                        <p style="margin: 4px 0 0; white-space: pre-line;"><?php echo esc_html( $revision_msg->message ); ?></p>
                                    </div>
                                <?php
                                    endif;
                                endif;
                                ?>

                                <!-- Mark as Delivered form -->
                                <details style="margin-top: 12px;">
                                    <summary style="cursor: pointer; color: var(--nme-emerald, #10B981); font-weight: 600;">📦 Mark as Delivered</summary>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
                                        <input type="hidden" name="action" value="nmep_mark_delivered">
                                        <input type="hidden" name="order_id" value="<?php echo (int) $o->id; ?>">
                                        <?php wp_nonce_field( 'nmep_deliver_' . $o->id ); ?>
                                        <p>
                                            <label style="font-size: 0.85rem;">Delivery message to buyer (10+ chars)</label>
                                            <textarea name="delivery_message" rows="3" minlength="10" required placeholder="Hi! Here is your completed work. Let me know if you need any changes..." style="width: 100%; padding: 8px; border: 1px solid var(--nme-border, #E5E7EB); border-radius: 6px;"></textarea>
                                        </p>
                                        <p>
                                            <label style="font-size: 0.85rem;">Attachment URL (optional)</label>
                                            <input type="url" name="attachment_url" placeholder="https://drive.google.com/... or similar">
                                            <small>Paste a link to the deliverable (Google Drive, Dropbox, your portfolio, etc.)</small>
                                        </p>
                                        <button type="submit" class="nme-btn nme-btn-gold">📦 Send Delivery to Buyer</button>
                                    </form>
                                </details>

                                <?php
                                // Conversation with buyer — post-purchase order messages.
                                // The send handler (NMEP_Messages::handle_send_message) already
                                // authorizes the expert role via the logged-in user, and its
                                // post-send redirect points at this dashboard — but the render
                                // call was missing here, which is why experts couldn't see or
                                // reply to buyer messages after purchase. Compute the unread
                                // count BEFORE render_thread() runs (which marks read) so the
                                // badge is accurate on this render.
                                if ( class_exists( 'NMEP_Messages' ) ) :
                                    $order_unread = (int) $wpdb->get_var( $wpdb->prepare(
                                        "SELECT COUNT(*) FROM " . NMEP_Database::table( 'order_messages' ) . "
                                         WHERE order_id = %d AND sender_role = 'buyer' AND is_read = 0",
                                        (int) $o->id
                                    ) );
                                ?>
                                <details style="margin-top: 12px;" <?php echo $order_unread > 0 ? 'open' : ''; ?>>
                                    <summary style="cursor: pointer; color: var(--nme-emerald, #10B981); font-weight: 600;">
                                        💬 Conversation with buyer
                                        <?php if ( $order_unread > 0 ) : ?>
                                            <span style="background:#EF4444;color:#fff;border-radius:999px;padding:1px 8px;font-size:0.75rem;font-weight:700;margin-left:6px;"><?php echo (int) $order_unread; ?> new</span>
                                        <?php endif; ?>
                                    </summary>
                                    <?php echo NMEP_Messages::render_thread( $o, 'expert', admin_url( 'admin-post.php' ) ); ?>
                                </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Services list -->
                <div class="nme-card nme-mt-3">
                    <h2 style="margin-top:0;">Your Services</h2>

                    <?php if ( empty( $services ) ) : ?>
                        <div style="text-align: center; padding: 60px 20px;">
                            <div style="font-size: 4rem;">📦</div>
                            <h3>No services yet</h3>
                            <p style="color: var(--nme-text-light);">Create your first service listing to start receiving orders.</p>
                            <p><a href="?action=new" class="nme-btn nme-btn-gold">+ Create Your First Service</a></p>
                        </div>
                    <?php else : ?>
                        <table style="width:100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid var(--nme-border);">
                                    <th style="text-align: left; padding: 12px;">Title</th>
                                    <th style="text-align: left; padding: 12px;">Status</th>
                                    <th style="text-align: right; padding: 12px;">Basic Price</th>
                                    <th style="text-align: center; padding: 12px;">Orders</th>
                                    <th style="text-align: center; padding: 12px;">Rating</th>
                                    <th style="text-align: right; padding: 12px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $services as $s ) :
                                    if ( $s->status === 'archived' ) continue;
                                ?>
                                    <tr style="border-bottom: 1px solid var(--nme-border);">
                                        <td style="padding: 14px 12px;">
                                            <strong><?php echo esc_html( $s->title ); ?></strong>
                                            <?php if ( $s->status === NMEP_Services::STATUS_ACTIVE ) : ?>
                                                <br><small><a href="<?php echo esc_url( NMEP_Services::get_service_url( $s ) ); ?>" target="_blank">View public page →</a></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 12px;"><?php echo NMEP_Services::get_status_badge( $s->status ); ?></td>
                                        <td style="padding: 14px 12px; text-align: right;"><?php echo nmep_format_inr( $s->basic_price ); ?></td>
                                        <td style="padding: 14px 12px; text-align: center;"><?php echo (int) $s->orders_count; ?></td>
                                        <td style="padding: 14px 12px; text-align: center;">
                                            <?php if ( $s->reviews_count > 0 ) : ?>
                                                ⭐ <?php echo number_format( (float) $s->avg_rating, 1 ); ?>
                                            <?php else : ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 14px 12px; text-align: right;">
                                            <a href="?edit_service=<?php echo (int) $s->id; ?>" class="nme-btn" style="padding: 6px 12px; font-size: 0.85rem; min-width: 0; min-height: 0;">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Service editor (create or edit)
     */
    private static function render_service_editor( $expert, $service_id = 0 ) {
        $service = $service_id > 0 ? NMEP_Services::get( $service_id ) : null;

        // Permission check
        if ( $service && (int) $service->expert_id !== (int) $expert->id ) {
            return self::render_message_box( 'Permission Denied', 'You cannot edit this service.' );
        }

        $is_new = ! $service;
        $categories = NMEP_Compat::get_all_categories();

        // v1.5.4: Restore form data from transient if there was an error
        $restored = get_transient( 'nmep_form_data_' . get_current_user_id() );
        $get = function( $key, $fallback = '' ) use ( $restored, $service ) {
            if ( $restored && isset( $restored[ $key ] ) ) {
                return $restored[ $key ];
            }
            if ( $service && isset( $service->$key ) ) {
                return $service->$key;
            }
            return $fallback;
        };

        // Settings
        $min_price = (int) NMEP_Settings::get( 'min_order_amount', 199 );
        $max_price = (int) NMEP_Settings::get( 'max_order_amount', 9999 );

        // Tier metadata for the form
        $tier_meta = array(
            'basic'    => array( 'label' => 'Starter',  'icon' => '📦', 'desc' => 'Quick & affordable',                'default_days' => 3, 'default_rev' => 1 ),
            'standard' => array( 'label' => 'Standard', 'icon' => '⭐', 'desc' => 'Most popular',                       'default_days' => 5, 'default_rev' => 2 ),
            'premium'  => array( 'label' => 'Pro',      'icon' => '💎', 'desc' => 'Premium with extras',                'default_days' => 7, 'default_rev' => 3 ),
        );

        // AJAX upload nonce
        $upload_nonce = wp_create_nonce( 'nmep_upload_cover' );

        ob_start();
        echo self::render_status_messages();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 900px;">

                <p><a href="<?php echo esc_url( nmep_get_page_url( 'expert-dashboard' ) ); ?>" style="color: var(--nme-emerald);">← Back to Dashboard</a></p>

                <h2><?php echo $is_new ? '✨ Create New Service' : '✏️ Edit Service'; ?></h2>

                <?php if ( $service && $service->status === NMEP_Services::STATUS_REJECTED ) : ?>
                    <div class="nme-card" style="border-left: 4px solid #EF4444; background: #FEF2F2;">
                        <h4 style="margin-top:0; color: #991B1B;">❌ Service was rejected</h4>
                        <p><strong>Reason:</strong> <?php echo esc_html( $service->rejection_reason ); ?></p>
                        <p>Edit and re-submit when ready.</p>
                    </div>
                <?php elseif ( $service && $service->status === NMEP_Services::STATUS_PENDING_REVIEW ) : ?>
                    <div class="nme-card" style="border-left: 4px solid #F59E0B; background: #FFFBEB;">
                        <p style="margin:0;"><strong>⏳ Under Review</strong> — Editing this service will pause it until re-approved.</p>
                    </div>
                <?php endif; ?>

                <?php if ( $restored ) : ?>
                    <div class="nme-card" style="border-left: 4px solid #3B82F6; background: #EFF6FF;">
                        <p style="margin:0; color: #1E40AF;">💾 <strong>Form data restored.</strong> Your previous typing is shown below — no need to retype!</p>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card" id="nmep-service-form">
                    <input type="hidden" name="action" value="nmep_save_service">
                    <input type="hidden" name="service_id" value="<?php echo (int) ( $service->id ?? 0 ); ?>">
                    <?php wp_nonce_field( 'nmep_save_service', 'nmep_nonce' ); ?>

                    <h3>1. About This Service</h3>

                    <p>
                        <label>Service Title *</label>
                        <input type="text" name="title" required minlength="10" maxlength="200"
                               value="<?php echo esc_attr( $get( 'title' ) ); ?>"
                               placeholder="I will design a professional YouTube thumbnail for you">
                        <small style="color: var(--nme-text-light);">Start with "I will..." (10-200 characters)</small>
                    </p>

                    <p>
                        <label>Category *</label>
                        <select name="category_id" required>
                            <option value="">— Select category —</option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo (int) $cat->id; ?>" <?php selected( (int) $get( 'category_id', 0 ), $cat->id ); ?>>
                                    <?php echo esc_html( $cat->icon . ' ' . $cat->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p>
                        <label>Short Description *</label>
                        <input type="text" name="short_description" required minlength="30" maxlength="300"
                               value="<?php echo esc_attr( $get( 'short_description' ) ); ?>"
                               placeholder="One-line summary that appears in search results">
                        <small style="color: var(--nme-text-light);">30-300 characters</small>
                    </p>

                    <p>
                        <label>Full Description *</label>
                        <textarea name="description" rows="8" required
                                  placeholder="Describe what you offer in detail. What does the buyer get? What is your process? What makes you different?"><?php echo esc_textarea( $get( 'description' ) ); ?></textarea>
                        <small style="color: var(--nme-text-light);">Minimum 100 characters. Use line breaks for readability.</small>
                    </p>

                    <!-- v1.5.4: PHOTO UPLOAD WIDGET (replaces URL input) -->
                    <div style="margin: 20px 0;">
                        <label>Cover Photo (optional)</label>
                        <div id="nmep-cover-uploader" style="border: 2px dashed #D1D5DB; border-radius: 12px; padding: 24px; text-align: center; background: #F9FAFB; cursor: pointer; transition: all 0.2s;">
                            <?php $existing_cover = $get( 'cover_image' ); ?>

                            <div id="nmep-cover-preview" style="<?php echo $existing_cover ? '' : 'display:none;'; ?> margin-bottom: 12px;">
                                <?php if ( $existing_cover ) : ?>
                                    <img src="<?php echo esc_url( $existing_cover ); ?>" style="max-width: 100%; max-height: 240px; border-radius: 8px;" alt="Cover preview">
                                <?php endif; ?>
                            </div>

                            <div id="nmep-cover-prompt" style="<?php echo $existing_cover ? 'display:none;' : ''; ?>">
                                <div style="font-size: 2.5rem; margin-bottom: 8px;">📷</div>
                                <div style="font-weight: 600; color: var(--nme-forest); margin-bottom: 4px;">Tap to upload a cover photo</div>
                                <div style="color: var(--nme-text-light); font-size: 0.85rem;">JPG or PNG · Max 5MB · Recommended 1280×720</div>
                            </div>

                            <div id="nmep-cover-actions" style="<?php echo $existing_cover ? '' : 'display:none;'; ?> margin-top: 12px;">
                                <button type="button" id="nmep-cover-replace" class="nme-btn nme-btn-outline" style="font-size: 0.85rem;">🔄 Replace</button>
                                <button type="button" id="nmep-cover-remove" class="nme-btn" style="background: #FEE2E2; color: #991B1B; font-size: 0.85rem;">🗑️ Remove</button>
                            </div>

                            <div id="nmep-cover-progress" style="display:none; margin-top: 12px;">
                                <div style="background: #E5E7EB; border-radius: 4px; height: 8px; overflow: hidden;">
                                    <div id="nmep-cover-bar" style="background: var(--nme-emerald); height: 100%; width: 0%; transition: width 0.3s;"></div>
                                </div>
                                <small style="color: var(--nme-text-light);">Uploading…</small>
                            </div>

                            <div id="nmep-cover-error" style="display:none; margin-top: 12px; color: #991B1B; background: #FEE2E2; padding: 8px; border-radius: 6px; font-size: 0.85rem;"></div>
                        </div>

                        <input type="hidden" name="cover_image" id="nmep-cover-url" value="<?php echo esc_attr( $existing_cover ); ?>">
                        <input type="file" id="nmep-cover-file" accept="image/jpeg,image/png" style="display:none;">

                        <small style="color: var(--nme-text-light); display: block; margin-top: 8px;">
                            💡 No cover? No problem — we'll generate a beautiful card with your service title automatically.
                        </small>
                    </div>

                    <p>
                        <label>Demo Video URL (optional)</label>
                        <input type="url" name="video_url" value="<?php echo esc_attr( $get( 'video_url' ) ); ?>" placeholder="https://youtube.com/watch?v=...">
                    </p>

                    <p>
                        <label>Tags (comma-separated)</label>
                        <input type="text" name="tags" maxlength="500" value="<?php echo esc_attr( $get( 'tags' ) ); ?>" placeholder="thumbnail, youtube, design, photoshop">
                    </p>

                    <h3 class="nme-mt-4">2. Pricing — Choose your packages</h3>
                    <p style="color: var(--nme-text-light); margin-bottom: 8px;">
                        ⚡ <strong>Offer 1, 2, or 3 packages.</strong> At least one is required.
                        Min ₹<?php echo $min_price; ?>, Max ₹<?php echo $max_price; ?>.
                    </p>
                    <p style="color: var(--nme-text-light); font-size: 0.85rem;">
                        💡 <strong>Pro tip:</strong> Most successful sellers offer all 3 packages — buyers usually pick the middle one.
                    </p>

                    <?php
                    foreach ( $tier_meta as $tier_key => $tm ) :
                        $title    = $get( $tier_key . '_title' );
                        $price    = $get( $tier_key . '_price' );
                        $days     = $get( $tier_key . '_delivery_days', $tm['default_days'] );
                        $rev      = $get( $tier_key . '_revisions',     $tm['default_rev'] );
                        $features = $get( $tier_key . '_features' );

                        // First tier (Starter) is recommended/visible by default
                        $is_first = ( $tier_key === 'basic' );
                        $has_data = ! empty( $price ) || ! empty( $title ) || ! empty( $features );
                        $show_initially = $is_first || $has_data;
                    ?>
                        <div class="nmep-tier-block" data-tier="<?php echo esc_attr( $tier_key ); ?>"
                             style="background: var(--nme-bg-soft); padding: 20px; border-radius: 12px; margin-bottom: 16px; border: 2px solid <?php echo $is_first ? 'var(--nme-emerald)' : 'transparent'; ?>; <?php echo $show_initially ? '' : 'display:none;'; ?>">

                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <h4 style="margin: 0; color: var(--nme-emerald);">
                                    <?php echo esc_html( $tm['icon'] ); ?>
                                    <?php echo esc_html( $tm['label'] ); ?>
                                    <?php if ( $is_first ) : ?>
                                        <span style="font-size: 0.7rem; background: var(--nme-gold); color: #fff; padding: 2px 8px; border-radius: 50px; margin-left: 8px;">RECOMMENDED</span>
                                    <?php endif; ?>
                                </h4>
                                <?php if ( ! $is_first ) : ?>
                                    <button type="button" class="nmep-tier-toggle nme-btn" data-target="<?php echo esc_attr( $tier_key ); ?>" style="background: #FEE2E2; color: #991B1B; font-size: 0.8rem; padding: 4px 12px;">✕ Remove this package</button>
                                <?php endif; ?>
                            </div>

                            <p style="color: var(--nme-text-light); font-size: 0.85rem; margin-bottom: 12px;">
                                <?php echo esc_html( $tm['desc'] ); ?>
                            </p>

                            <p>
                                <label>Package title (what you call this offer)</label>
                                <input type="text" name="<?php echo esc_attr( $tier_key ); ?>_title" maxlength="80"
                                       value="<?php echo esc_attr( $title ); ?>"
                                       placeholder="e.g., 1 thumbnail design">
                            </p>

                            <div class="nme-grid nme-grid-2">
                                <p>
                                    <label>Price (₹) <?php echo $is_first ? '*' : '(leave blank to skip)'; ?></label>
                                    <input type="number" name="<?php echo esc_attr( $tier_key ); ?>_price"
                                           min="0" max="<?php echo $max_price; ?>" step="1"
                                           value="<?php echo esc_attr( $price ); ?>"
                                           placeholder="<?php echo $min_price; ?>">
                                </p>
                                <p>
                                    <label>Delivery (days)</label>
                                    <input type="number" name="<?php echo esc_attr( $tier_key ); ?>_delivery_days"
                                           min="1" max="60"
                                           value="<?php echo esc_attr( $days ); ?>">
                                </p>
                                <p>
                                    <label>Revisions allowed</label>
                                    <input type="number" name="<?php echo esc_attr( $tier_key ); ?>_revisions"
                                           min="0" max="20"
                                           value="<?php echo esc_attr( $rev ); ?>">
                                </p>
                            </div>
                            <p>
                                <label>What's included (one feature per line)</label>
                                <textarea name="<?php echo esc_attr( $tier_key ); ?>_features" rows="4"
                                          placeholder="Custom design&#10;1080p HD quality&#10;Source files included"><?php echo esc_textarea( $features ); ?></textarea>
                            </p>
                        </div>
                    <?php endforeach; ?>

                    <!-- "Add tier" buttons (shown when tier hidden) -->
                    <div id="nmep-add-tier-buttons" style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
                        <button type="button" class="nmep-add-tier nme-btn nme-btn-outline" data-target="standard" style="<?php echo ! empty( $get( 'standard_price' ) ) ? 'display:none;' : ''; ?>">
                            ⭐ Add Standard package
                        </button>
                        <button type="button" class="nmep-add-tier nme-btn nme-btn-outline" data-target="premium" style="<?php echo ! empty( $get( 'premium_price' ) ) ? 'display:none;' : ''; ?>">
                            💎 Add Pro package
                        </button>
                    </div>

                    <h3 class="nme-mt-4">3. Buyer Requirements</h3>
                    <p>
                        <label>What do you need from buyers to start work?</label>
                        <textarea name="requirements" rows="4" placeholder="e.g., Channel name, video topic, brand colors, reference images..."><?php echo esc_textarea( $get( 'requirements' ) ); ?></textarea>
                        <small style="color: var(--nme-text-light);">Buyers will provide this info during checkout.</small>
                    </p>

                    <p class="nme-mt-4 nme-text-center">
                        <button type="submit" class="nme-btn nme-btn-gold" style="font-size: 1.05rem; padding: 14px 32px;">💾 Save Service</button>
                    </p>
                </form>

                <?php if ( $service && in_array( $service->status, array( NMEP_Services::STATUS_DRAFT, NMEP_Services::STATUS_REJECTED ), true ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card nme-mt-3 nme-text-center">
                        <input type="hidden" name="action" value="nmep_submit_service">
                        <input type="hidden" name="service_id" value="<?php echo (int) $service->id; ?>">
                        <?php wp_nonce_field( 'nmep_submit_service_' . $service->id ); ?>
                        <h3>Ready to go live?</h3>
                        <p>Submit for review. We'll check your service within 24-48 hours.</p>
                        <button type="submit" class="nme-btn nme-btn-gold">📤 Submit for Review</button>
                    </form>
                <?php endif; ?>

                <?php if ( $service && $service->status === NMEP_Services::STATUS_ACTIVE ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card nme-mt-3 nme-text-center" style="border-left: 4px solid #6366F1;">
                        <input type="hidden" name="action" value="nmep_pause_service">
                        <input type="hidden" name="service_id" value="<?php echo (int) $service->id; ?>">
                        <?php wp_nonce_field( 'nmep_pause_service_' . $service->id ); ?>
                        <p>Need a break? You can pause this service and resume later.</p>
                        <button type="submit" class="nme-btn nme-btn-outline">⏸ Pause Service</button>
                    </form>
                <?php endif; ?>

                <?php if ( $service && $service->status === NMEP_Services::STATUS_PAUSED ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card nme-mt-3 nme-text-center">
                        <input type="hidden" name="action" value="nmep_resume_service">
                        <input type="hidden" name="service_id" value="<?php echo (int) $service->id; ?>">
                        <?php wp_nonce_field( 'nmep_resume_service_' . $service->id ); ?>
                        <p>Ready to start receiving orders again?</p>
                        <button type="submit" class="nme-btn nme-btn-gold">▶ Resume Service</button>
                    </form>
                <?php endif; ?>

            </div>
        </section>

        <!-- v1.5.4: Photo upload + tier toggle JS -->
        <script>
        (function() {
            const ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
            const uploadNonce = '<?php echo esc_js( $upload_nonce ); ?>';

            const uploader   = document.getElementById('nmep-cover-uploader');
            const fileInput  = document.getElementById('nmep-cover-file');
            const urlInput   = document.getElementById('nmep-cover-url');
            const preview    = document.getElementById('nmep-cover-preview');
            const prompt     = document.getElementById('nmep-cover-prompt');
            const actions    = document.getElementById('nmep-cover-actions');
            const progress   = document.getElementById('nmep-cover-progress');
            const bar        = document.getElementById('nmep-cover-bar');
            const errBox     = document.getElementById('nmep-cover-error');
            const replaceBtn = document.getElementById('nmep-cover-replace');
            const removeBtn  = document.getElementById('nmep-cover-remove');

            function showError(msg) {
                errBox.textContent = msg;
                errBox.style.display = 'block';
                progress.style.display = 'none';
            }

            function hideError() {
                errBox.style.display = 'none';
            }

            function showUploaded(url) {
                preview.innerHTML = '<img src="' + url + '" style="max-width:100%;max-height:240px;border-radius:8px;" alt="Cover">';
                preview.style.display = '';
                prompt.style.display = 'none';
                actions.style.display = '';
                progress.style.display = 'none';
                urlInput.value = url;
                hideError();
            }

            function clearImage() {
                urlInput.value = '';
                preview.innerHTML = '';
                preview.style.display = 'none';
                actions.style.display = 'none';
                prompt.style.display = '';
                hideError();
            }

            function uploadFile(file) {
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) {
                    showError('Photo must be under 5MB. Yours is ' + (file.size / 1048576).toFixed(1) + 'MB.');
                    return;
                }
                if (!['image/jpeg', 'image/png'].includes(file.type)) {
                    showError('Only JPG or PNG photos. Got: ' + file.type);
                    return;
                }

                hideError();
                progress.style.display = 'block';
                bar.style.width = '0%';

                const fd = new FormData();
                fd.append('action', 'nmep_upload_cover');
                fd.append('nonce', uploadNonce);
                fd.append('cover_image', file);

                const xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        bar.style.width = Math.round((e.loaded / e.total) * 100) + '%';
                    }
                });
                xhr.addEventListener('load', function() {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        if (res.success && res.data && res.data.url) {
                            showUploaded(res.data.url);
                        } else {
                            showError((res.data && res.data.message) || 'Upload failed.');
                        }
                    } catch (e) {
                        showError('Server error. Please try again.');
                    }
                });
                xhr.addEventListener('error', function() {
                    showError('Connection error. Please try again.');
                });
                xhr.open('POST', ajaxUrl);
                xhr.send(fd);
            }

            // Click-to-upload
            uploader.addEventListener('click', function(e) {
                if (e.target === replaceBtn || e.target === removeBtn) return;
                fileInput.click();
            });
            replaceBtn && replaceBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                fileInput.click();
            });
            removeBtn && removeBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                clearImage();
            });

            fileInput.addEventListener('change', function() {
                if (fileInput.files && fileInput.files[0]) {
                    uploadFile(fileInput.files[0]);
                }
            });

            // Drag and drop
            ['dragenter', 'dragover'].forEach(function(ev) {
                uploader.addEventListener(ev, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    uploader.style.borderColor = 'var(--nme-emerald, #10B981)';
                    uploader.style.background  = '#ECFDF5';
                });
            });
            ['dragleave', 'drop'].forEach(function(ev) {
                uploader.addEventListener(ev, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    uploader.style.borderColor = '#D1D5DB';
                    uploader.style.background  = '#F9FAFB';
                });
            });
            uploader.addEventListener('drop', function(e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) {
                    uploadFile(e.dataTransfer.files[0]);
                }
            });

            // === TIER TOGGLE LOGIC ===
            document.querySelectorAll('.nmep-add-tier').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const target = btn.getAttribute('data-target');
                    const block = document.querySelector('.nmep-tier-block[data-tier="' + target + '"]');
                    if (block) {
                        block.style.display = '';
                        btn.style.display = 'none';
                        block.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                });
            });

            document.querySelectorAll('.nmep-tier-toggle').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const target = btn.getAttribute('data-target');
                    const block = document.querySelector('.nmep-tier-block[data-tier="' + target + '"]');
                    if (block) {
                        // Clear price (so it won't validate)
                        const priceInput = block.querySelector('input[name="' + target + '_price"]');
                        if (priceInput) priceInput.value = '';
                        block.style.display = 'none';
                        // Show the "Add" button
                        const addBtn = document.querySelector('.nmep-add-tier[data-target="' + target + '"]');
                        if (addBtn) addBtn.style.display = '';
                    }
                });
            });
        })();
        </script>

        <style>
            #nmep-cover-uploader:hover { border-color: var(--nme-emerald, #10B981); }
            .nmep-tier-block label { font-weight: 600; color: var(--nme-forest, #0F2419); }
        </style>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       BROWSE SERVICES (public)
       ============================================================ */

    public static function sc_browse_services( $atts ) {
        $a = shortcode_atts( array(
            'category' => isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : '',
            'search'   => isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '',
            'limit'    => 24,
        ), $atts );

        $args = array(
            'limit'  => (int) $a['limit'],
            'search' => $a['search'],
            'order_by' => isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'popular',
        );

        if ( $a['category'] ) {
            $cat = NMEP_Compat::get_category_by_slug( $a['category'] );
            if ( $cat ) $args['category_id'] = $cat->id;
        }

        $services = NMEP_Services::get_active( $args );
        $categories = NMEP_Compat::get_all_categories();

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container">

                <!-- Filter bar -->
                <div class="nme-card nme-mb-3">
                    <form method="get" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                        <select name="category" style="flex: 1; min-width: 200px;">
                            <option value="">All categories</option>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $a['category'], $cat->slug ); ?>>
                                    <?php echo esc_html( $cat->icon . ' ' . $cat->name ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="q" value="<?php echo esc_attr( $a['search'] ); ?>" placeholder="Search services..." style="flex: 2; min-width: 240px;">
                        <select name="sort" style="min-width: 160px;">
                            <option value="popular" <?php selected( $args['order_by'], 'popular' ); ?>>Most Popular</option>
                            <option value="newest" <?php selected( $args['order_by'], 'newest' ); ?>>Newest</option>
                            <option value="rating" <?php selected( $args['order_by'], 'rating' ); ?>>Highest Rated</option>
                            <option value="price_low" <?php selected( $args['order_by'], 'price_low' ); ?>>Price: Low to High</option>
                            <option value="price_high" <?php selected( $args['order_by'], 'price_high' ); ?>>Price: High to Low</option>
                        </select>
                        <button type="submit" class="nme-btn">Search</button>
                    </form>
                </div>

                <!-- Results -->
                <?php if ( empty( $services ) ) : ?>
                    <div class="nme-card nme-text-center" style="padding: 60px 20px;">
                        <div style="font-size: 4rem;">🔍</div>
                        <h3>No services found</h3>
                        <p>Try a different search or browse all categories.</p>
                    </div>
                <?php else : ?>
                    <p style="color: var(--nme-text-light);"><?php echo count( $services ); ?> services found</p>
                    <div class="nme-grid nme-grid-3">
                        <?php foreach ( $services as $service ) : ?>
                            <?php echo self::render_service_card( $service ); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function sc_featured_services( $atts ) {
        $a = shortcode_atts( array( 'limit' => 8 ), $atts );
        $services = NMEP_Services::get_active( array( 'limit' => (int) $a['limit'], 'order_by' => 'popular' ) );

        if ( empty( $services ) ) {
            return '<div class="nme-card nme-text-center"><p>No services published yet. Check back soon!</p></div>';
        }

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container">
                <div class="nme-text-center nme-mb-4">
                    <h2>Featured Services</h2>
                    <p>Top-rated services from our most trusted experts.</p>
                </div>
                <div class="nme-grid nme-grid-4">
                    <?php foreach ( $services as $s ) echo self::render_service_card( $s ); ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Service card (used in lists)
     */
    private static function render_service_card( $service ) {
        $url = NMEP_Services::get_service_url( $service );
        $expert_name = $service->expert_name ?? 'Expert';

        // v1.5.4: Find lowest price (not specifically basic) - tier might be optional now
        $lowest_price = 0;
        foreach ( array( 'basic', 'standard', 'premium' ) as $t ) {
            $p = (float) ( $service->{$t . '_price'} ?? 0 );
            if ( $p > 0 && ( $lowest_price === 0 || $p < $lowest_price ) ) {
                $lowest_price = $p;
            }
        }

        ob_start();
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="nme-card" style="text-decoration: none; display: block; padding: 0; overflow: hidden;">
            <?php nmep_render_service_cover( $service, '16/9', array( 'expert_name' => $expert_name ) ); ?>
            <div style="padding: 20px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <span style="font-size: 0.85rem; color: var(--nme-text-light);">By <?php echo esc_html( $expert_name ); ?></span>
                    <?php if ( ! empty( $service->is_founding_expert ) ) : ?>
                        <span style="background: var(--nme-gold); color: var(--nme-forest); padding: 2px 6px; border-radius: 4px; font-size: 0.65rem; font-weight: 700;">★ FOUNDING</span>
                    <?php endif; ?>
                    <?php if ( ! empty( $service->expert_user_id ) ) { echo apply_filters( 'nmep_service_card_after_expert', '', $service->expert_user_id ); } ?>
                </div>
                <h3 style="font-size: 1.1rem; margin: 0 0 10px; color: var(--nme-forest); line-height: 1.4;">
                    <?php echo esc_html( $service->title ); ?>
                </h3>
                <?php if ( $service->reviews_count > 0 ) : ?>
                    <div style="font-size: 0.9rem; color: var(--nme-text-light); margin-bottom: 12px;">
                        ⭐ <?php echo number_format( (float) $service->avg_rating, 1 ); ?>
                        <span style="opacity: 0.6;">(<?php echo (int) $service->reviews_count; ?>)</span>
                    </div>
                <?php endif; ?>
                <div style="border-top: 1px solid var(--nme-border); padding-top: 12px;">
                    <span style="font-size: 0.8rem; color: var(--nme-text-light); text-transform: uppercase; letter-spacing: 0.05em;">Starting at</span>
                    <div style="font-size: 1.4rem; font-weight: 700; color: var(--nme-forest);"><?php echo nmep_format_inr( $lowest_price ); ?></div>
                </div>
            </div>
        </a>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       SINGLE SERVICE PAGE
       ============================================================ */

    public static function sc_service_view( $atts ) {
        $a = shortcode_atts( array( 'slug' => '', 'id' => 0 ), $atts );
        $service = null;
        if ( $a['id'] ) {
            $service = NMEP_Services::get( (int) $a['id'] );
        } elseif ( $a['slug'] ) {
            $service = NMEP_Services::get_by_slug( $a['slug'] );
        }

        if ( ! $service ) {
            return '<div class="nme-card nme-text-center"><p>Service not found.</p></div>';
        }

        return self::render_single_service( $service );
    }

    /**
     * Full single service page
     */
    public static function render_single_service( $service ) {
        $expert = NMEP_Compat::get_expert( $service->expert_id );
        $category = NMEP_Compat::get_category_by_id( $service->category_id );

        // v1.5.4: Get only tiers that are actually offered (have a price)
        $tiers_available = NMEP_Services::get_active_tiers( $service );
        if ( empty( $tiers_available ) ) {
            $tiers_available = array( 'basic' );
        }

        // Default to first available tier (might not be 'basic')
        $selected_tier = isset( $_GET['tier'] ) ? sanitize_key( $_GET['tier'] ) : '';
        if ( ! in_array( $selected_tier, $tiers_available, true ) ) {
            $selected_tier = $tiers_available[0];
        }

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 1100px;">
                <p style="color: var(--nme-text-light); font-size: 0.9rem;">
                    <a href="/services/" style="color: var(--nme-emerald);">Browse Services</a>
                    <?php if ( $category ) : ?> › <a href="/services/?category=<?php echo esc_attr( $category->slug ); ?>" style="color: var(--nme-emerald);"><?php echo esc_html( $category->name ); ?></a><?php endif; ?>
                </p>

                <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 32px; align-items: start;" class="nmep-service-grid">

                    <!-- LEFT: Service details -->
                    <div>
                        <h1 style="margin-top: 0;"><?php echo esc_html( $service->title ); ?></h1>

                        <!-- Expert mini-profile -->
                        <?php if ( $expert ) :
                            $expert_profile_url = class_exists( 'NMEP_Expert_Profiles' ) ? NMEP_Expert_Profiles::get_expert_url( $expert ) : '';
                        ?>
                            <div style="display: flex; align-items: center; gap: 12px; margin: 16px 0;">
                                <?php if ( $expert->profile_photo ) : ?>
                                    <?php if ( $expert_profile_url ) : ?>
                                        <a href="<?php echo esc_url( $expert_profile_url ); ?>"><img src="<?php echo esc_url( $expert->profile_photo ); ?>" style="width: 44px; height: 44px; border-radius: 50%; border: 2px solid var(--nme-gold);"></a>
                                    <?php else : ?>
                                        <img src="<?php echo esc_url( $expert->profile_photo ); ?>" style="width: 44px; height: 44px; border-radius: 50%; border: 2px solid var(--nme-gold);">
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight: 600; color: var(--nme-forest);">
                                        <?php if ( $expert_profile_url ) : ?>
                                            <a href="<?php echo esc_url( $expert_profile_url ); ?>" style="color: var(--nme-forest); text-decoration: none; border-bottom: 1px dotted var(--nme-emerald);"><?php echo esc_html( $expert->full_name ); ?></a>
                                        <?php else : ?>
                                            <?php echo esc_html( $expert->full_name ); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.85rem; color: var(--nme-text-light);">
                                        <?php echo NMEP_Compat::get_tier_badge( $expert->tier ); ?>
                                        <?php if ( $service->reviews_count > 0 ) : ?>
                                            ⭐ <?php echo number_format( (float) $service->avg_rating, 1 ); ?>
                                            (<?php echo (int) $service->reviews_count; ?> reviews)
                                        <?php endif; ?>
                                        <?php if ( $expert_profile_url ) : ?>
                                            · <a href="<?php echo esc_url( $expert_profile_url ); ?>" style="color: var(--nme-emerald);">View Profile →</a>
                                        <?php endif; ?>
                                        <?php if ( class_exists( 'NMEP_Inbox' ) ) :
                                            $msg_url = is_user_logged_in()
                                                ? add_query_arg( 'expert_id', (int) $expert->id, NMEP_Inbox::buyer_inbox_url() )
                                                : wp_login_url( add_query_arg( 'expert_id', (int) $expert->id, NMEP_Inbox::buyer_inbox_url() ) );
                                        ?>
                                            · <a href="<?php echo esc_url( $msg_url ); ?>" style="color: var(--nme-emerald);">💬 Message Expert</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Cover image (v1.5.4: uses helper with auto-placeholder) -->
                        <div style="margin-bottom: 24px;">
                            <?php nmep_render_service_cover( $service, '16/9', array( 'expert_name' => $expert ? $expert->full_name : '' ) ); ?>
                        </div>

                        <!-- Description -->
                        <div class="nme-card">
                            <h2>About This Service</h2>
                            <div style="line-height: 1.8;"><?php echo wp_kses_post( wpautop( $service->description ) ); ?></div>
                        </div>

                        <!-- Requirements -->
                        <?php if ( ! empty( $service->requirements ) ) : ?>
                            <div class="nme-card nme-mt-3">
                                <h3>What I'll Need From You</h3>
                                <p style="white-space: pre-line;"><?php echo esc_html( $service->requirements ); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php
                        // v1.5.4: COMPARE PACKAGES TABLE (only when 2+ tiers offered)
                        if ( count( $tiers_available ) >= 2 ) :
                        ?>
                            <div class="nme-card nme-mt-3">
                                <h3>Compare Packages</h3>
                                <div style="overflow-x: auto;">
                                    <table style="width: 100%; border-collapse: collapse; min-width: 480px;">
                                        <thead>
                                            <tr style="background: var(--nme-bg-soft, #F9FAFB);">
                                                <th style="text-align: left; padding: 14px 12px; font-weight: 600; color: var(--nme-text-light); font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px;">Package</th>
                                                <?php foreach ( $tiers_available as $tier ) :
                                                    $is_active = $tier === $selected_tier;
                                                ?>
                                                    <th style="text-align: left; padding: 14px 12px; min-width: 160px; <?php echo $is_active ? 'background: var(--nme-forest, #0F2419); color: #fff;' : 'color: var(--nme-forest);'; ?>">
                                                        <div style="font-size: 1.4rem; font-weight: 700; margin-bottom: 4px;">
                                                            <?php echo nmep_format_inr( NMEP_Services::get_package_price( $service, $tier ) ); ?>
                                                        </div>
                                                        <div style="font-size: 0.95rem; font-weight: 600; opacity: <?php echo $is_active ? '1' : '0.85'; ?>;">
                                                            <?php echo esc_html( NMEP_Services::get_friendly_tier_name( $tier ) ); ?>
                                                        </div>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                            <tr>
                                                <th style="text-align: left; padding: 14px 12px;"></th>
                                                <?php foreach ( $tiers_available as $tier ) : ?>
                                                    <th style="text-align: left; padding: 8px 12px; font-weight: 600; color: var(--nme-forest); border-top: 1px solid var(--nme-border, #E5E7EB);">
                                                        <?php
                                                        // Show seller's custom title (Frontend Deployment, etc.)
                                                        $custom = trim( (string) $service->{$tier . '_title'} );
                                                        echo $custom !== '' ? esc_html( strtoupper( $custom ) ) : '&nbsp;';
                                                        ?>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Description row -->
                                            <tr>
                                                <td style="padding: 14px 12px; vertical-align: top; color: var(--nme-text-light); border-top: 1px solid var(--nme-border, #E5E7EB);">Description</td>
                                                <?php foreach ( $tiers_available as $tier ) :
                                                    $features = NMEP_Services::get_package_features( $service, $tier );
                                                ?>
                                                    <td style="padding: 14px 12px; vertical-align: top; border-top: 1px solid var(--nme-border, #E5E7EB);">
                                                        <?php if ( ! empty( $features ) ) : ?>
                                                            <ul style="list-style: none; padding: 0; margin: 0;">
                                                                <?php foreach ( $features as $f ) : ?>
                                                                    <li style="padding: 3px 0; font-size: 0.9rem;">✓ <?php echo esc_html( $f ); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php else : ?>
                                                            <span style="color: var(--nme-text-light); font-size: 0.85rem;">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <!-- Delivery time row -->
                                            <tr>
                                                <td style="padding: 14px 12px; color: var(--nme-text-light); border-top: 1px solid var(--nme-border, #E5E7EB);">Delivery</td>
                                                <?php foreach ( $tiers_available as $tier ) : ?>
                                                    <td style="padding: 14px 12px; border-top: 1px solid var(--nme-border, #E5E7EB);">
                                                        ⏱️ <?php echo NMEP_Services::get_delivery_days( $service, $tier ); ?> days
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <!-- Revisions row -->
                                            <tr>
                                                <td style="padding: 14px 12px; color: var(--nme-text-light); border-top: 1px solid var(--nme-border, #E5E7EB);">Revisions</td>
                                                <?php foreach ( $tiers_available as $tier ) : ?>
                                                    <td style="padding: 14px 12px; border-top: 1px solid var(--nme-border, #E5E7EB);">
                                                        🔄 <?php echo NMEP_Services::get_revisions( $service, $tier ); ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                            <!-- Select button row -->
                                            <tr>
                                                <td style="padding: 16px 12px; border-top: 2px solid var(--nme-border, #E5E7EB);"></td>
                                                <?php foreach ( $tiers_available as $tier ) :
                                                    $checkout_url = add_query_arg( array( 'service_id' => $service->id, 'tier' => $tier ), nmep_get_page_url( 'checkout' ) );
                                                ?>
                                                    <td style="padding: 16px 12px; border-top: 2px solid var(--nme-border, #E5E7EB);">
                                                        <a href="<?php echo esc_url( $checkout_url ); ?>" class="nme-btn nme-btn-gold" style="display: inline-block; font-size: 0.85rem; padding: 8px 16px;">Select →</a>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php
                        // REVIEWS SECTION (Batch E)
                        $reviews = NMEP_Reviews::get_for_service( $service->id, 10 );
                        if ( ! empty( $reviews ) || $service->reviews_count > 0 ) :
                        ?>
                            <div class="nme-card nme-mt-3">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 16px;">
                                    <h3 style="margin: 0;">Reviews (<?php echo (int) $service->reviews_count; ?>)</h3>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php echo NMEP_Reviews::render_stars( $service->avg_rating, 22 ); ?>
                                        <span style="font-size: 1.4rem; font-weight: 700; color: var(--nme-forest, #0F2419);"><?php echo number_format( (float) $service->avg_rating, 1 ); ?></span>
                                    </div>
                                </div>

                                <?php foreach ( $reviews as $review ) : ?>
                                    <div style="padding: 16px 0; border-bottom: 1px solid var(--nme-border, #E5E7EB);">
                                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                                            <div>
                                                <strong><?php echo esc_html( $review->buyer_name ); ?></strong>
                                                <?php if ( $review->would_recommend ) : ?>
                                                    <span style="font-size: 0.7rem; background: #ECFDF5; color: #065F46; padding: 2px 8px; border-radius: 50px; margin-left: 4px;">✓ Recommends</span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <?php echo NMEP_Reviews::render_stars( $review->rating, 14 ); ?>
                                                <small style="color: var(--nme-text-light, #6B7280); margin-left: 6px;"><?php echo esc_html( date( 'd M Y', strtotime( $review->created_at ) ) ); ?></small>
                                            </div>
                                        </div>
                                        <p style="margin: 0; color: var(--nme-text, #374151); white-space: pre-line;"><?php echo esc_html( $review->comment ); ?></p>

                                        <?php if ( ! empty( $review->expert_response ) ) : ?>
                                            <div style="background: var(--nme-bg-soft, #F9FAFB); border-left: 3px solid var(--nme-emerald, #10B981); padding: 10px 14px; margin-top: 10px; border-radius: 6px;">
                                                <strong style="font-size: 0.85rem; color: var(--nme-emerald, #10B981);">Expert's Response:</strong>
                                                <p style="margin: 4px 0 0; font-size: 0.9rem; color: var(--nme-text, #374151);"><?php echo esc_html( $review->expert_response ); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT: Pricing & order -->
                    <div>
                        <div class="nme-card" style="position: sticky; top: 100px;">
                            <!-- Tier tabs -->
                            <div style="display: flex; gap: 4px; border-bottom: 2px solid var(--nme-border); margin-bottom: 16px;">
                                <?php
                                // v1.5.4: tiers_available was set above
                                foreach ( $tiers_available as $tier ) :
                                    $is_active = $tier === $selected_tier;
                                    $tier_url = add_query_arg( 'tier', $tier );
                                    // Show seller's custom title if set, else friendly name (Starter/Standard/Pro)
                                    $tab_label = NMEP_Services::get_friendly_tier_name( $tier );
                                ?>
                                    <a href="<?php echo esc_url( $tier_url ); ?>" style="flex: 1; text-align: center; padding: 12px; text-decoration: none; font-weight: 600; <?php echo $is_active ? 'background: var(--nme-forest); color: var(--nme-white); border-radius: 8px 8px 0 0;' : 'color: var(--nme-text-light);'; ?>">
                                        <?php echo esc_html( $tab_label ); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <!-- Selected tier details -->
                            <h3 style="margin-top: 0;"><?php echo esc_html( NMEP_Services::get_package_title( $service, $selected_tier ) ); ?></h3>
                            <div style="font-size: 2rem; font-weight: 700; color: var(--nme-forest); margin-bottom: 16px;">
                                <?php echo nmep_format_inr( NMEP_Services::get_package_price( $service, $selected_tier ) ); ?>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <div style="display: flex; gap: 16px; color: var(--nme-text-light); font-size: 0.9rem;">
                                    <div>⏱️ <?php echo NMEP_Services::get_delivery_days( $service, $selected_tier ); ?> days</div>
                                    <div>🔄 <?php echo NMEP_Services::get_revisions( $service, $selected_tier ); ?> revisions</div>
                                </div>
                            </div>

                            <?php
                            $features = NMEP_Services::get_package_features( $service, $selected_tier );
                            if ( ! empty( $features ) ) :
                            ?>
                                <ul style="list-style: none; padding: 0; margin: 0 0 20px;">
                                    <?php foreach ( $features as $f ) : ?>
                                        <li style="padding: 8px 0; border-bottom: 1px solid var(--nme-border); color: var(--nme-text);">✓ <?php echo esc_html( $f ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <!-- Order button -->
                            <a href="<?php echo esc_url( add_query_arg( array( 'service_id' => $service->id, 'tier' => $selected_tier ), nmep_get_page_url( 'checkout' ) ) ); ?>" class="nme-btn nme-btn-gold" style="width: 100%; display: flex;">
                                Continue (<?php echo nmep_format_inr( NMEP_Services::get_package_price( $service, $selected_tier ) ); ?>)
                            </a>

                            <p style="font-size: 0.8rem; color: var(--nme-text-light); margin: 16px 0 0; text-align: center;">
                                🛡️ Payment held in escrow until you approve the work
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <style>
            @media (max-width: 880px) {
                .nmep-service-grid { grid-template-columns: 1fr !important; }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       OTHER SHORTCODES (Batch C/D will fill these)
       ============================================================ */

    /* ============================================================
       CHECKOUT (Batch C)
       ============================================================ */

    /**
     * Checkout page — renders form OR payment popup based on state
     */
    public static function sc_checkout( $atts ) {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

        // Stage 2: Payment page (after order created)
        if ( $action === 'pay' ) {
            return self::render_payment_page();
        }

        // Stage 1: Checkout form
        return self::render_checkout_form();
    }

    /**
     * Stage 1 — Checkout form
     */
    private static function render_checkout_form() {
        $service_id = isset( $_GET['service_id'] ) ? (int) $_GET['service_id'] : 0;
        $tier       = isset( $_GET['tier'] ) ? sanitize_key( $_GET['tier'] ) : 'basic';

        if ( ! in_array( $tier, array( 'basic', 'standard', 'premium' ), true ) ) {
            $tier = 'basic';
        }

        if ( ! $service_id ) {
            return self::render_message_box( 'No service selected', 'Please choose a service to purchase.', '/services/', 'Browse Services' );
        }

        $service = NMEP_Services::get( $service_id );
        if ( ! $service || $service->status !== NMEP_Services::STATUS_ACTIVE ) {
            return self::render_message_box( 'Service unavailable', 'This service is no longer available for purchase.', '/services/', 'Browse Services' );
        }

        $expert = NMEP_Compat::get_expert( $service->expert_id );
        $price = NMEP_Services::get_package_price( $service, $tier );
        $package_title = NMEP_Services::get_package_title( $service, $tier );
        $delivery_days = NMEP_Services::get_delivery_days( $service, $tier );
        $revisions = NMEP_Services::get_revisions( $service, $tier );
        $features = NMEP_Services::get_package_features( $service, $tier );

        if ( $price <= 0 ) {
            return self::render_message_box( 'Invalid pricing', 'This package is not configured correctly. Please contact support.' );
        }

        // Pre-fill from logged-in user if available
        $user = is_user_logged_in() ? wp_get_current_user() : null;
        $prefill_name  = $user ? $user->display_name : '';
        $prefill_email = $user ? $user->user_email : '';

        ob_start();
        echo self::render_status_messages();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 1100px;">
                <div style="display: grid; grid-template-columns: 1.4fr 1fr; gap: 32px; align-items: start;" class="nmep-checkout-grid">

                    <!-- LEFT: Form -->
                    <div>
                        <h1 style="margin-top: 0;">Complete Your Order</h1>
                        <p style="color: var(--nme-text-light, #6B7280);">Fill in your details to proceed to payment.</p>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card">
                            <input type="hidden" name="action" value="nmep_start_checkout">
                            <input type="hidden" name="service_id" value="<?php echo (int) $service->id; ?>">
                            <input type="hidden" name="tier" value="<?php echo esc_attr( $tier ); ?>">
                            <?php wp_nonce_field( 'nmep_checkout', 'nmep_checkout_nonce' ); ?>

                            <h3 style="margin-top: 0;">Your Information</h3>
                            <p>
                                <label>Full Name *</label>
                                <input type="text" name="buyer_name" required minlength="2" maxlength="120" value="<?php echo esc_attr( $prefill_name ); ?>">
                            </p>
                            <p>
                                <label>Email Address *</label>
                                <input type="email" name="buyer_email" required value="<?php echo esc_attr( $prefill_email ); ?>">
                                <small>Order updates and receipt will be sent here</small>
                            </p>
                            <p>
                                <label>Phone Number *</label>
                                <input type="tel" name="buyer_phone" required pattern="[0-9+\s\-]{10,15}" placeholder="+91 XXXXXXXXXX">
                                <small>For order updates via SMS/WhatsApp</small>
                            </p>

                            <?php if ( ! empty( $service->requirements ) ) : ?>
                                <h3 class="nme-mt-3">What the Expert Needs</h3>
                                <div style="background: var(--nme-bg-soft, #F9FAFB); padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                                    <p style="margin: 0; white-space: pre-line; color: var(--nme-text, #374151);"><?php echo esc_html( $service->requirements ); ?></p>
                                </div>
                                <p>
                                    <label>Your Requirements *</label>
                                    <textarea name="requirements" rows="6" required placeholder="Provide the details requested above..."></textarea>
                                    <small>Be as detailed as possible — this helps the expert deliver exactly what you need</small>
                                </p>
                            <?php else : ?>
                                <p>
                                    <label>Additional Notes (optional)</label>
                                    <textarea name="requirements" rows="4" placeholder="Any specific requirements or preferences..."></textarea>
                                </p>
                            <?php endif; ?>

                            <?php
                            // v1.5.5 — COUPON INPUT (only rendered if the coupons module is loaded)
                            if ( class_exists( 'NMEP_Coupons' ) ) : ?>
                                <div class="nmep-coupon-block" style="background: var(--nme-bg-soft, #F9FAFB); padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                                    <label style="margin-bottom: 8px; font-weight: 600;">Have a coupon code?</label>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <input type="text"
                                               id="nmep-coupon-input"
                                               name="coupon_code"
                                               maxlength="40"
                                               autocomplete="off"
                                               style="flex: 1; min-width: 180px; text-transform: uppercase; font-family: monospace; letter-spacing: 1px;"
                                               placeholder="Enter code">
                                        <button type="button" id="nmep-coupon-apply-btn" class="nme-btn"
                                                style="flex: 0 0 auto; padding: 8px 20px; min-height: 0;">Apply</button>
                                    </div>
                                    <div id="nmep-coupon-feedback" aria-live="polite" style="margin-top: 10px; font-size: 0.9rem; min-height: 0;"></div>
                                    <small style="color: var(--nme-text-light, #6B7280);">Discount is verified at payment — applying here just previews the new total.</small>
                                </div>
                            <?php endif; ?>

                            <p class="nme-mt-3">
                                <label style="display: flex; align-items: start; gap: 10px; font-weight: normal;">
                                    <input type="checkbox" required style="width: auto; margin-top: 4px;">
                                    <span style="font-size: 0.9rem;">I agree to the <a href="/terms-of-service/" target="_blank" style="color: var(--nme-emerald, #10B981);">Terms of Service</a> and <a href="/refund-policy/" target="_blank" style="color: var(--nme-emerald, #10B981);">Refund Policy</a>. I understand that my payment will be held in escrow until I approve the work.</span>
                                </label>
                            </p>

                            <p class="nme-mt-3 nme-text-center">
                                <button type="submit" id="nmep-checkout-submit" class="nme-btn nme-btn-gold" style="font-size: 1.1rem; padding: 16px 40px;">
                                    Continue to Payment — <span id="nmep-checkout-total"><?php echo nmep_format_inr( $price ); ?></span>
                                </button>
                            </p>

                            <p style="text-align: center; font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin-top: 16px;">
                                🔒 Secure payment via Razorpay. Your card details never touch our servers.
                            </p>
                        </form>
                    </div>

                    <!-- RIGHT: Order Summary -->
                    <div>
                        <div class="nme-card" style="position: sticky; top: 100px;">
                            <h3 style="margin-top: 0;">Order Summary</h3>

                            <?php if ( $service->cover_image ) : ?>
                                <div style="aspect-ratio: 16/9; background: var(--nme-bg-soft) url('<?php echo esc_url( $service->cover_image ); ?>') center/cover no-repeat; border-radius: 8px; margin-bottom: 16px;"></div>
                            <?php endif; ?>

                            <h4 style="font-size: 1rem; margin: 0 0 8px; color: var(--nme-forest, #0F2419);">
                                <?php echo esc_html( $service->title ); ?>
                            </h4>

                            <?php if ( $expert ) : ?>
                                <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin: 0 0 16px;">
                                    By <strong><?php echo esc_html( $expert->full_name ); ?></strong>
                                </p>
                            <?php endif; ?>

                            <div style="background: var(--nme-bg-soft, #F9FAFB); padding: 16px; border-radius: 8px; margin: 16px 0;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                    <strong style="color: var(--nme-forest, #0F2419);"><?php echo esc_html( $package_title ); ?></strong>
                                    <strong><?php echo nmep_format_inr( $price ); ?></strong>
                                </div>
                                <div style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280);">
                                    ⏱ <?php echo $delivery_days; ?> days delivery · 🔄 <?php echo $revisions; ?> revisions
                                </div>
                            </div>

                            <?php if ( ! empty( $features ) ) : ?>
                                <ul style="list-style: none; padding: 0; margin: 16px 0;">
                                    <?php foreach ( $features as $f ) : ?>
                                        <li style="padding: 6px 0; color: var(--nme-text, #374151); font-size: 0.9rem;">✓ <?php echo esc_html( $f ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <div style="border-top: 2px solid var(--nme-border, #E5E7EB); padding-top: 16px; margin-top: 16px;">
                                <div id="nmep-summary-discount-row" style="display: none; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                    <span style="color: var(--nme-emerald, #10B981); font-weight: 600;">Coupon (<span id="nmep-summary-coupon-code"></span>)</span>
                                    <span style="color: var(--nme-emerald, #10B981); font-weight: 600;">− <span id="nmep-summary-discount">₹0.00</span></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong style="font-size: 1.1rem;">Total</strong>
                                    <strong id="nmep-summary-total" style="font-size: 1.5rem; color: var(--nme-forest, #0F2419);"><?php echo nmep_format_inr( $price ); ?></strong>
                                </div>
                                <div id="nmep-summary-original-row" style="display: none; text-align: right; font-size: 0.85rem; color: var(--nme-text-light, #6B7280); text-decoration: line-through; margin-top: 4px;">
                                    <span id="nmep-summary-original"><?php echo nmep_format_inr( $price ); ?></span>
                                </div>
                            </div>

                            <div style="background: #FFFBEB; border-left: 3px solid #D4A843; padding: 12px; border-radius: 6px; margin-top: 16px;">
                                <p style="margin: 0; font-size: 0.85rem; color: #78350F;">
                                    🛡️ <strong>Escrow Protection:</strong> Payment is held until you approve the work
                                </p>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </section>

        <style>
            @media (max-width: 880px) {
                .nmep-checkout-grid { grid-template-columns: 1fr !important; }
            }
            #nmep-coupon-feedback.is-success { color: #065F46; background: #ECFDF5; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #10B981; }
            #nmep-coupon-feedback.is-error   { color: #991B1B; background: #FEF2F2; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #EF4444; }
            #nmep-coupon-feedback.is-loading { color: #6B7280; font-style: italic; }
            #nmep-coupon-input.is-valid      { border-color: #10B981 !important; background: #ECFDF5; }
            #nmep-coupon-input.is-invalid    { border-color: #EF4444 !important; background: #FEF2F2; }
        </style>

        <?php if ( class_exists( 'NMEP_Coupons' ) ) : ?>
        <script>
        (function(){
            var input       = document.getElementById('nmep-coupon-input');
            var btn         = document.getElementById('nmep-coupon-apply-btn');
            var feedback    = document.getElementById('nmep-coupon-feedback');
            var totalEl     = document.getElementById('nmep-checkout-total');
            var summaryTotal   = document.getElementById('nmep-summary-total');
            var summaryDiscRow = document.getElementById('nmep-summary-discount-row');
            var summaryDisc    = document.getElementById('nmep-summary-discount');
            var summaryCodeEl  = document.getElementById('nmep-summary-coupon-code');
            var summaryOrigRow = document.getElementById('nmep-summary-original-row');
            var submitBtn   = document.getElementById('nmep-checkout-submit');
            var emailInput  = document.querySelector('input[name="buyer_email"]');

            if ( !input || !btn || !feedback ) return;

            var ajaxUrl = (window.nmep_data && window.nmep_data.ajax_url) ? window.nmep_data.ajax_url : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce   = (window.nmep_data && window.nmep_data.nonce)    ? window.nmep_data.nonce    : '<?php echo esc_js( wp_create_nonce( 'nmep_ajax' ) ); ?>';
            var serviceId = <?php echo (int) $service->id; ?>;
            var tier      = <?php echo wp_json_encode( $tier ); ?>;
            var originalTotalDisplay = <?php echo wp_json_encode( nmep_format_inr( $price ) ); ?>;
            var inflight = null;

            function setFeedback(state, msg){
                feedback.className = state ? 'is-' + state : '';
                feedback.textContent = msg || '';
            }

            function resetSummary(){
                input.classList.remove('is-valid', 'is-invalid');
                if (summaryDiscRow) summaryDiscRow.style.display = 'none';
                if (summaryOrigRow) summaryOrigRow.style.display = 'none';
                if (summaryTotal)   summaryTotal.textContent   = originalTotalDisplay;
                if (totalEl)        totalEl.textContent        = originalTotalDisplay;
                submitBtn && submitBtn.removeAttribute('disabled');
            }

            function applyCoupon(){
                var code = (input.value || '').toUpperCase().trim();
                input.value = code;
                if (!code) {
                    setFeedback('', '');
                    resetSummary();
                    return;
                }

                // Cancel any previous request
                if (inflight && typeof inflight.abort === 'function') {
                    try { inflight.abort(); } catch(e){}
                }

                setFeedback('loading', 'Checking coupon...');
                submitBtn && submitBtn.setAttribute('disabled', 'disabled');

                var formData = new FormData();
                formData.append('action', 'nmep_validate_coupon');
                formData.append('nonce', nonce);
                formData.append('code', code);
                formData.append('service_id', String(serviceId));
                formData.append('tier', tier);
                if (emailInput && emailInput.value) formData.append('email', emailInput.value);

                var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                inflight = controller;

                var fetchOpts = { method: 'POST', body: formData, credentials: 'same-origin' };
                if (controller) fetchOpts.signal = controller.signal;

                fetch(ajaxUrl, fetchOpts)
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        submitBtn && submitBtn.removeAttribute('disabled');
                        if (res && res.success && res.data) {
                            input.classList.remove('is-invalid');
                            input.classList.add('is-valid');
                            setFeedback('success', res.data.message || 'Coupon applied.');
                            if (summaryTotal)      summaryTotal.textContent      = res.data.final_display;
                            if (totalEl)           totalEl.textContent           = res.data.final_display;
                            if (summaryDisc)       summaryDisc.textContent       = res.data.discount_display;
                            if (summaryCodeEl)     summaryCodeEl.textContent     = res.data.code || code;
                            if (summaryDiscRow)    summaryDiscRow.style.display  = 'flex';
                            if (summaryOrigRow) {
                                document.getElementById('nmep-summary-original').textContent = res.data.original_display;
                                summaryOrigRow.style.display = 'block';
                            }
                        } else {
                            input.classList.remove('is-valid');
                            input.classList.add('is-invalid');
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'This coupon is not valid.';
                            setFeedback('error', msg);
                            resetSummary();
                            input.classList.add('is-invalid');
                        }
                    })
                    .catch(function(err){
                        if (err && err.name === 'AbortError') return;
                        submitBtn && submitBtn.removeAttribute('disabled');
                        setFeedback('error', 'Could not check coupon. Please try again.');
                    });
            }

            btn.addEventListener('click', applyCoupon);
            input.addEventListener('keydown', function(e){
                if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
            });
            input.addEventListener('input', function(){
                // If user edits the code after applying, reset the summary until they re-apply
                if (input.classList.contains('is-valid') || input.classList.contains('is-invalid')) {
                    resetSummary();
                    setFeedback('', '');
                }
            });
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Stage 2 — Payment page (loads Razorpay popup)
     */
    private static function render_payment_page() {
        $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
        $token    = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        if ( ! $order_id || ! $token ) {
            return self::render_message_box( 'Invalid payment link', 'This payment link is missing or invalid.', '/services/', 'Browse Services' );
        }

        $order = NMEP_Orders::get( $order_id );
        if ( ! $order || $order->view_token !== $token ) {
            return self::render_message_box( 'Order not found', 'We could not find this order. Please try again.', '/services/', 'Browse Services' );
        }

        // If already paid, redirect to thank you
        if ( $order->payment_status === NMEP_Orders::PAYMENT_CAPTURED ) {
            wp_safe_redirect( add_query_arg( array( 'order_id' => $order->id, 'token' => $order->view_token ), nmep_get_page_url( 'order-thank-you' ) ) );
            exit;
        }

        if ( empty( $order->razorpay_order_id ) ) {
            return self::render_message_box( 'Payment not ready', 'This order is not ready for payment. Please contact support.', '/services/', 'Browse Services' );
        }

        $config = NMEP_Checkout::get_razorpay_popup_config( $order );
        if ( ! $config ) {
            return self::render_message_box( 'Configuration error', 'Payment gateway is not configured. Please contact support.' );
        }

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 720px;">
                <div class="nme-card nme-text-center" style="padding: 48px 32px;">
                    <h2 style="margin-top: 0;">Ready for Payment</h2>
                    <p style="color: var(--nme-text-light, #6B7280); font-size: 1.05rem;">
                        Order: <strong><?php echo esc_html( $order->order_number ); ?></strong><br>
                        Amount: <strong><?php echo nmep_format_inr( $order->gross_amount ); ?></strong>
                    </p>

                    <button id="nmep-pay-button" class="nme-btn nme-btn-gold" style="font-size: 1.1rem; padding: 16px 48px; margin: 24px 0;">
                        💳 Pay <?php echo nmep_format_inr( $order->gross_amount ); ?>
                    </button>

                    <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin: 16px 0 0;">
                        🔒 You will be securely redirected to Razorpay to complete payment.<br>
                        Test card: <code>4111 1111 1111 1111</code> · Any future date · Any CVV
                    </p>
                </div>
            </div>
        </section>

        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
        <script>
        (function(){
            var rzpConfig = <?php echo wp_json_encode( $config ); ?>;
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
            var ajaxAction = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonce = '<?php echo esc_js( wp_create_nonce( 'nmep_ajax' ) ); ?>';

            // Build Razorpay options
            var options = {
                key: rzpConfig.key,
                amount: rzpConfig.amount,
                currency: rzpConfig.currency,
                name: rzpConfig.name,
                description: rzpConfig.description,
                image: rzpConfig.image,
                order_id: rzpConfig.order_id,
                prefill: rzpConfig.prefill,
                notes: rzpConfig.notes,
                theme: rzpConfig.theme,
                handler: function(response) {
                    // Submit verification form
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = ajaxUrl;

                    var fields = {
                        'action': 'nmep_verify_payment',
                        'razorpay_payment_id': response.razorpay_payment_id,
                        'razorpay_order_id': response.razorpay_order_id,
                        'razorpay_signature': response.razorpay_signature,
                        'local_order_id': rzpConfig.local_order_id
                    };

                    for (var key in fields) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = fields[key];
                        form.appendChild(input);
                    }

                    document.body.appendChild(form);
                    form.submit();
                },
                modal: {
                    ondismiss: function() {
                        console.log('Razorpay popup dismissed by user');
                    }
                }
            };

            var rzp = new Razorpay(options);

            rzp.on('payment.failed', function(response) {
                // Log the failure server-side
                var formData = new FormData();
                formData.append('action', 'nmep_log_payment_failure');
                formData.append('nonce', nonce);
                formData.append('local_order_id', rzpConfig.local_order_id);
                formData.append('error_code', response.error.code || '');
                formData.append('error_description', response.error.description || '');

                fetch(ajaxAction, { method: 'POST', body: formData });

                alert('Payment failed: ' + (response.error.description || 'Unknown error') + '\n\nPlease try again.');
            });

            document.getElementById('nmep-pay-button').addEventListener('click', function(){
                rzp.open();
            });

            // Auto-open popup after 800ms (give user time to see the page)
            setTimeout(function(){ rzp.open(); }, 800);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       THANK YOU PAGE
       ============================================================ */

    public static function sc_thank_you( $atts ) {
        $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
        $token    = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        if ( ! $order_id || ! $token ) {
            return self::render_message_box( 'Invalid link', 'This order link is invalid.', '/services/', 'Browse Services' );
        }

        $order = NMEP_Orders::get( $order_id );
        if ( ! $order || $order->view_token !== $token ) {
            return self::render_message_box( 'Order not found', 'We could not find this order.', '/services/', 'Browse Services' );
        }

        $expert = NMEP_Compat::get_expert( $order->expert_id );
        $service = NMEP_Services::get( $order->service_id );
        $track_url = add_query_arg( array( 'order' => $order->order_number, 'token' => $order->view_token ), nmep_get_page_url( 'order-track' ) );

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 720px;">
                <div class="nme-card nme-text-center" style="padding: 48px 32px; border-top: 6px solid var(--nme-emerald, #10B981);">
                    <div style="font-size: 4rem; margin-bottom: 16px;">🎉</div>
                    <h1 style="margin: 0;">Order Confirmed!</h1>
                    <p style="font-size: 1.1rem; color: var(--nme-text-light, #6B7280); margin: 16px 0;">
                        Thank you, <strong><?php echo esc_html( $order->buyer_name ); ?></strong>!<br>
                        Your payment of <strong><?php echo nmep_format_inr( $order->gross_amount ); ?></strong> has been received.
                    </p>

                    <div style="background: var(--nme-bg-soft, #F9FAFB); padding: 24px; border-radius: 12px; margin: 24px 0; text-align: left;">
                        <table style="width: 100%; font-size: 0.95rem;">
                            <tr>
                                <td style="padding: 8px 0; color: var(--nme-text-light, #6B7280);">Order Number:</td>
                                <td style="padding: 8px 0; text-align: right;"><strong style="font-family: monospace;"><?php echo esc_html( $order->order_number ); ?></strong></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: var(--nme-text-light, #6B7280);">Service:</td>
                                <td style="padding: 8px 0; text-align: right;"><strong><?php echo esc_html( $order->service_title ); ?></strong></td>
                            </tr>
                            <tr>
                                <td style="padding: 8px 0; color: var(--nme-text-light, #6B7280);">Package:</td>
                                <td style="padding: 8px 0; text-align: right;"><?php echo esc_html( $order->package_title ); ?></td>
                            </tr>
                            <?php if ( $expert ) : ?>
                            <tr>
                                <td style="padding: 8px 0; color: var(--nme-text-light, #6B7280);">Expert:</td>
                                <td style="padding: 8px 0; text-align: right;"><?php echo esc_html( $expert->full_name ); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td style="padding: 8px 0; color: var(--nme-text-light, #6B7280);">Delivery in:</td>
                                <td style="padding: 8px 0; text-align: right;"><strong><?php echo (int) $order->delivery_days; ?> days</strong></td>
                            </tr>
                        </table>
                    </div>

                    <div style="background: #FFFBEB; border-left: 4px solid #D4A843; padding: 16px; border-radius: 8px; text-align: left; margin: 16px 0;">
                        <strong>What happens next?</strong>
                        <ol style="margin: 8px 0 0; padding-left: 20px; color: var(--nme-text, #374151);">
                            <li>The expert has been notified.</li>
                            <li>They will start work and deliver within <?php echo (int) $order->delivery_days; ?> days.</li>
                            <li>You will receive an email when work is ready.</li>
                            <li>Your payment is in <strong>secure escrow</strong> until you approve.</li>
                        </ol>
                    </div>

                    <p class="nme-mt-3">
                        <a href="<?php echo esc_url( $track_url ); ?>" class="nme-btn nme-btn-gold">📦 Track Your Order</a>
                    </p>

                    <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin-top: 24px;">
                        A confirmation email has been sent to <strong><?php echo esc_html( $order->buyer_email ); ?></strong>.<br>
                        Save your order number: <code><?php echo esc_html( $order->order_number ); ?></code>
                    </p>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       TRACK ORDER PAGE
       ============================================================ */

    public static function sc_track_order( $atts ) {
        self::prevent_page_cache();
        $order_number = isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : '';
        $token        = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 800px;">
                <h1 style="margin-top: 0;">Track Your Order</h1>

                <?php if ( ! $order_number || ! $token ) : ?>
                    <!-- Lookup form -->
                    <div class="nme-card">
                        <p style="color: var(--nme-text-light, #6B7280);">Enter your order number and tracking token (from your confirmation email).</p>
                        <form method="get">
                            <p>
                                <label>Order Number</label>
                                <input type="text" name="order" placeholder="NMEX..." required>
                            </p>
                            <p>
                                <label>Tracking Token</label>
                                <input type="text" name="token" placeholder="From your email" required>
                            </p>
                            <p><button type="submit" class="nme-btn nme-btn-gold">Track Order</button></p>
                        </form>
                    </div>
                <?php else :
                    $order = NMEP_Orders::get_by_number( $order_number );
                    if ( ! $order || $order->view_token !== $token ) :
                ?>
                    <div class="nme-card" style="border-left: 4px solid #EF4444;">
                        <h3>Order Not Found</h3>
                        <p>The order number or tracking token is invalid. Please check your email and try again.</p>
                        <p><a href="?" class="nme-btn">Try Again</a></p>
                    </div>
                <?php else :
                    echo self::render_order_tracking( $order );
                endif;
                endif; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /**
     * Render order tracking timeline
     */
    private static function render_order_tracking( $order ) {
        $expert = NMEP_Compat::get_expert( $order->expert_id );

        // Build timeline events
        $events = array();

        $events[] = array(
            'icon' => '📝', 'title' => 'Order Placed',
            'date' => $order->created_at,
            'completed' => true,
            'desc' => 'Your order was created'
        );

        if ( $order->paid_at ) {
            $events[] = array(
                'icon' => '💳', 'title' => 'Payment Received',
                'date' => $order->paid_at,
                'completed' => true,
                'desc' => nmep_format_inr( $order->gross_amount ) . ' captured · Held in escrow'
            );
        }

        if ( $order->delivered_at ) {
            $events[] = array(
                'icon' => '📦', 'title' => 'Work Delivered',
                'date' => $order->delivered_at,
                'completed' => true,
                'desc' => 'Expert delivered the work'
            );
        } elseif ( $order->paid_at ) {
            $events[] = array(
                'icon' => '⏳', 'title' => 'Expert Working',
                'date' => $order->delivery_due_at,
                'completed' => false,
                'desc' => 'Delivery due by ' . date( 'd M Y', strtotime( $order->delivery_due_at ) )
            );
        }

        if ( $order->approved_at ) {
            $events[] = array(
                'icon' => '✅', 'title' => 'Order Completed',
                'date' => $order->approved_at,
                'completed' => true,
                'desc' => 'You approved the work · Funds released to expert'
            );
        }

        ob_start();
        ?>
        <div class="nme-card">
            <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h2 style="margin: 0;"><?php echo esc_html( $order->service_title ); ?></h2>
                    <p style="margin: 4px 0; color: var(--nme-text-light, #6B7280);">
                        <?php echo esc_html( $order->package_title ); ?>
                        <?php if ( $expert ) : ?> · By <strong><?php echo esc_html( $expert->full_name ); ?></strong><?php endif; ?>
                    </p>
                    <p style="margin: 4px 0; font-family: monospace; color: var(--nme-text-light, #6B7280);">
                        <?php echo esc_html( $order->order_number ); ?>
                    </p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.6rem; font-weight: 700; color: var(--nme-forest, #0F2419);">
                        <?php echo nmep_format_inr( $order->gross_amount ); ?>
                    </div>
                    <?php
                    $status_colors = array(
                        'paid' => '#10B981', 'in_progress' => '#3B82F6', 'delivered' => '#F59E0B',
                        'completed' => '#10B981', 'cancelled' => '#EF4444', 'refunded' => '#6B7280',
                    );
                    $color = $status_colors[ $order->status ] ?? '#6B7280';
                    ?>
                    <span style="display: inline-block; padding: 4px 12px; background: <?php echo esc_attr( $color ); ?>; color: #fff; border-radius: 50px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 8px;">
                        <?php echo esc_html( str_replace( '_', ' ', $order->status ) ); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="nme-card nme-mt-3">
            <h3 style="margin-top: 0;">Timeline</h3>
            <div style="position: relative;">
                <?php foreach ( $events as $i => $event ) : ?>
                    <div style="display: flex; gap: 16px; padding: 12px 0; border-bottom: <?php echo $i < count($events) - 1 ? '1px solid var(--nme-border, #E5E7EB)' : 'none'; ?>;">
                        <div style="width: 48px; height: 48px; flex-shrink: 0; background: <?php echo $event['completed'] ? 'var(--nme-emerald, #10B981)' : '#E5E7EB'; ?>; color: <?php echo $event['completed'] ? '#fff' : '#9CA3AF'; ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                            <?php echo $event['icon']; ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: var(--nme-forest, #0F2419);"><?php echo esc_html( $event['title'] ); ?></div>
                            <div style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280);"><?php echo esc_html( $event['desc'] ); ?></div>
                            <div style="font-size: 0.75rem; color: var(--nme-text-light, #6B7280); margin-top: 4px;">
                                <?php echo esc_html( date( 'd M Y · H:i', strtotime( $event['date'] ) ) ); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ( ! empty( $order->buyer_requirements ) ) : ?>
            <div class="nme-card nme-mt-3">
                <h3 style="margin-top: 0;">Your Requirements</h3>
                <p style="white-space: pre-line; color: var(--nme-text, #374151);"><?php echo esc_html( $order->buyer_requirements ); ?></p>
            </div>
        <?php endif; ?>

        <?php
        // BUYER ACTIONS (Batch D) — show only if order is delivered and has view token
        if ( $order->status === NMEP_Orders::STATUS_DELIVERED ) :
            // Get delivery message from order_messages
            global $wpdb;
            $delivery_msg = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . NMEP_Database::table( 'order_messages' ) . "
                 WHERE order_id = %d AND is_delivery = 1
                 ORDER BY created_at DESC LIMIT 1",
                $order->id
            ) );
        ?>
            <?php if ( $delivery_msg ) : ?>
                <div class="nme-card nme-mt-3" style="border-left: 4px solid var(--nme-emerald, #10B981);">
                    <h3 style="margin-top: 0; color: var(--nme-emerald, #10B981);">📦 Delivery Note</h3>
                    <p style="white-space: pre-line;"><?php echo esc_html( $delivery_msg->message ); ?></p>
                    <?php
                    $attachments = ! empty( $delivery_msg->attachment_urls ) ? json_decode( $delivery_msg->attachment_urls, true ) : array();
                    if ( ! empty( $attachments ) && is_array( $attachments ) ) :
                        foreach ( $attachments as $url ) :
                    ?>
                        <p>📎 <a href="<?php echo esc_url( $url ); ?>" target="_blank" style="color: var(--nme-emerald, #10B981);">View attachment</a></p>
                    <?php
                        endforeach;
                    endif;
                    ?>
                    <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin-bottom: 0;">
                        Delivered <?php echo esc_html( human_time_diff( strtotime( $delivery_msg->created_at ), current_time( 'timestamp' ) ) ); ?> ago by <?php echo esc_html( $delivery_msg->sender_name ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="nme-grid nme-grid-2 nme-mt-3">
                <!-- Approve & Release -->
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card" style="border-top: 4px solid var(--nme-emerald, #10B981);">
                    <input type="hidden" name="action" value="nmep_approve_order">
                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                    <input type="hidden" name="token" value="<?php echo esc_attr( $order->view_token ); ?>">
                    <?php wp_nonce_field( 'nmep_approve_' . $order->id ); ?>
                    <h3 style="margin-top: 0;">✅ Happy with the work?</h3>
                    <p>Approve and release the payment to the expert.</p>
                    <button type="submit" class="nme-btn nme-btn-gold" onclick="return confirm('Approve this order and release payment to the expert? This action is final.');">
                        Approve & Release Payment
                    </button>
                </form>

                <!-- Request Revision -->
                <?php if ( $order->revisions_used < $order->revisions_allowed ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nme-card" style="border-top: 4px solid #F59E0B;">
                        <input type="hidden" name="action" value="nmep_request_revision">
                        <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                        <input type="hidden" name="token" value="<?php echo esc_attr( $order->view_token ); ?>">
                        <?php wp_nonce_field( 'nmep_revision_' . $order->id ); ?>
                        <h3 style="margin-top: 0;">🔄 Need Changes?</h3>
                        <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280);">
                            Revisions left: <strong><?php echo (int) ( $order->revisions_allowed - $order->revisions_used ); ?> of <?php echo (int) $order->revisions_allowed; ?></strong>
                        </p>
                        <p>
                            <textarea name="revision_message" rows="3" required minlength="10" placeholder="Describe what needs to be changed (at least 10 characters)..." style="width: 100%; padding: 8px; border: 1px solid var(--nme-border, #E5E7EB); border-radius: 6px;"></textarea>
                        </p>
                        <button type="submit" class="nme-btn">Request Revision</button>
                    </form>
                <?php else : ?>
                    <div class="nme-card" style="border-top: 4px solid #6B7280;">
                        <h3 style="margin-top: 0;">No Revisions Left</h3>
                        <p>You have used all <?php echo (int) $order->revisions_allowed; ?> revisions. Please approve or open a dispute.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Dispute (collapsed by default) -->
            <details class="nme-card nme-mt-3" style="border-left: 4px solid #EF4444;">
                <summary style="cursor: pointer; color: #EF4444; font-weight: 600;">🚨 Need to open a dispute?</summary>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="nmep_open_dispute">
                    <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                    <input type="hidden" name="token" value="<?php echo esc_attr( $order->view_token ); ?>">
                    <input type="hidden" name="role" value="buyer">
                    <?php wp_nonce_field( 'nmep_open_dispute' ); ?>
                    <p>
                        <label>Reason</label>
                        <select name="reason" required>
                            <option value="">— Select reason —</option>
                            <option>Work does not match description</option>
                            <option>Quality is unacceptable</option>
                            <option>Expert is unresponsive</option>
                            <option>Wrong file/format delivered</option>
                            <option>Other</option>
                        </select>
                    </p>
                    <p>
                        <label>Detailed Description (30+ chars)</label>
                        <textarea name="description" rows="4" minlength="30" required placeholder="Explain the issue in detail..."></textarea>
                    </p>
                    <p style="background: #FEF2F2; padding: 12px; border-radius: 6px; font-size: 0.85rem; color: #991B1B; margin-bottom: 12px;">
                        ⚠️ Disputes are reviewed by our admin team within 24 hours. Misuse may result in account suspension.
                    </p>
                    <button type="submit" class="nme-btn" style="background: #EF4444;">Open Dispute</button>
                </form>
            </details>
        <?php endif; ?>

        <?php
        // REVIEW FORM (Batch E) — show if completed and not yet reviewed
        if ( in_array( $order->status, array( NMEP_Orders::STATUS_COMPLETED, NMEP_Orders::STATUS_AUTO_RELEASED ), true ) ) :
            $existing_review = NMEP_Reviews::get_for_order( $order->id );

            if ( $existing_review ) :
        ?>
                <div class="nme-card nme-mt-3" style="border-left: 4px solid var(--nme-gold, #D4A843); background: #FFFBEB;">
                    <h3 style="margin-top: 0;">⭐ Your Review</h3>
                    <div style="margin: 10px 0;"><?php echo NMEP_Reviews::render_stars( $existing_review->rating, 24 ); ?> <strong><?php echo (int) $existing_review->rating; ?>/5</strong></div>
                    <p style="font-style: italic;">"<?php echo esc_html( $existing_review->comment ); ?>"</p>
                    <small style="color: var(--nme-text-light, #6B7280);">Submitted <?php echo esc_html( human_time_diff( strtotime( $existing_review->created_at ), current_time( 'timestamp' ) ) ); ?> ago</small>
                </div>
        <?php
            else :
        ?>
                <div class="nme-card nme-mt-3" style="border-left: 4px solid var(--nme-gold, #D4A843);">
                    <h3 style="margin-top: 0;">⭐ Leave a Review</h3>
                    <p style="color: var(--nme-text-light, #6B7280);">Help others and the expert by sharing your experience.</p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="nmep_submit_review">
                        <input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
                        <input type="hidden" name="token" value="<?php echo esc_attr( $order->view_token ); ?>">
                        <?php wp_nonce_field( 'nmep_review_' . $order->id ); ?>

                        <p>
                            <label>Your Rating *</label>
                            <div class="nmep-star-rating" style="font-size: 2rem; color: #E5E7EB; user-select: none;">
                                <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                                    <label style="cursor: pointer; display: inline-block; padding: 0 4px;">
                                        <input type="radio" name="rating" value="<?php echo $i; ?>" required style="display: none;">
                                        <span class="nmep-star" data-value="<?php echo $i; ?>">★</span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <small>Click stars to rate</small>
                        </p>

                        <p>
                            <label>Your Review * (10-2000 characters)</label>
                            <textarea name="comment" rows="5" minlength="10" maxlength="2000" required placeholder="What was your experience like? Was the work delivered on time? Was the expert responsive? Would you order again?"></textarea>
                        </p>

                        <p>
                            <label style="display: flex; align-items: center; gap: 10px; font-weight: normal;">
                                <input type="checkbox" name="would_recommend" value="1" checked style="width: auto;">
                                <span>Yes, I would recommend this expert to others</span>
                            </label>
                        </p>

                        <p><button type="submit" class="nme-btn nme-btn-gold">Submit Review</button></p>
                    </form>
                </div>

                <script>
                (function(){
                    var stars = document.querySelectorAll('.nmep-star');
                    var radios = document.querySelectorAll('input[name="rating"]');
                    function paint(upTo){
                        stars.forEach(function(s){
                            s.style.color = parseInt(s.dataset.value) <= upTo ? '#F59E0B' : '#E5E7EB';
                        });
                    }
                    stars.forEach(function(star){
                        star.addEventListener('mouseenter', function(){ paint(parseInt(star.dataset.value)); });
                        star.addEventListener('click', function(){
                            var v = parseInt(star.dataset.value);
                            radios.forEach(function(r){ if (parseInt(r.value) === v) r.checked = true; });
                            paint(v);
                        });
                    });
                    document.querySelector('.nmep-star-rating').addEventListener('mouseleave', function(){
                        var checked = document.querySelector('input[name="rating"]:checked');
                        paint(checked ? parseInt(checked.value) : 0);
                    });
                })();
                </script>
        <?php
            endif;
        endif;

        // MESSAGES THREAD (Batch E) — always show for paid orders
        if ( in_array( $order->status, array(
            NMEP_Orders::STATUS_PAID, NMEP_Orders::STATUS_IN_PROGRESS, NMEP_Orders::STATUS_DELIVERED,
            NMEP_Orders::STATUS_REVISION, NMEP_Orders::STATUS_DISPUTED,
            NMEP_Orders::STATUS_COMPLETED, NMEP_Orders::STATUS_AUTO_RELEASED,
        ), true ) ) :
            echo NMEP_Messages::render_thread( $order, 'buyer', admin_url( 'admin-post.php' ) );
        endif;
        ?>

        <div class="nme-card nme-mt-3" style="background: #FFFBEB; border-left: 4px solid #D4A843;">
            <p style="margin: 0; color: #78350F;">
                💬 <strong>Need help with this order?</strong> Email us at <a href="mailto:<?php echo esc_attr( NMEP_Settings::get( 'support_email' ) ); ?>"><?php echo esc_html( NMEP_Settings::get( 'support_email' ) ); ?></a> with your order number.
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       BUYER DASHBOARD — /my-orders/
       ============================================================ */

    public static function sc_my_orders() {
        self::prevent_page_cache();
        // Two access modes: logged-in user OR email lookup
        if ( is_user_logged_in() ) {
            return self::render_my_orders_logged_in();
        }
        return self::render_my_orders_lookup();
    }

    private static function render_my_orders_logged_in() {
        $user = wp_get_current_user();
        $orders = NMEP_Orders::get_for_buyer( $user->ID, 100 );
        // Also pull by email in case some orders were placed as guest
        $email_orders = NMEP_Orders::get_for_buyer( $user->user_email, 100 );

        // Merge unique
        $seen = array();
        $all = array();
        foreach ( array_merge( $orders, $email_orders ) as $o ) {
            if ( ! isset( $seen[ $o->id ] ) ) {
                $all[] = $o;
                $seen[ $o->id ] = true;
            }
        }

        ob_start();
        echo self::render_status_messages();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 1100px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
                    <div>
                        <h1 style="margin: 0;">My Orders</h1>
                        <p style="margin: 4px 0 0; color: var(--nme-text-light, #6B7280);">Welcome back, <?php echo esc_html( $user->display_name ); ?></p>
                    </div>
                    <div>
                        <a href="/services/" class="nme-btn nme-btn-gold">+ Browse Services</a>
                    </div>
                </div>

                <?php echo self::render_orders_list( $all, true ); ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function render_my_orders_lookup() {
        $email = isset( $_GET['email'] ) ? sanitize_email( $_GET['email'] ) : '';
        $orders = $email ? NMEP_Orders::get_for_buyer( $email, 100 ) : array();

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 900px;">
                <h1>My Orders</h1>

                <?php if ( ! $email ) : ?>
                    <div class="nme-card">
                        <p>Enter the email address you used to place orders:</p>
                        <form method="get">
                            <p>
                                <input type="email" name="email" required placeholder="your@email.com" style="width: 100%;">
                            </p>
                            <p><button type="submit" class="nme-btn nme-btn-gold">Find My Orders</button></p>
                        </form>
                        <p style="font-size: 0.85rem; color: var(--nme-text-light, #6B7280); margin-top: 16px;">
                            💡 Tip: For full account features and saved orders, <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color: var(--nme-emerald, #10B981);">log in</a> or <a href="<?php echo esc_url( wp_registration_url() ); ?>" style="color: var(--nme-emerald, #10B981);">create an account</a>.
                        </p>
                    </div>
                <?php else : ?>
                    <p style="color: var(--nme-text-light, #6B7280);">Showing orders for: <strong><?php echo esc_html( $email ); ?></strong> · <a href="?">Change email</a></p>
                    <?php echo self::render_orders_list( $orders, false ); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function render_orders_list( $orders, $is_logged_in ) {
        if ( empty( $orders ) ) {
            return '<div class="nme-card nme-text-center" style="padding:60px 20px;">
                <div style="font-size:4rem;">📦</div>
                <h3>No orders yet</h3>
                <p>Browse services to find an expert who can help you.</p>
                <p><a href="/services/" class="nme-btn nme-btn-gold">Browse Services</a></p>
            </div>';
        }

        ob_start();
        ?>
        <div class="nme-card" style="padding: 0;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: var(--nme-bg-soft, #F9FAFB);">
                    <tr style="border-bottom: 2px solid var(--nme-border, #E5E7EB);">
                        <th style="text-align: left; padding: 14px 16px;">Order</th>
                        <th style="text-align: left; padding: 14px 16px;">Service</th>
                        <th style="text-align: right; padding: 14px 16px;">Amount</th>
                        <th style="text-align: center; padding: 14px 16px;">Status</th>
                        <th style="text-align: right; padding: 14px 16px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $orders as $o ) :
                        $track_url = add_query_arg( array(
                            'order' => $o->order_number,
                            'token' => $o->view_token,
                        ), nmep_get_page_url( 'order-track' ) );
                    ?>
                        <tr style="border-bottom: 1px solid var(--nme-border, #E5E7EB);">
                            <td style="padding: 14px 16px;">
                                <code style="font-size: 0.85rem;"><?php echo esc_html( $o->order_number ); ?></code><br>
                                <small style="color: var(--nme-text-light, #6B7280);"><?php echo esc_html( date( 'd M Y', strtotime( $o->created_at ) ) ); ?></small>
                            </td>
                            <td style="padding: 14px 16px;">
                                <strong><?php echo esc_html( $o->service_title ); ?></strong><br>
                                <small style="color: var(--nme-text-light, #6B7280);"><?php echo esc_html( $o->package_title ); ?></small>
                            </td>
                            <td style="padding: 14px 16px; text-align: right;">
                                <strong><?php echo nmep_format_inr( $o->gross_amount ); ?></strong>
                            </td>
                            <td style="padding: 14px 16px; text-align: center;">
                                <?php
                                $status_colors = array(
                                    'paid' => '#10B981', 'in_progress' => '#3B82F6', 'delivered' => '#F59E0B',
                                    'completed' => '#10B981', 'auto_released' => '#10B981',
                                    'cancelled' => '#EF4444', 'refunded' => '#6B7280', 'disputed' => '#EF4444',
                                    'revision' => '#F59E0B', 'initiated' => '#9CA3AF',
                                );
                                $color = $status_colors[ $o->status ] ?? '#6B7280';
                                ?>
                                <span style="display: inline-block; padding: 4px 10px; background: <?php echo esc_attr( $color ); ?>; color: #fff; border-radius: 50px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
                                    <?php echo esc_html( str_replace( '_', ' ', $o->status ) ); ?>
                                </span>
                            </td>
                            <td style="padding: 14px 16px; text-align: right;">
                                <a href="<?php echo esc_url( $track_url ); ?>" class="nme-btn" style="padding: 6px 14px; font-size: 0.85rem; min-height: 0; min-width: 0;">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       UI HELPERS
       ============================================================ */

    /**
     * Tells LiteSpeed Cache, WP Rocket, WP Super Cache, W3 Total Cache etc.
     * not to cache the current response, and emits standard HTTP no-cache
     * headers so browsers and CDNs in front of WP also bypass their caches.
     *
     * Required on any shortcode whose HTML depends on per-user / per-order
     * state — otherwise the buyer's /order-track/ keeps showing the PAID
     * snapshot even after the expert marks delivered, and the approve /
     * revision / dispute buttons never appear. We discovered this the hard
     * way on Hostinger where LiteSpeed Cache is enabled by default.
     */
    private static function prevent_page_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! headers_sent() ) {
            nocache_headers();
        }
    }

    private static function render_status_messages() {
        if ( ! isset( $_GET['nmep_status'] ) ) return '';
        $status = sanitize_key( $_GET['nmep_status'] );
        $msg = isset( $_GET['nmep_msg'] ) ? sanitize_text_field( urldecode( $_GET['nmep_msg'] ) ) : '';
        if ( ! $msg ) return '';

        if ( $status === 'success' ) {
            return '<div class="nme-card" style="border-left: 4px solid var(--nme-emerald); background: #ECFDF5; max-width: 1200px; margin: 0 auto 20px;"><p style="color: #065F46; margin: 0;">✅ ' . esc_html( $msg ) . '</p></div>';
        }
        if ( $status === 'error' ) {
            return '<div class="nme-card" style="border-left: 4px solid #EF4444; background: #FEF2F2; max-width: 1200px; margin: 0 auto 20px;"><p style="color: #991B1B; margin: 0;">❌ ' . esc_html( $msg ) . '</p></div>';
        }
        return '';
    }

    private static function render_login_required( $message ) {
        return self::render_message_box( 'Login Required', $message, wp_login_url( get_permalink() ), 'Log In' );
    }

    private static function render_message_box( $title, $message, $cta_url = '', $cta_text = '' ) {
        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 720px;">
                <div class="nme-card nme-text-center" style="padding: 48px 32px;">
                    <h2><?php echo esc_html( $title ); ?></h2>
                    <p style="font-size: 1.05rem; color: var(--nme-text-light);"><?php echo wp_kses_post( $message ); ?></p>
                    <?php if ( $cta_url && $cta_text ) : ?>
                        <p class="nme-mt-3"><a href="<?php echo esc_url( $cta_url ); ?>" class="nme-btn nme-btn-gold"><?php echo esc_html( $cta_text ); ?></a></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function render_coming_soon( $title, $message ) {
        return self::render_message_box( $title, $message );
    }
}