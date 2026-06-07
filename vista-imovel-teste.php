<?php
/**
 * Plugin Name: Anuve Form — Orçamento Popup
 * Description: Formulário de qualificação de leads em popup estilo Typeform. Shortcode [anuve_form] exibe um botão que abre o popup. Salva no banco, envia e-mail e dispara webhook Zapier.
 * Version:     2.0.0
 * Author:      Anuve Digital
 * Text Domain: anuve-form
 */

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════
// DEFAULTS
// ═══════════════════════════════════════════════════════
function anuveform_defaults() {
    return [
        'notify_email' => 'ivanenriqueales@gmail.com',
        'redirect_url' => 'https://anuvedigital.com.br/obrigado',
        'webhook_url'  => 'https://hooks.zapier.com/hooks/catch/25178606/4ofsqjq/',
        'btn_label'    => 'Solicitar Orçamento',
    ];
}

function anuveform_get() {
    return wp_parse_args( get_option( 'anuve_form_settings', [] ), anuveform_defaults() );
}

// ═══════════════════════════════════════════════════════
// CRIAÇÃO / MIGRAÇÃO DA TABELA
// ═══════════════════════════════════════════════════════
function anuveform_create_table() {
    global $wpdb;
    $t   = $wpdb->prefix . 'anuve_leads';
    $sql = "CREATE TABLE {$t} (
        id               mediumint(9)  NOT NULL AUTO_INCREMENT,
        data_envio       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        nome             varchar(255)  NOT NULL DEFAULT '',
        instagram        varchar(255)  NOT NULL DEFAULT '',
        o_que_vende      varchar(1000) NOT NULL DEFAULT '',
        tem_landing_page varchar(10)   NOT NULL DEFAULT '',
        faturamento      varchar(100)  NOT NULL DEFAULT '',
        whatsapp         varchar(30)   NOT NULL DEFAULT '',
        PRIMARY KEY  (id)
    ) {$wpdb->get_charset_collate()};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

register_activation_hook( __FILE__, 'anuveform_activate' );
function anuveform_activate() {
    anuveform_create_table();
    update_option( 'anuve_form_db_version', '2.0' );
}

// Migração automática para instalações existentes (v1 → v2)
add_action( 'admin_init', 'anuveform_maybe_upgrade' );
function anuveform_maybe_upgrade() {
    $ver = get_option( 'anuve_form_db_version', '1.0' );
    if ( version_compare( $ver, '2.0', '<' ) ) {
        anuveform_create_table();
        update_option( 'anuve_form_db_version', '2.0' );
    }
}

// ═══════════════════════════════════════════════════════
// ADMIN — menus
// ═══════════════════════════════════════════════════════
add_action( 'admin_menu', 'anuveform_admin_menu' );
function anuveform_admin_menu() {
    add_menu_page(
        'Anuve Leads', 'Anuve Leads', 'manage_options',
        'anuve-form', 'anuveform_page_submissions',
        'dashicons-groups', 25
    );
    add_submenu_page( 'anuve-form', 'Leads',         'Leads',         'manage_options', 'anuve-form',          'anuveform_page_submissions' );
    add_submenu_page( 'anuve-form', 'Configurações', 'Configurações', 'manage_options', 'anuve-form-settings', 'anuveform_page_settings'    );
}

