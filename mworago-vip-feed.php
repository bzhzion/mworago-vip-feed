<?php
/**
 * Plugin Name: Mworago VIP Feed
 * Description: RSS feed des articles privés (VIP) pour workflow n8n de traduction ES/PT
 * Version: 1.8
 *
 * Déploiement :
 *   docker cp kpopify-vip-feed kpopify-wp:/var/www/html/wp-content/plugins/
 *   Activer via wp-admin ou directement en BDD (wp_options > active_plugins)
 *
 * Config : WP Admin > Réglages > VIP Feed
 *
 * Usage (token via header HTTP recommandé, ou query string pour n8n) :
 *   curl -H "X-VIP-Token: TOKEN" "https://fr.mworago.com/?feed=feed-vip"
 *   https://fr.mworago.com/?feed=feed-vip&token=TOKEN  (n8n RSS Feed Read)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Feed RSS ──────────────────────────────────────────────────────────────────

add_action( 'init', function() {
    add_feed( 'feed-vip', 'kpopify_vip_feed_output' );
} );

function kpopify_vip_feed_output() {
    // Token stocké en SHA-256 dans wp_options
    $expected_hash = (string) get_option( 'vip_feed_token_hash', '' );

    // Pas de token configuré → même réponse qu'un token invalide (pas d'info disclosure)
    if ( empty( $expected_hash ) ) {
        status_header( 403 );
        die( 'Forbidden.' );
    }

    // Rate limiting : 5 tentatives max par IP sur 15 minutes
    $ip       = kpopify_vip_feed_get_ip();
    $rate_key = 'vip_feed_fail_' . md5( $ip );
    if ( (int) get_transient( $rate_key ) >= 5 ) {
        status_header( 429 );
        die( 'Too many requests.' );
    }

    // Token via header HTTP (prioritaire) ou query string (n8n RSS Feed Read ne supporte pas les headers)
    if ( isset( $_SERVER['HTTP_X_VIP_TOKEN'] ) ) {
        $provided = (string) $_SERVER['HTTP_X_VIP_TOKEN'];
    } else {
        $provided = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';
    }

    // hash_equals sur les hash SHA-256 : comparaison en temps constant
    if ( ! hash_equals( $expected_hash, hash( 'sha256', $provided ) ) ) {
        set_transient( $rate_key, (int) get_transient( $rate_key ) + 1, 900 );
        status_header( 403 );
        die( 'Forbidden.' );
    }

    header( 'Cache-Control: no-store, no-cache, must-revalidate' );
    header( 'Pragma: no-cache' );
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Referrer-Policy: no-referrer' );

    // Filtre optionnel : ?hours=48 (défaut) — borné 1h–720h, cast int anti-injection
    $hours = isset( $_GET['hours'] ) ? max( 1, min( 720, (int) $_GET['hours'] ) ) : 48;
    $since = date( 'Y-m-d H:i:s', time() - $hours * 3600 );

    // get_posts via WP_Query : pas d'injection SQL possible
    $posts = get_posts( [
        'post_status'   => 'private',
        'numberposts'   => 20,
        'orderby'       => 'date',
        'order'         => 'DESC',
        'no_found_rows' => true,
        'date_query'    => [ [ 'after' => $since, 'inclusive' => true ] ],
    ] );

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<rss version="2.0"><channel><title>Kpopify VIP</title>';

    // Retire les caractères Unicode de contrôle/formatage (override RTL, zero-width...)
    // avant échappement HTML — esc_html() ne protège pas contre le spoofing visuel.
    $strip_unicode_controls = static function ( $value ) {
        $stripped = preg_replace( '/[\p{Cc}\p{Cf}]/u', '', (string) $value );
        return null === $stripped ? $value : $stripped;
    };

    foreach ( $posts as $post ) {
        $title   = esc_html( $strip_unicode_controls( $post->post_title ) );
        $link    = esc_url( get_permalink( $post ) );
        $guid    = (int) $post->ID;
        $pubdate = esc_html( date( DATE_RSS, strtotime( $post->post_date ) ) );
        $slug    = esc_html( $post->post_name );

        echo '<item>';
        echo '<title>' . $title . '</title>';
        echo '<link>' . $link . '</link>';
        echo '<guid isPermaLink="false">' . $guid . '</guid>';
        echo '<pubDate>' . $pubdate . '</pubDate>';
        echo '<slug>' . $slug . '</slug>';
        echo '</item>';
    }

    echo '</channel></rss>';
}

// ── IP réelle (Cloudflare-aware) ──────────────────────────────────────────────

function kpopify_vip_feed_get_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && kpopify_vip_feed_is_cloudflare_ip( $remote ) ) {
        return trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
    }
    return $remote;
}

function kpopify_vip_feed_is_cloudflare_ip( string $ip ): bool {
    static $cf_ranges = [
        '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
        '131.0.72.0/22',   '141.101.64.0/18',  '162.158.0.0/15',
        '172.64.0.0/13',   '173.245.48.0/20',  '188.114.96.0/20',
        '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
    ];
    $long = ip2long( $ip );
    if ( $long === false ) return false;
    foreach ( $cf_ranges as $cidr ) {
        [ $net, $bits ] = explode( '/', $cidr );
        $shift = 32 - (int) $bits;
        if ( ( $long >> $shift ) === ( ip2long( $net ) >> $shift ) ) return true;
    }
    return false;
}

// ── Page de configuration (WP Admin > Réglages > VIP Feed) ───────────────────

add_action( 'admin_menu', function() {
    add_options_page(
        'VIP Feed',
        'VIP Feed',
        'manage_options',
        'kpopify-vip-feed',
        'kpopify_vip_feed_settings_page'
    );
} );

add_action( 'admin_init', function() {
    register_setting( 'kpopify_vip_feed', 'vip_feed_token_hash', [
        'type'              => 'string',
        'sanitize_callback' => 'kpopify_vip_feed_sanitize_token',
        'default'           => '',
    ] );
} );

function kpopify_vip_feed_sanitize_token( $raw ) {
    $raw = sanitize_text_field( $raw );

    // Champ vide = désactivation volontaire
    if ( empty( $raw ) ) {
        return '';
    }

    // Longueur minimale 32 caractères
    if ( strlen( $raw ) < 32 ) {
        add_settings_error(
            'kpopify_vip_feed',
            'token_too_short',
            'Le token doit faire au moins 32 caractères.',
            'error'
        );
        return (string) get_option( 'vip_feed_token_hash', '' );
    }

    // Stocker uniquement le hash SHA-256 — le token clair n'est jamais persisté
    return hash( 'sha256', $raw );
}

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( $hook !== 'settings_page_kpopify-vip-feed' ) return;
    wp_enqueue_script(
        'kpopify-vip-feed-admin',
        plugin_dir_url( __FILE__ ) . 'admin.js',
        [],
        '1.6',
        true
    );
} );

function kpopify_vip_feed_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Forbidden' );
    }

    $has_token = ! empty( get_option( 'vip_feed_token_hash', '' ) );
    // L'URL est affichée sans token (le token n8n doit être configuré séparément)
    $feed_url  = home_url( '/?feed=feed-vip&token=VOTRE_TOKEN' );
    ?>
    <div class="wrap">
        <h1>Kpopify VIP Feed</h1>

        <?php settings_errors( 'kpopify_vip_feed' ); ?>

        <?php if ( $has_token ) : ?>
        <div class="notice notice-success"><p>✓ Token configuré. Le feed est actif.</p></div>
        <?php else : ?>
        <div class="notice notice-warning"><p>⚠ Aucun token configuré. Le feed retourne 403.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'kpopify_vip_feed' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="vip_feed_token_hash">Nouveau token</label></th>
                    <td>
                        <input
                            type="password"
                            id="vip_feed_token_hash"
                            name="vip_feed_token_hash"
                            value=""
                            class="regular-text code"
                            autocomplete="new-password"
                            placeholder="Laisser vide pour ne pas modifier"
                        />
                        <button type="button" id="generate-token" class="button">Générer</button>
                        <button type="button" id="toggle-token" class="button">Afficher</button>
                        <p class="description">
                            ⚠ Le token est stocké sous forme de hash SHA-256 — <strong>il ne peut plus être relu après enregistrement</strong>. Notez-le ailleurs avant de sauvegarder.<br>
                            Le bouton "Afficher" permet uniquement de vérifier ce que vous êtes en train de saisir.<br>
                            Minimum 32 caractères. Laisser vide pour conserver le token actuel.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">URL du feed</th>
                    <td>
                        <code><?php echo esc_html( home_url( '/?feed=feed-vip' ) ); ?></code>
                        <p class="description">
                            Passer le token via le header HTTP <code>X-VIP-Token: VOTRE_TOKEN</code> uniquement.<br>
                            Query string désactivé depuis v1.5 (token visible dans les logs Nginx).
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Enregistrer le token' ); ?>
        </form>
    </div>
    <?php
}
