<?php
/**
 * NME_Dashboard
 *
 * Expert-facing dashboard page. Registers the [nme_expert_dashboard]
 * shortcode and creates the /expert-dashboard/ page on activation.
 *
 * Panels:
 *   - Header: name, tier, rating, orders, earnings
 *   - KYC status banner (hidden once verified)
 *   - Stats row (active orders, completed, pending earnings, rating)
 *   - Quick actions (edit profile, new service, view public profile, KYC)
 *   - Recent orders (last 8) pulled from payments
 *   - Embedded [nmep_expert_profile_editor] from nme-payments for
 *     availability / portfolio / languages / certifications
 *
 * @package NagalandMeExperts
 * @since   0.3.0
 *
 * v0.4.0 — adds change-request surfacing:
 *   - Pending-request banner (if expert has an open profile-photo or bank
 *     change request)
 *   - Two new quick-action buttons: "Change profile picture" and
 *     "Edit bank account"
 *   - "Recent account changes" log panel (last 5 approved/rejected items)
 *   These are only rendered if the NME_Change_Requests class is loaded, so
 *   the file remains safe if the companion class is missing.
 *
 * v0.5.1 — adds registered-address self-service:
 *   - "Update address" quick-action button linking to [nme_edit_address]
 *   - Address-completeness alert when street / village / 6-digit PIN are
 *     missing — this blocks Razorpay Route onboarding, so we surface it
 *     prominently next to the KYC banner rather than letting experts
 *     discover it at payout time.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NME_Dashboard {

    public static function init() {
        add_shortcode( 'nme_expert_dashboard', array( __CLASS__, 'render' ) );
    }

    public static function render( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return self::card( 'Please log in to view your expert dashboard.', '<a class="nme-btn nme-btn-gold" href="' . esc_url( wp_login_url( self::page_url() ) ) . '">Log in</a>' );
        }

        $user_id = get_current_user_id();
        $expert  = NME_Experts::get_by_user( $user_id );
        if ( ! $expert ) {
            return self::card(
                'No expert profile found for your account.',
                '<a class="nme-btn nme-btn-gold" href="' . esc_url( home_url( '/expert-register/' ) ) . '">Apply to be an expert</a>'
            );
        }

        if ( $expert->status === NME_Experts::STATUS_PENDING ) {
            $was_resubmit = isset( $_GET['nme_status'] ) && $_GET['nme_status'] === 'application_resubmitted';
            $resubmit_flash = $was_resubmit
                ? '<div style="background:#ECFDF5;border-left:4px solid #059669;padding:12px 14px;color:#065F46;margin-bottom:16px;border-radius:6px;"><strong>✅ Application resubmitted.</strong> Thanks — your updated application is back with our review team.</div>'
                : '';

            $resubmitted_at = isset( $expert->resubmitted_at ) ? $expert->resubmitted_at : null;
            $is_resubmitted_row = ! empty( $resubmitted_at ) && $resubmitted_at !== '0000-00-00 00:00:00';
            $heading = $is_resubmitted_row ? 'Application under re-review' : 'Application under review';
            $body_line = $is_resubmitted_row
                ? 'Thanks for resubmitting! Our team reviews every application personally — you\'ll hear from us within 24 hours (sometimes up to 2 days) via email and WhatsApp.'
                : 'Thanks for applying! Our team reviews every application personally — you\'ll hear from us within 24 hours (sometimes up to 2 days) via email and WhatsApp.';

            return self::card(
                $resubmit_flash .
                '<h2 style="margin-top:0;">' . esc_html( $heading ) . '</h2>' .
                '<p>' . esc_html( $body_line ) . '</p>',
                ''
            );
        }
        if ( $expert->status === NME_Experts::STATUS_REJECTED ) {
            $resubmitted_at = isset( $expert->resubmitted_at ) ? $expert->resubmitted_at : null;
            $was_resubmitted = ! empty( $resubmitted_at ) && $resubmitted_at !== '0000-00-00 00:00:00';
            $resubmit_note   = $was_resubmitted
                ? '<p style="color:#6B7280;font-size:0.9rem;margin:4px 0 0;">You previously resubmitted on <strong>' . esc_html( mysql2date( 'F j, Y \a\t g:i a', $resubmitted_at ) ) . '</strong> but the application was not approved.</p>'
                : '';

            return self::card(
                '<h2 style="margin-top:0;">Your application was not approved</h2>' .
                ( $expert->rejection_reason ? '<p><strong>Reason:</strong> ' . esc_html( $expert->rejection_reason ) . '</p>' : '' ) .
                '<p>You can edit your application and resubmit once you have addressed the feedback above. Your existing details will be pre-filled so you only need to change what needs fixing.</p>' .
                $resubmit_note,
                '<a class="nme-btn nme-btn-gold" href="' . esc_url( home_url( '/edit-application/' ) ) . '">Edit &amp; Resubmit Application</a>'
            );
        }
        if ( $expert->status === NME_Experts::STATUS_SUSPENDED ) {
            return self::card(
                '<h2 style="margin-top:0;">Account suspended</h2>' .
                '<p>Your expert account is currently suspended. Please contact support on WhatsApp to resolve.</p>',
                '<a class="nme-btn" href="https://wa.me/916383359495" rel="noopener">Contact support</a>'
            );
        }

        // APPROVED experts get the full dashboard.
        $has_bridge = class_exists( 'NME_Payments_Bridge' );
        $metrics    = $has_bridge ? NME_Payments_Bridge::get_metrics( (int) $expert->id )         : null;
        $summary    = $has_bridge ? NME_Payments_Bridge::get_orders_summary( (int) $expert->id )  : array( 'active' => 0, 'completed' => 0, 'cancelled' => 0, 'in_dispute' => 0, 'total' => 0, 'earned' => 0.0, 'pending_earnings' => 0.0 );
        $orders     = $has_bridge ? NME_Payments_Bridge::get_orders_for_expert( (int) $expert->id, 8 ) : array();
        $profile_url = NME_Frontend::get_profile_url( $expert );
        $kyc_banner  = self::kyc_banner_for( $expert );

        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width:1040px;">

                <!-- Header strip -->
                <div class="nme-card" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
                    <?php if ( $expert->profile_photo ) : ?>
                        <img src="<?php echo esc_url( $expert->profile_photo ); ?>" alt="" style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid var(--nme-gold);">
                    <?php endif; ?>
                    <div style="flex:1;min-width:200px;">
                        <div style="color:#6B7280;font-size:0.9rem;">Welcome back,</div>
                        <h1 style="margin:2px 0 6px;"><?php echo esc_html( $expert->full_name ); ?></h1>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:600;color:#fff;background:<?php echo esc_attr( $has_bridge ? NME_Payments_Bridge::tier_color( $expert->tier ) : '#0A7558' ); ?>;">
                                <?php echo esc_html( $has_bridge ? NME_Payments_Bridge::tier_label( $expert->tier ) : ucfirst( $expert->tier ?: 'new' ) ); ?>
                            </span>
                            <?php if ( $expert->is_founding_expert ) : ?>
                                <span style="background:#FEF3C7;color:#92400E;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:600;">Founding Expert</span>
                            <?php endif; ?>
                            <?php if ( $metrics && ! empty( $metrics->availability_mode ) ) : ?>
                                <span style="background:#F3F4F6;color:#374151;padding:3px 10px;border-radius:999px;font-size:0.78rem;">
                                    <?php echo esc_html( ucfirst( $metrics->availability_mode ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="nme-btn" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener">View public profile</a>
                    </div>
                </div>

                <?php if ( $kyc_banner ) echo $kyc_banner; ?>

                <?php
                // v0.5.1 — registered address completeness (required for Razorpay Route).
                // We show this even after KYC is verified if the columns are blank —
                // otherwise payouts silently fail at account-creation time.
                $address_alert = self::address_alert_for( $expert );
                if ( $address_alert ) echo $address_alert;
                ?>

                <?php
                // v0.4.0 — pending change-request banner (profile photo / bank account).
                if ( class_exists( 'NME_Change_Requests' ) ) {
                    $pending_banner = NME_Change_Requests::get_pending_banner_html( (int) $expert->id );
                    if ( $pending_banner ) {
                        echo $pending_banner; // html output, escaped inside the method
                    }
                }
                ?>

                <!-- Stats row -->
                <div class="nme-card nme-mt-3">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;text-align:center;">
                        <?php echo self::stat( 'Active orders',    (int) $summary['active'] ); ?>
                        <?php echo self::stat( 'Completed',        (int) $summary['completed'] ); ?>
                        <?php echo self::stat( 'Pending earnings', '₹' . number_format( (float) $summary['pending_earnings'] ) ); ?>
                        <?php echo self::stat( 'Total earned',     '₹' . number_format( (float) $summary['earned'] ) ); ?>
                        <?php echo self::stat( 'Rating',           number_format( (float) $expert->avg_rating, 1 ) ); ?>
                        <?php if ( $metrics && $metrics->avg_response_seconds ) : ?>
                            <?php echo self::stat( 'Response', NME_Payments_Bridge::humanize_response_time( (int) $metrics->avg_response_seconds ) ); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick actions -->
                <div class="nme-card nme-mt-3">
                    <h2 style="margin-top:0;">Quick actions</h2>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <?php
                        // Messages — surfaces buyer pre-purchase messages that
                        // otherwise only arrive by email. Without this link the
                        // expert has no in-dashboard path to /expert-inbox/.
                        if ( class_exists( 'NMEP_Inbox' ) ) :
                            $unread    = (int) NMEP_Inbox::unread_count_for_expert( (int) $expert->id );
                            $inbox_url = NMEP_Inbox::expert_inbox_url();
                            ?>
                            <a class="nme-btn<?php echo $unread > 0 ? ' nme-btn-gold' : ''; ?>" href="<?php echo esc_url( $inbox_url ); ?>">
                                ✉️ Messages<?php if ( $unread > 0 ) : ?><span style="background:#EF4444;color:#fff;border-radius:999px;padding:1px 8px;font-size:0.75rem;font-weight:700;margin-left:6px;"><?php echo (int) $unread; ?></span><?php endif; ?>
                            </a>
                        <?php endif; ?>
                        <a class="nme-btn nme-btn-gold" href="<?php echo esc_url( home_url( '/create-service/' ) ); ?>">+ Create new service</a>
                        <a class="nme-btn" href="<?php echo esc_url( home_url( '/kyc/' ) ); ?>">KYC &amp; payout details</a>
                        <a class="nme-btn" href="<?php echo esc_url( home_url( '/edit-profile/' ) ); ?>">✏️ Edit About &amp; bio</a>
                        <a class="nme-btn" href="#profile-sections">Edit portfolio &amp; availability</a>
                        <?php if ( class_exists( 'NME_Change_Requests' ) ) : ?>
                            <a class="nme-btn" href="<?php echo esc_url( home_url( '/change-profile-photo/' ) ); ?>">Change profile picture</a>
                            <a class="nme-btn" href="<?php echo esc_url( home_url( '/edit-bank-account/' ) ); ?>">Edit bank account</a>
                            <a class="nme-btn" href="<?php echo esc_url( home_url( '/edit-address/' ) ); ?>">Update address</a>
                        <?php endif; ?>
                        <a class="nme-btn" href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener">View public profile</a>
                    </div>
                </div>

                <!-- Recent orders -->
                <?php if ( ! empty( $orders ) ) : ?>
                    <div class="nme-card nme-mt-3">
                        <h2 style="margin-top:0;">Recent orders</h2>
                        <table style="width:100%;border-collapse:collapse;font-size:0.95rem;">
                            <thead>
                                <tr style="background:#F9FAFB;">
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #E5E7EB;">Order</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #E5E7EB;">Status</th>
                                    <th style="text-align:right;padding:10px;border-bottom:1px solid #E5E7EB;">You earn</th>
                                    <th style="text-align:left;padding:10px;border-bottom:1px solid #E5E7EB;">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $orders as $o ) : ?>
                                    <tr>
                                        <td style="padding:10px;border-bottom:1px solid #F3F4F6;">
                                            <strong>#<?php echo (int) $o->id; ?></strong>
                                            <?php if ( ! empty( $o->service_title ) ) : ?>
                                                <div style="color:#6B7280;font-size:0.85rem;"><?php echo esc_html( wp_trim_words( $o->service_title, 8, '…' ) ); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:10px;border-bottom:1px solid #F3F4F6;"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $o->status ) ) ); ?></td>
                                        <td style="padding:10px;border-bottom:1px solid #F3F4F6;text-align:right;">₹<?php echo number_format( (float) ( $o->expert_amount ?? 0 ) ); ?></td>
                                        <td style="padding:10px;border-bottom:1px solid #F3F4F6;color:#6B7280;">
                                            <?php echo esc_html( mysql2date( 'j M Y', $o->created_at ) ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ( NME_Payments_Bridge::has_orders() ) : ?>
                    <div class="nme-card nme-mt-3 nme-text-center">
                        <p style="margin:0;color:#6B7280;">No orders yet. Once buyers hire you, they'll show up here.</p>
                    </div>
                <?php endif; ?>

                <?php
                // v0.4.0 — recent account changes log (last 5 approved/rejected requests).
                if ( class_exists( 'NME_Change_Requests' ) ) {
                    $changes_log = NME_Change_Requests::get_recent_changes_log( (int) $expert->id );
                    if ( $changes_log ) {
                        echo '<div class="nme-card nme-mt-3">';
                        echo '<h2 style="margin-top:0;">Recent account changes</h2>';
                        echo $changes_log; // escaped inside the method
                        echo '</div>';
                    }
                }
                ?>

                <div id="profile-sections" class="nme-mt-3">
                    <?php
                    // Embed the payments v2.0 profile editor if available.
                    if ( shortcode_exists( 'nmep_expert_profile_editor' ) ) {
                        echo do_shortcode( '[nmep_expert_profile_editor]' );
                    } else {
                        echo self::card(
                            'Extended profile editing (portfolio, languages, certifications) is available when the payments plugin is active.',
                            ''
                        );
                    }
                    ?>
                </div>

            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
       HELPERS
       ============================================================ */

    private static function stat( $label, $value ) {
        return '<div><div class="nme-trust-stat" style="font-size:1.4rem;font-weight:700;color:#0A7558;">' . esc_html( (string) $value ) . '</div><div class="nme-trust-label" style="color:#6B7280;font-size:0.85rem;">' . esc_html( $label ) . '</div></div>';
    }

    private static function card( $html_inner, $cta_html ) {
        ob_start();
        ?>
        <section class="nme-section">
            <div class="nme-container" style="max-width: 720px;">
                <div class="nme-card nme-text-center">
                    <?php echo wp_kses_post( $html_inner ); ?>
                    <?php if ( $cta_html ) : ?>
                        <p class="nme-mt-3"><?php echo wp_kses_post( $cta_html ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function page_url() {
        $page = get_page_by_path( 'expert-dashboard' );
        return $page ? get_permalink( $page ) : home_url( '/expert-dashboard/' );
    }

    /**
     * Alert banner shown when the expert's registered address is missing or
     * incomplete. Razorpay Route requires a valid street / locality / 6-digit
     * PIN to onboard the linked account — blank fields block payouts entirely,
     * so we nudge the expert to fix it before they hit that cliff.
     */
    private static function address_alert_for( $expert ) {
        $street  = (string) ( $expert->street_address ?? '' );
        $village = (string) ( $expert->village_locality ?? '' );
        $pin     = (string) ( $expert->postal_code ?? '' );

        $missing = array();
        if ( $street === '' )   $missing[] = 'street address';
        if ( $village === '' )  $missing[] = 'village / locality';
        if ( ! preg_match( '/^\d{6}$/', $pin ) ) $missing[] = '6-digit PIN code';

        if ( empty( $missing ) ) return '';

        $list = implode( ', ', $missing );
        $url  = esc_url( home_url( '/edit-address/' ) );
        return '<div class="nme-card nme-mt-3" style="background:#FEE2E2;border-left:4px solid #EF4444;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">'
             . '<div style="color:#991B1B;"><strong>Action needed:</strong> your registered address is incomplete (' . esc_html( $list ) . '). Payouts cannot be set up without it.</div>'
             . '<a class="nme-btn nme-btn-gold" href="' . $url . '">Complete address</a>'
             . '</div>';
    }

    private static function kyc_banner_for( $expert ) {
        $status = (string) ( $expert->kyc_status ?? 'not_started' );
        if ( $status === 'verified' ) return '';

        $map = array(
            'not_started' => array( 'color' => '#92400E', 'bg' => '#FEF3C7', 'text' => 'Complete your KYC to start receiving payouts.', 'cta' => 'Start KYC' ),
            'submitted'   => array( 'color' => '#1E40AF', 'bg' => '#DBEAFE', 'text' => 'KYC submitted — our team is reviewing. This usually takes 1–2 business days.', 'cta' => 'Review details' ),
            'rejected'    => array( 'color' => '#991B1B', 'bg' => '#FEE2E2', 'text' => 'KYC was not approved. ' . ( $expert->kyc_rejection_reason ?: 'Please re-submit with the requested changes.' ), 'cta' => 'Re-submit KYC' ),
        );
        $m = $map[ $status ] ?? $map['not_started'];
        $url = esc_url( home_url( '/kyc/' ) );

        return '<div class="nme-card nme-mt-3" style="background:' . $m['bg'] . ';border-left:4px solid ' . $m['color'] . ';display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">'
             . '<div style="color:' . $m['color'] . ';">' . esc_html( $m['text'] ) . '</div>'
             . '<a class="nme-btn nme-btn-gold" href="' . $url . '">' . esc_html( $m['cta'] ) . '</a>'
             . '</div>';
    }
}