// ═══════════════════════════════════════════════════════
// PÁGINA: LEADS
// ═══════════════════════════════════════════════════════
function anuveform_page_submissions() {
    global $wpdb;
    $t = $wpdb->prefix . 'anuve_leads';

    if ( isset( $_GET['export'] ) && current_user_can( 'manage_options' ) ) {
        anuveform_export_csv(); return;
    }

    if ( isset( $_GET['delete'] ) && current_user_can( 'manage_options' ) ) {
        check_admin_referer( 'anuve_del_' . intval( $_GET['delete'] ) );
        $wpdb->delete( $t, [ 'id' => intval( $_GET['delete'] ) ] );
        echo '<div class="notice notice-success"><p>Lead removido com sucesso.</p></div>';
    }

    $rows  = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY data_envio DESC" );
    $total = count( $rows );
    ?>
    <style>
        .av-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px 24px;margin-bottom:14px}
        .av-ch{display:flex;align-items:baseline;justify-content:space-between;border-bottom:2px solid #761648;padding-bottom:8px;margin-bottom:14px}
        .av-cn{font-size:16px;font-weight:700;color:#26292F}
        .av-cd{font-size:12px;color:#999}
        .av-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:10px}
        .av-ii label{display:block;font-size:10px;color:#aaa;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
        .av-ii span{font-size:13px;color:#26292F}
        .av-fat{display:inline-block;background:#fdf2f7;color:#761648;border:1px solid #c0698e;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
        .av-lp-sim{display:inline-block;background:#edf9f0;color:#276749;border:1px solid #6ac48a;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
        .av-lp-nao{display:inline-block;background:#fdf8f0;color:#9b6a1a;border:1px solid #e0b96a;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600}
        .av-del{font-size:11px;color:#c00;text-decoration:none}.av-del:hover{color:#800}
        .av-badge{background:#761648;color:#fff;padding:2px 12px;border-radius:20px;font-size:12px;font-weight:700;margin-left:10px}
    </style>
    <div class="wrap">
        <h1>Leads Anuve <span class="av-badge"><?= esc_html( $total ) ?> leads</span></h1>
        <p><a class="button button-primary" href="<?= esc_url( add_query_arg( 'export', '1' ) ) ?>">⬇ Exportar CSV</a></p>

        <?php if ( ! $rows ) : ?>
            <p style="color:#666;margin-top:16px;">Nenhum lead ainda. Assim que alguém preencher o formulário, aparecerá aqui.</p>
        <?php endif; ?>

        <?php foreach ( $rows as $row ) : ?>
        <div class="av-card">
            <div class="av-ch">
                <div>
                    <span class="av-cn"><?= esc_html( $row->nome ) ?></span>
                    <span class="av-cd"> · <?= esc_html( $row->data_envio ) ?></span>
                </div>
                <a class="av-del"
                   href="<?= esc_url( wp_nonce_url( add_query_arg( 'delete', $row->id ), 'anuve_del_' . $row->id ) ) ?>"
                   onclick="return confirm('Remover este lead?')">✕ Remover</a>
            </div>
            <div class="av-grid">
                <div class="av-ii"><label>Instagram</label><span><?= esc_html( $row->instagram ) ?></span></div>
                <div class="av-ii"><label>O que vende</label><span><?= esc_html( $row->o_que_vende ) ?></span></div>
                <div class="av-ii"><label>Tem landing page?</label>
                    <?php
                    $lp = $row->tem_landing_page;
                    $lp_class = ( $lp === 'Sim' ) ? 'av-lp-sim' : 'av-lp-nao';
                    ?>
                    <span class="<?= $lp_class ?>"><?= esc_html( $lp ) ?></span>
                </div>
                <div class="av-ii"><label>Faturamento Mensal</label><span class="av-fat"><?= esc_html( $row->faturamento ) ?></span></div>
                <div class="av-ii"><label>WhatsApp</label><span><?= esc_html( $row->whatsapp ) ?></span></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════
// PÁGINA: CONFIGURAÇÕES
// ═══════════════════════════════════════════════════════
function anuveform_page_settings() {
    $s     = anuveform_get();
    $saved = isset( $_GET['saved'] );
    ?>
    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>✓ Configurações salvas com sucesso.</p></div>
    <?php endif; ?>

    <div class="wrap" style="max-width:700px">
        <h1>Configurações — Anuve Form</h1>

        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px 28px;margin-top:16px">
            <form method="post" action="<?= esc_url( admin_url( 'admin-post.php' ) ) ?>">
                <input type="hidden" name="action" value="anuveform_save">
                <?php wp_nonce_field( 'anuveform_save' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="notify_email">E-mail de notificação</label></th>
                        <td>
                            <input type="email" id="notify_email" name="notify_email"
                                   value="<?= esc_attr( $s['notify_email'] ) ?>" class="regular-text">
                            <p class="description">Endereço que receberá os dados de cada novo lead.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="redirect_url">URL de Redirecionamento</label></th>
                        <td>
                            <input type="url" id="redirect_url" name="redirect_url"
                                   value="<?= esc_attr( $s['redirect_url'] ) ?>" class="regular-text">
                            <p class="description">Página para onde o lead será enviado após o envio.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_url">Webhook URL (Zapier)</label></th>
                        <td>
                            <input type="url" id="webhook_url" name="webhook_url"
                                   value="<?= esc_attr( $s['webhook_url'] ) ?>" class="regular-text">
                            <p class="description">Endpoint Zapier que receberá os dados via POST (JSON).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="btn_label">Texto do Botão</label></th>
                        <td>
                            <input type="text" id="btn_label" name="btn_label"
                                   value="<?= esc_attr( $s['btn_label'] ) ?>" class="regular-text">
                            <p class="description">Texto padrão exibido no botão que abre o popup.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary button-large">💾 Salvar Configurações</button>
                </p>
            </form>
        </div>

        <div style="background:#fdf2f7;border:1px solid #761648;border-radius:8px;padding:18px 24px;margin-top:20px">
            <strong style="display:block;margin-bottom:8px">📋 Como usar</strong>
            <p style="margin:0 0 10px;font-size:13px;color:#444">Cole este shortcode em qualquer página para exibir o botão que abre o popup:</p>
            <code style="display:block;font-size:15px;padding:10px 14px;background:#fff;border-radius:4px;border:1px solid #e8c5d8">[anuve_form]</code>
            <p style="margin:10px 0 4px;font-size:13px;color:#444">Você pode personalizar o texto do botão direto no shortcode:</p>
            <code style="display:block;font-size:14px;padding:10px 14px;background:#fff;border-radius:4px;border:1px solid #e8c5d8">[anuve_form label="Quero meu orçamento"]</code>
            <p style="margin:10px 0 0;font-size:12px;color:#777">Compatível com Elementor, Gutenberg e qualquer tema WordPress.</p>
        </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════
// SALVAR CONFIGURAÇÕES
// ═══════════════════════════════════════════════════════
add_action( 'admin_post_anuveform_save', 'anuveform_save_settings' );
function anuveform_save_settings() {
    check_admin_referer( 'anuveform_save' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );

    update_option( 'anuve_form_settings', [
        'notify_email' => sanitize_email(      wp_unslash( $_POST['notify_email'] ?? '' ) ),
        'redirect_url' => esc_url_raw(         wp_unslash( $_POST['redirect_url'] ?? '' ) ),
        'webhook_url'  => esc_url_raw(         wp_unslash( $_POST['webhook_url']  ?? '' ) ),
        'btn_label'    => sanitize_text_field( wp_unslash( $_POST['btn_label']    ?? 'Solicitar Orçamento' ) ),
    ] );

    wp_redirect( admin_url( 'admin.php?page=anuve-form-settings&saved=1' ) );
    exit;
}

// ═══════════════════════════════════════════════════════
// EXPORT CSV
// ═══════════════════════════════════════════════════════
function anuveform_export_csv() {
    global $wpdb;
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    $rows = $wpdb->get_results(
        'SELECT * FROM ' . $wpdb->prefix . 'anuve_leads ORDER BY data_envio DESC',
        ARRAY_A
    );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="leads-anuve-' . date( 'Y-m-d' ) . '.csv"' );

    $f = fopen( 'php://output', 'w' );
    fprintf( $f, chr(0xEF) . chr(0xBB) . chr(0xBF) ); // BOM UTF-8

    fputcsv( $f, [ 'ID', 'Data', 'Nome', 'Instagram', 'O que vende', 'Tem Landing Page?', 'Faturamento', 'WhatsApp' ], ';' );
    foreach ( $rows as $r ) {
        fputcsv( $f, [
            $r['id']        ?? '',
            $r['data_envio']       ?? '',
            $r['nome']             ?? '',
            $r['instagram']        ?? '',
            $r['o_que_vende']      ?? '',
            $r['tem_landing_page'] ?? '',
            $r['faturamento']      ?? '',
            $r['whatsapp']         ?? '',
        ], ';' );
    }
    fclose( $f );
    exit;
}

// ═══════════════════════════════════════════════════════
// REST API — recebe, salva, e-mail e webhook
// ═══════════════════════════════════════════════════════
add_action( 'rest_api_init', function () {
    register_rest_route( 'anuve-form/v1', '/submit', [
        'methods'             => 'POST',
        'callback'            => 'anuveform_api_submit',
        'permission_callback' => '__return_true',
    ] );
} );

function anuveform_api_submit( WP_REST_Request $req ) {
    global $wpdb;
    $s = anuveform_get();
    $d = $req->get_json_params();

    if ( empty( $d['nome'] ) ) {
        return new WP_Error( 'invalid', 'Dados insuficientes.', [ 'status' => 400 ] );
    }

    $data = [
        'nome'            => sanitize_text_field( $d['nome']            ?? '' ),
        'instagram'       => sanitize_text_field( $d['instagram']       ?? '' ),
        'o_que_vende'     => sanitize_text_field( $d['o_que_vende']     ?? '' ),
        'tem_landing_page'=> sanitize_text_field( $d['tem_landing_page']?? '' ),
        'faturamento'     => sanitize_text_field( $d['faturamento']     ?? '' ),
        'whatsapp'        => sanitize_text_field( $d['whatsapp']        ?? '' ),
    ];

    $ok = $wpdb->insert( $wpdb->prefix . 'anuve_leads', $data );
    if ( ! $ok ) {
        return new WP_Error( 'db', 'Erro ao salvar no banco de dados.', [ 'status' => 500 ] );
    }
    $insert_id = $wpdb->insert_id;

    // ── E-mail de notificação ────────────────────────────
    if ( ! empty( $s['notify_email'] ) ) {
        $subject = '🎯 Novo Orçamento Solicitado — ' . $data['nome'];
        $body    = "Olá, Ivan!\n\n";
        $body   .= "Nova solicitação de orçamento pelo formulário da Anuve.\n\n";
        $body   .= "───────────────────────────────\n";
        $body   .= "👤 Nome:               " . $data['nome']             . "\n";
        $body   .= "📱 Instagram:          " . $data['instagram']        . "\n";
        $body   .= "🛍️  O que vende:        " . $data['o_que_vende']      . "\n";
        $body   .= "🌐 Tem landing page:   " . $data['tem_landing_page'] . "\n";
        $body   .= "💰 Faturamento/mês:    " . $data['faturamento']      . "\n";
        $body   .= "📞 WhatsApp:           " . $data['whatsapp']         . "\n";
        $body   .= "───────────────────────────────\n\n";
        $body   .= "📅 Data: " . current_time( 'mysql' ) . "\n";
        $body   .= "🆔 ID interno: #" . $insert_id . "\n\n";
        $body   .= "Painel de leads:\n" . admin_url( 'admin.php?page=anuve-form' ) . "\n\n";
        $body   .= "— Anuve Digital\n";

        wp_mail( $s['notify_email'], $subject, $body, [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Anuve Form <no-reply@anuvedigital.com.br>',
        ] );
    }

    // ── Webhook Zapier ───────────────────────────────────
    if ( ! empty( $s['webhook_url'] ) ) {
        wp_remote_post( $s['webhook_url'], [
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( array_merge( $data, [
                'id'         => $insert_id,
                'data_envio' => current_time( 'mysql' ),
                'source_url' => get_site_url(),
            ] ) ),
            'timeout'  => 10,
            'blocking' => false,
        ] );
    }

    return [
        'success'      => true,
        'id'           => $insert_id,
        'redirect_url' => $s['redirect_url'],
    ];
}

// ═══════════════════════════════════════════════════════
// SHORTCODE [anuve_form]
// ═══════════════════════════════════════════════════════
add_shortcode( 'anuve_form', 'anuveform_shortcode' );
function anuveform_shortcode( $atts = [] ) {
    static $popup_rendered = false;

    $atts  = shortcode_atts( [ 'label' => '' ], $atts, 'anuve_form' );
    $s     = anuveform_get();
    $label = ! empty( $atts['label'] ) ? $atts['label'] : $s['btn_label'];

    ob_start();
    anuveform_render( $s, $label, ! $popup_rendered );
    $popup_rendered = true;
    return ob_get_clean();
}

// Fonte Inter
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'anuve-form-inter',
        'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        [], null
    );
} );

// ═══════════════════════════════════════════════════════
// RENDER DO POPUP + BOTÃO TRIGGER
// ═══════════════════════════════════════════════════════
function anuveform_render( $s, $btn_label, $render_popup ) {
    $api_url  = esc_js( rest_url( 'anuve-form/v1/submit' ) );
    $redirect = esc_js( $s['redirect_url'] );

    $faturamento_opts = [
        'A' => 'Menos de R$ 10 mil / mês',
        'B' => 'R$ 10 mil a R$ 50 mil / mês',
        'C' => 'R$ 50 mil a R$ 100 mil / mês',
        'D' => 'R$ 100 mil a R$ 300 mil / mês',
        'E' => 'Acima de R$ 300 mil / mês',
    ];

    // 6 steps (0–5) antes da tela de sucesso (step 6)
    $total_steps = 6;
    ?>

<!-- ══ Anuve Form Popup v2 ══════════════════════════════ -->

<?php if ( $render_popup ) : ?>
<style id="af-style">
/* ── Reset base ───────────────────────────────────────── */
.af-overlay, .af-overlay *, .af-trigger-btn { box-sizing: border-box; }

/* ── Overlay / Backdrop ───────────────────────────────── */
.af-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15,10,20,.60);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity .35s ease;
}
.af-overlay.open {
    opacity: 1;
    pointer-events: all;
}

/* ── Modal ────────────────────────────────────────────── */
.af-modal {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: #FFFFFF;
    width: 100%;
    max-width: 680px;
    margin: 16px;
    position: relative;
    height: 560px;
    overflow: hidden;
    border-radius: 16px;
    box-shadow: 0 28px 80px rgba(0,0,0,.26);
    transform: translateY(28px) scale(.96);
    transition: transform .42s cubic-bezier(.22,1,.36,1);
}
.af-overlay.open .af-modal {
    transform: translateY(0) scale(1);
}

/* ── Botão fechar ─────────────────────────────────────── */
.af-close-btn {
    position: absolute;
    top: 16px; right: 18px;
    z-index: 50;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #C0C0C0;
    font-size: 18px;
    line-height: 1;
    padding: 6px 8px;
    border-radius: 50%;
    transition: color .2s, background .2s;
    font-family: inherit;
}
.af-close-btn:hover { color: #26292F; background: #F5F5F5; }

/* ── Headline topo ────────────────────────────────────── */
.af-modal-headline {
    position: absolute;
    top: 0; left: 0; right: 0;
    padding: 20px 32px 0;
    z-index: 10;
    pointer-events: none;
}
.af-modal-headline span {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #761648;
    text-transform: uppercase;
}

/* ── Barra de progresso ───────────────────────────────── */
.af-pbar-track {
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: #F0F0F0;
    z-index: 20;
}
.af-pbar-fill {
    height: 100%;
    background: #761648;
    width: 0%;
    transition: width .55s ease;
}

/* ── Steps ────────────────────────────────────────────── */
.af-step {
    position: absolute;
    inset: 0;
    padding: 70px 64px 44px;
    display: flex;
    align-items: center;
    opacity: 0;
    transform: translateY(28px);
    pointer-events: none;
    transition: opacity .44s ease, transform .44s ease;
}
.af-step.active  { opacity: 1; transform: translateY(0);    pointer-events: all; }
.af-step.af-exit { opacity: 0; transform: translateY(-28px); pointer-events: none; }
.af-step-inner { width: 100%; }

/* ── Número do step ───────────────────────────────────── */
.af-num {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: #761648;
    margin-bottom: 14px;
}

/* ── Pergunta ─────────────────────────────────────────── */
.af-q {
    font-size: clamp(19px, 2.5vw, 24px);
    font-weight: 400;
    color: #26292F;
    line-height: 1.35;
    margin-bottom: 6px;
}
.af-q strong { font-weight: 700; }

/* ── Subtítulo ────────────────────────────────────────── */
.af-sub {
    font-size: 13px;
    color: #9A9A9A;
    margin-bottom: 22px;
    line-height: 1.6;
}

/* ── Input text ───────────────────────────────────────── */
.af-input {
    display: block;
    width: 100%;
    background: transparent;
    border: none;
    border-bottom: 2px solid #E4E4E4;
    font-family: inherit;
    font-size: 20px;
    color: #26292F;
    padding: 10px 0;
    outline: none;
    caret-color: #761648;
    transition: border-color .3s;
}
.af-input:focus { border-bottom-color: #761648; }
.af-input::placeholder { color: #CCCCCC; font-size: 17px; }

/* ── Mensagem de erro ─────────────────────────────────── */
.af-err {
    font-size: 12px;
    color: #E53E3E;
    height: 18px;
    margin-top: 5px;
    opacity: 0;
    transition: opacity .2s;
}
.af-err.show { opacity: 1; }

/* ── Ações (botões next/back) ─────────────────────────── */
.af-actions {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 28px;
}

.af-btn-next {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #761648;
    color: #FFFFFF;
    border: none;
    border-radius: 6px;
    padding: 13px 28px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .25s, transform .2s, box-shadow .25s;
    letter-spacing: .2px;
}
.af-btn-next:hover {
    background: #5e1139;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(118,22,72,.3);
}

.af-btn-back {
    background: transparent;
    border: none;
    color: #BBBBBB;
    font-family: inherit;
    font-size: 12px;
    letter-spacing: .5px;
    cursor: pointer;
    transition: color .2s;
    padding: 10px 0;
}
.af-btn-back:hover { color: #761648; }

/* ── Hint teclado ─────────────────────────────────────── */
.af-hint {
    margin-top: 14px;
    font-size: 11px;
    color: #DADADA;
}
.af-hint kbd {
    background: #F6F6F6;
    border: 1px solid #E4E4E4;
    border-radius: 3px;
    padding: 1px 5px;
    font-size: 10px;
    color: #AAAAAA;
}

/* ── Choices ──────────────────────────────────────────── */
.af-choices {
    display: flex;
    flex-direction: column;
    gap: 9px;
    margin-top: 4px;
}
.af-choices.af-row {
    flex-direction: row;
    gap: 14px;
}

.af-choice {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #FFFFFF;
    border: 1.5px solid #E8E8E8;
    border-radius: 8px;
    padding: 12px 16px;
    font-family: inherit;
    font-size: 14px;
    color: #333;
    text-align: left;
    cursor: pointer;
    transition: border-color .2s, background .2s, transform .15s;
    width: 100%;
}
.af-choice:hover {
    border-color: #761648;
    background: #fdf2f7;
    transform: translateX(4px);
}
.af-choices.af-row .af-choice {
    justify-content: center;
    flex-direction: column;
    gap: 8px;
    padding: 18px 24px;
    text-align: center;
}
.af-choices.af-row .af-choice:hover {
    transform: translateY(-3px);
}
.af-choice.selected {
    border-color: #761648;
    background: #fdf0f5;
    color: #26292F;
    font-weight: 500;
}
.af-choice-letter {
    min-width: 26px;
    height: 26px;
    border-radius: 5px;
    border: 1.5px solid #E0E0E0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #AAAAAA;
    transition: background .2s, color .2s, border-color .2s;
    flex-shrink: 0;
}
.af-choices.af-row .af-choice-letter {
    font-size: 16px;
    width: 38px;
    height: 38px;
    border-radius: 50%;
}
.af-choice.selected .af-choice-letter {
    background: #761648;
    color: #fff;
    border-color: #761648;
}
.af-choice:hover .af-choice-letter {
    border-color: #761648;
    color: #761648;
}

/* ── Tela de sucesso ──────────────────────────────────── */
.af-success-step {
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}
.af-success-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: #fdf0f5;
    border: 2px solid #761648;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: #761648;
    margin-bottom: 22px;
}
.af-success-title {
    font-size: clamp(20px, 2.8vw, 28px);
    font-weight: 700;
    color: #26292F;
    margin-bottom: 12px;
    line-height: 1.3;
}
.af-success-title em { font-style: normal; color: #761648; }
.af-success-msg {
    font-size: 14px;
    color: #888;
    line-height: 1.8;
    max-width: 380px;
}
.af-dots {
    display: flex;
    gap: 8px;
    margin-top: 24px;
    justify-content: center;
}
.af-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #761648;
    animation: af-pulse 1.5s infinite;
}
.af-dot:nth-child(2) { animation-delay: .22s; }
.af-dot:nth-child(3) { animation-delay: .44s; }
@keyframes af-pulse {
    0%, 80%, 100% { opacity: .2; transform: scale(.7); }
    40%            { opacity: 1;  transform: scale(1);  }
}

/* ── Mobile ───────────────────────────────────────────── */
@media (max-width: 640px) {
    .af-modal {
        height: 100dvh;
        margin: 0;
        border-radius: 0;
    }
    .af-step   { padding: 68px 24px 40px; }
    .af-q      { font-size: 18px; }
    .af-input  { font-size: 18px; }
    .af-hint   { display: none; }
    .af-choices { gap: 7px; }
    .af-choice  { font-size: 13px; padding: 11px 13px; }
    .af-choices.af-row .af-choice { padding: 14px 20px; }
}
</style>

<!-- ══ Popup overlay ════════════════════════════════════ -->
<div class="af-overlay" id="af-overlay" onclick="afCheckClose(event)"
     role="dialog" aria-modal="true" aria-label="Solicitar Orçamento">

  <div class="af-modal" id="af-modal">

    <div class="af-pbar-track"><div class="af-pbar-fill" id="af-pbar"></div></div>

    <div class="af-modal-headline"><span>Solicite seu orçamento.</span></div>

    <button class="af-close-btn" onclick="afClosePopup()" aria-label="Fechar formulário">
        ✕
    </button>

    <!-- STEP 0: Nome -->
    <div class="af-step active" id="af-s0">
        <div class="af-step-inner">
            <div class="af-num">01 &nbsp;→</div>
            <p class="af-q">Qual é o seu <strong>nome?</strong></p>
            <input class="af-input" type="text" id="af-nome"
                   placeholder="Digite seu nome..." autocomplete="name">
            <div class="af-err" id="af-err-nome">Por favor, informe seu nome.</div>
            <div class="af-actions">
                <button class="af-btn-next" onclick="afNext()">
                    Continuar
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
            <div class="af-hint">Pressione <kbd>Enter</kbd> para continuar</div>
        </div>
    </div>

    <!-- STEP 1: Instagram -->
    <div class="af-step" id="af-s1">
        <div class="af-step-inner">
            <div class="af-num">02 &nbsp;→</div>
            <p class="af-q">Qual é o seu <strong>Instagram?</strong></p>
            <p class="af-sub">Pode colocar o @ ou o link completo do perfil.</p>
            <input class="af-input" type="text" id="af-instagram"
                   placeholder="@seuperfil">
            <div class="af-err" id="af-err-instagram">Por favor, informe seu Instagram.</div>
            <div class="af-actions">
                <button class="af-btn-next" onclick="afNext()">
                    Continuar
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </button>
                <button class="af-btn-back" onclick="afBack()">← Voltar</button>
            </div>
            <div class="af-hint">Pressione <kbd>Enter</kbd> para continuar</div>
        </div>
    </div>

    <!-- STEP 2: O que você vende? -->
    <div class="af-step" id="af-s2">
        <div class="af-step-inner">
            <div class="af-num">03 &nbsp;→</div>
            <p class="af-q">O que você <strong>vende?</strong></p>
            <p class="af-sub">Descreva brevemente seu produto ou serviço.</p>
            <input class="af-input" type="text" id="af-vende"
                   placeholder="Ex: cursos online, consultoria, e-commerce...">
            <div class="af-err" id="af-err-vende">Por favor, descreva o que você vende.</div>
            <div class="af-actions">
                <button class="af-btn-next" onclick="afNext()">
                    Continuar
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </button>
                <button class="af-btn-back" onclick="afBack()">← Voltar</button>
            </div>
            <div class="af-hint">Pressione <kbd>Enter</kbd> para continuar</div>
        </div>
    </div>

    <!-- STEP 3: Tem landing page? (Sim / Não) -->
    <div class="af-step" id="af-s3">
        <div class="af-step-inner">
            <div class="af-num">04 &nbsp;→</div>
            <p class="af-q">Você já tem <strong>landing page</strong> hoje?</p>
            <p class="af-sub">Selecione uma das opções abaixo.</p>
            <div class="af-choices af-row">
                <button class="af-choice" type="button"
                        onclick="afSelectLanding(this, 'Sim')">
                    <span class="af-choice-letter">S</span>
                    Sim
                </button>
                <button class="af-choice" type="button"
                        onclick="afSelectLanding(this, 'N&atilde;o')">
                    <span class="af-choice-letter">N</span>
                    Não
                </button>
            </div>
            <input type="hidden" id="af-landing-val" value="">
            <div class="af-err" id="af-err-landing">Por favor, selecione uma opção.</div>
            <div class="af-actions" style="margin-top:18px">
                <button class="af-btn-back" onclick="afBack()">← Voltar</button>
            </div>
        </div>
    </div>

    <!-- STEP 4: Faturamento (múltipla escolha) -->
    <div class="af-step" id="af-s4">
        <div class="af-step-inner">
            <div class="af-num">05 &nbsp;→</div>
            <p class="af-q">Qual é o <strong>faturamento médio mensal</strong> do seu negócio?</p>
            <p class="af-sub">Selecione a faixa que melhor representa o momento atual.</p>
            <div class="af-choices">
                <?php foreach ( $faturamento_opts as $letra => $texto ) : ?>
                <button class="af-choice" type="button"
                        onclick="afSelectFaturamento(this, <?= esc_js( wp_json_encode( $texto ) ) ?>)">
                    <span class="af-choice-letter"><?= esc_html( $letra ) ?></span>
                    <?= esc_html( $texto ) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="af-faturamento-val" value="">
            <div class="af-err" id="af-err-faturamento">Por favor, selecione uma opção.</div>
            <div class="af-actions" style="margin-top:16px">
                <button class="af-btn-back" onclick="afBack()">← Voltar</button>
            </div>
        </div>
    </div>

    <!-- STEP 5: WhatsApp -->
    <div class="af-step" id="af-s5">
        <div class="af-step-inner">
            <div class="af-num">06 &nbsp;→</div>
            <p class="af-q">Qual é o seu <strong>WhatsApp para contato?</strong></p>
            <p class="af-sub">Vamos entrar em contato para apresentar o seu orçamento.</p>
            <input class="af-input" type="tel" id="af-whatsapp"
                   placeholder="(00) 00000-0000" autocomplete="tel">
            <div class="af-err" id="af-err-whatsapp">Por favor, informe seu WhatsApp.</div>
            <div class="af-actions">
                <button class="af-btn-next" onclick="afSubmit()">
                    Enviar solicitação
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </button>
                <button class="af-btn-back" onclick="afBack()">← Voltar</button>
            </div>
        </div>
    </div>

    <!-- STEP 6: Sucesso -->
    <div class="af-step af-success-step" id="af-s6">
        <div class="af-step-inner"
             style="display:flex;flex-direction:column;align-items:center;text-align:center">
            <div class="af-success-icon">✓</div>
            <h2 class="af-success-title">
                Recebemos, <em id="af-nome-ok"></em>
            </h2>
            <p class="af-success-msg">
                Sua solicitação chegou com sucesso.<br>
                Em breve entraremos em contato pelo seu WhatsApp.
            </p>
            <div class="af-dots">
                <div class="af-dot"></div>
                <div class="af-dot"></div>
                <div class="af-dot"></div>
            </div>
        </div>
    </div>

  </div><!-- /.af-modal -->
</div><!-- /.af-overlay -->

<script id="af-js">
(function () {
    'use strict';

    var cur     = 0;
    var TOTAL   = <?= (int) $total_steps ?>;
    var SUCCESS = <?= (int) $total_steps ?>;
    var API     = '<?= $api_url ?>';
    var RURL    = '<?= $redirect ?>';

    /* ─── Helpers ──────────────────────────────────────── */
    function g(id) { return document.getElementById(id); }

    function setBar(n) {
        var pct = n === 0 ? 0 : Math.round((n / TOTAL) * 100);
        g('af-pbar').style.width = pct + '%';
    }

    function focusInput(stepIndex) {
        setTimeout(function () {
            var step = g('af-s' + stepIndex);
            if (!step) return;
            var inp = step.querySelector('input[type="text"], input[type="tel"], input[type="email"]');
            if (inp) inp.focus();
        }, 340);
    }

    /* ─── Popup open / close ────────────────────────────── */
    window.afOpenPopup = function () {
        var ov = g('af-overlay');
        if (!ov) return;
        ov.classList.add('open');
        document.body.style.overflow = 'hidden';
        focusInput(0);
    };

    window.afClosePopup = function () {
        var ov = g('af-overlay');
        if (!ov) return;
        ov.classList.remove('open');
        document.body.style.overflow = '';
    };

    window.afCheckClose = function (e) {
        if (e.target === g('af-overlay')) afClosePopup();
    };

    /* ─── Transição entre steps ─────────────────────────── */
    function goTo(next) {
        var c  = g('af-s' + cur);
        var nx = g('af-s' + next);
        if (!c || !nx) return;

        c.classList.add('af-exit');
        c.classList.remove('active');
        setTimeout(function () { c.classList.remove('af-exit'); }, 520);

        nx.classList.add('active');
        cur = next;
        setBar(cur);
        focusInput(cur);
    }

    /* ─── Validação ─────────────────────────────────────── */
    var stepRules = [
        { field: 'af-nome',      err: 'af-err-nome'      },
        { field: 'af-instagram', err: 'af-err-instagram'  },
        { field: 'af-vende',     err: 'af-err-vende'      },
        null, // step 3 = landing page (choice)
        null, // step 4 = faturamento (choice)
        { field: 'af-whatsapp',  err: 'af-err-whatsapp'   },
    ];

    function validate(n) {
        var r = stepRules[n];
        if (!r) return true;
        var el  = g(r.field);
        var er  = g(r.err);
        var val = el ? el.value.trim() : '';
        if (!val) {
            if (er)  er.classList.add('show');
            if (el) el.focus();
            return false;
        }
        if (er) er.classList.remove('show');
        return true;
    }

    /* ─── Navegação ─────────────────────────────────────── */
    window.afNext = function () {
        if (!validate(cur)) return;
        if (cur < SUCCESS) goTo(cur + 1);
    };

    window.afBack = function () {
        if (cur <= 0) return;
        var errIds = [
            'af-err-nome','af-err-instagram','af-err-vende',
            'af-err-landing','af-err-faturamento','af-err-whatsapp'
        ];
        var er = g(errIds[cur]);
        if (er) er.classList.remove('show');
        goTo(cur - 1);
    };

    /* ─── Sim / Não — Landing Page ──────────────────────── */
    window.afSelectLanding = function (btn, val) {
        btn.closest('.af-choices').querySelectorAll('.af-choice').forEach(function (b) {
            b.classList.remove('selected');
        });
        btn.classList.add('selected');
        g('af-landing-val').value = val;
        g('af-err-landing').classList.remove('show');
        setTimeout(function () { goTo(cur + 1); }, 420);
    };

    /* ─── Faturamento ───────────────────────────────────── */
    window.afSelectFaturamento = function (btn, val) {
        btn.closest('.af-choices').querySelectorAll('.af-choice').forEach(function (b) {
            b.classList.remove('selected');
        });
        btn.classList.add('selected');
        g('af-faturamento-val').value = val;
        g('af-err-faturamento').classList.remove('show');
        setTimeout(function () { goTo(cur + 1); }, 420);
    };

    /* ─── Submit final ──────────────────────────────────── */
    window.afSubmit = function () {
        if (!validate(5)) return;

        var nome  = g('af-nome') ? g('af-nome').value.trim() : '';
        var first = nome.split(' ')[0] || '';
        var nameEl = g('af-nome-ok');
        if (nameEl) nameEl.textContent = first + '!';

        goTo(SUCCESS);

        var payload = {
            nome:             nome,
            instagram:        g('af-instagram')      ? g('af-instagram').value.trim()      : '',
            o_que_vende:      g('af-vende')          ? g('af-vende').value.trim()          : '',
            tem_landing_page: g('af-landing-val')    ? g('af-landing-val').value           : '',
            faturamento:      g('af-faturamento-val')? g('af-faturamento-val').value       : '',
            whatsapp:         g('af-whatsapp')       ? g('af-whatsapp').value.trim()       : '',
        };

        fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        }).catch(function (e) { console.warn('[Anuve Form]', e); });

        if (RURL) {
            setTimeout(function () { window.location.href = RURL; }, 3200);
        }
    };

    /* ─── Teclado ────────────────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        var ov = g('af-overlay');
        if (!ov || !ov.classList.contains('open')) return;

        if (e.key === 'Escape') {
            afClosePopup();
            return;
        }
        if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT') {
            e.preventDefault();
            if (cur === 5) { afSubmit(); } else { afNext(); }
        }
    });

    /* ─── Init ───────────────────────────────────────────── */
    setBar(0);

}());
</script>
<?php endif; // $render_popup ?>

<!-- ══ Botão trigger ════════════════════════════════════ -->
<button class="af-trigger-btn" onclick="afOpenPopup()">
    <?= esc_html( $btn_label ) ?>
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.5" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>
</button>

<style>
.af-trigger-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #761648;
    color: #FFFFFF;
    border: none;
    border-radius: 8px;
    padding: 16px 32px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    letter-spacing: .3px;
    transition: background .25s, transform .2s, box-shadow .25s;
}
.af-trigger-btn:hover {
    background: #5e1139;
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(118,22,72,.30);
}
.af-trigger-btn:active {
    transform: translateY(0);
    box-shadow: none;
}
</style>
<!-- ══ /Anuve Form Popup v2 ══════════════════════════════ -->
    <?php
}
