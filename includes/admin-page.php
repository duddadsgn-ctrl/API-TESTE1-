<?php
// Prevenir acesso direto.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adiciona a página do plugin no menu de administração do WordPress.
 */
function vit_add_admin_menu() {
    add_menu_page(
        'Vista Teste 1 Imóvel',
        'Vista Teste 1 Imóvel',
        'manage_options',
        'vista-imovel-teste',
        'vit_admin_page_html',
        'dashicons-rest-api',
        20
    );
}

/**
 * Renderiza o HTML da página de administração.
 */
function vit_admin_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p>Esta página permite testar a importação de um único imóvel da API Vista.</p>

        <?php
        // Exibe o relatório de importação, se houver.
        $report = get_transient( 'vit_import_report' );
        if ( $report ) {
            echo '<div class="notice notice-' . esc_attr( $report['status'] ) . ' is-dismissible" style="white-space: pre-wrap; font-family: monospace;">';
            echo '<h3>Relatório de Importação</h3>';
            echo '<ul>';
            foreach ( $report['log'] as $message ) {
                echo '<li>' . esc_html( $message ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
            delete_transient( 'vit_import_report' );
        }
        ?>

        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="vit_import_single_property">
            <?php wp_nonce_field( 'vit_import_nonce_action', 'vit_import_nonce_field' ); ?>

            <h3>Configurações da API</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="vit_api_url">API URL (Host REST)</label></th>
                    <td>
                        <input type="text" id="vit_api_url" name="vit_api_url" class="regular-text" value="<?php echo esc_attr( get_option( 'vit_api_url', 'https://cli41034-rest.vistahost.com.br'  ) ); ?>" required />
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="vit_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="vit_api_key" name="vit_api_key" class="regular-text" value="<?php echo esc_attr( get_option( 'vit_api_key', 'af095498fe7c4c41d8a6cdb86c820ca5' ) ); ?>" required />
                    </td>
                </tr>
            </table>

            <hr>
            <h3>Filtros Obrigatórios da API (para /listar)</h3>
            <p class="description">Estes valores são enviados para a API Vista na busca pela lista de imóveis. Eles não são salvos como taxonomias no WordPress.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="vit_api_filter_categoria">Categoria (API)</label></th>
                    <td>
                        <input type="text" id="vit_api_filter_categoria" name="vit_api_filter_categoria" value="<?php echo esc_attr( get_option( 'vit_api_filter_categoria' ) ); ?>" required />
                        <p class="description"><strong>Obrigatório para esta conta.</strong> Ex: "Apartamento", "Casa", "Terreno".</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="vit_api_filter_finalidade">Finalidade (API)</label></th>
                    <td>
                        <input type="text" id="vit_api_filter_finalidade" name="vit_api_filter_finalidade" value="<?php echo esc_attr( get_option( 'vit_api_filter_finalidade' ) ); ?>" />
                        <p class="description">Opcional, mas pode ser necessário. Ex: "Venda", "Locação".</p>
                    </td>
                </tr>
                 <tr valign="top">
                    <th scope="row"><label for="vit_property_code">Código do Imóvel (Opcional)</label></th>
                    <td>
                        <input type="text" id="vit_property_code" name="vit_property_code" value="<?php echo esc_attr( get_option( 'vit_property_code' ) ); ?>" />
                        <p class="description">Se preenchido, ignora os filtros acima e busca este imóvel diretamente.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Importar 1 imóvel' ); ?>
        </form>
    </div>
    <?php
}
