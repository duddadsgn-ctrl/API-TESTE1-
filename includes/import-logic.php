<?php
// Prevenir acesso direto.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manipula a submissão do formulário de importação.
 */
function vit_handle_import_single_property() {
    if ( ! isset( $_POST['vit_import_nonce_field'] ) || ! wp_verify_nonce( $_POST['vit_import_nonce_field'], 'vit_import_nonce_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Você não tem permissão para executar esta ação.' );
    }

    $api_url = esc_url_raw( $_POST['vit_api_url'] );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] );
    $property_code = sanitize_text_field( $_POST['vit_property_code'] );

    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );
    update_option( 'vit_property_code', $property_code );

    $report = vit_import_property( $api_url, $api_key, $property_code );

    set_transient( 'vit_import_report', $report, 60 );

    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}

/**
 * Função principal que orquestra a importação do imóvel.
 */
function vit_import_property( $api_url, $api_key, $property_code = '' ) {
    $log = [];
    $log[] = 'Iniciando processo de importação...';
    $log[] = 'Consultando API Vista...';
    $property_data = null;

    if ( ! empty( $property_code ) ) {
        // Busca direta pelo código do imóvel (usa POST)
        $log[] = "Buscando detalhes do imóvel com código: {$property_code}.";
        $endpoint = '/imoveis/detalhes';
        $post_fields = [ 'imovel' => $property_code ];
        $response = vit_call_api_post( $api_url, $endpoint, $api_key, $post_fields );
        
        if ( is_wp_error( $response ) ) {
            $log[] = 'ERRO: ' . $response->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }
        $property_data = $response;

    } else {
        // Busca o primeiro imóvel da lista (usa GET com JSON na URL)
        $log[] = 'Buscando lista de imóveis para pegar o primeiro...';
        $endpoint_list = '/imoveis/listar';
        $params = [ 'paginacao' => [ 'pagina' => 1, 'quantidade' => 1 ] ];
        $response_list = vit_call_api_get_with_json_param( $api_url, $endpoint_list, $api_key, $params );

        if ( is_wp_error( $response_list ) ) {
            $log[] = 'ERRO: ' . $response_list->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }

        if ( empty( $response_list ) || ! is_array( $response_list ) ) {
            $log[] = 'ERRO: A lista de imóveis retornou vazia ou em formato inesperado.';
            return [ 'status' => 'error', 'log' => $log ];
        }
        
        if (isset($response_list['total'])) unset($response_list['total']);

        $first_property_key = array_key_first( $response_list );
        $property_code = $first_property_key;
        
        if ( empty( $property_code ) ) {
            $log[] = 'ERRO: Não foi possível encontrar um imóvel na listagem.';
            return [ 'status' => 'error', 'log' => $log ];
        }

        $log[] = "Imóvel encontrado na lista com código: {$property_code}. Buscando detalhes...";
        $endpoint_details = '/imoveis/detalhes';
        $post_fields_details = [ 'imovel' => $property_code ];
        $response_details = vit_call_api_post( $api_url, $endpoint_details, $api_key, $post_fields_details );

        if ( is_wp_error( $response_details ) ) {
            $log[] = 'ERRO: ' . $response_details->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }
        $property_data = $response_details;
    }

    if ( empty( $property_data ) || ! isset( $property_data['Codigo'] ) ) {
        $log[] = "ERRO: Imóvel com código '{$property_code}' não encontrado ou resposta da API inválida.";
        return [ 'status' => 'error', 'log' => $log ];
    }

    $log[] = "Dados do imóvel '{$property_data['Codigo']}' recebidos com sucesso.";
    $post_id = vit_get_or_create_property_post( $property_data['Codigo'], $log );
    vit_update_property_fields( $post_id, $property_data, $log );
    vit_process_property_images( $post_id, $property_data, $log );

    $log[] = '---------------------------------';
    $log[] = 'RESUMO:';
    $log[] = 'Status: SUCESSO';
    $log[] = 'ID do Post: ' . $post_id;
    $log[] = 'Código do Imóvel: ' . $property_data['Codigo'];
    $log[] = 'Título: ' . get_the_title( $post_id );
    $log[] = 'Link para Editar: ' . get_edit_post_link( $post_id );
    $log[] = '---------------------------------';
    $log[] = 'Importação concluída!';

    return [ 'status' => 'success', 'log' => $log ];
}

/**
 * Faz a chamada para a API Vista usando POST (para /detalhes).
 */
function vit_call_api_post( $base_url, $endpoint, $api_key, $post_fields = [] ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( [ 'key' => $api_key ], $url );
    $args = [
        'method'  => 'POST', 'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => json_encode( $post_fields ),
    ];
    $response = wp_remote_post( $url, $args );
    return vit_handle_api_response( $response );
}

/**
 * Faz a chamada para a API Vista usando GET com parâmetros JSON na URL (para /listar).
 */
function vit_call_api_get_with_json_param( $base_url, $endpoint, $api_key, $params = [] ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    
    // Constrói a URL com a chave e os parâmetros codificados como JSON
    $query_args = [ 'key' => $api_key ];
    if ( ! empty( $params ) ) {
        // A API pode esperar os parâmetros em chaves como 'filtros' ou 'paginacao'
        // ou diretamente. Vamos enviar ambos para maximizar a chance de sucesso.
        $query_args['filtros'] = json_encode($params);
        $query_args['paginacao'] = json_encode($params['paginacao'] ?? []);
    }
    
    $url = add_query_arg( $query_args, $url );

    $args = [ 'method' => 'GET', 'timeout' => 30 ];
    $response = wp_remote_get( $url, $args );
    return vit_handle_api_response( $response );
}

/**
 * Função unificada para tratar a resposta da API.
 */
function vit_handle_api_response( $response ) {
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_connection_error', 'Falha ao conectar na API: ' . $response->get_error_message() );
    }
    $body = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response  );
    if ( $http_code !== 200  ) {
        return new WP_Error( 'api_http_error', "API retornou um erro HTTP {$http_code}. Resposta: " . substr($body, 0, 300 ) );
    }
    $data = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'api_json_error', 'Falha ao decodificar o JSON da API. Erro: ' . json_last_error_msg() );
    }
    return $data;
}

// O restante do código permanece o mesmo.

function vit_get_or_create_property_post( $vista_code, &$log ) {
    $args = [ 'post_type' => 'imoveis', 'post_status' => 'any', 'meta_key' => '_vista_codigo', 'meta_value' => $vista_code, 'posts_per_page' => 1, 'fields' => 'ids' ];
    $query = new WP_Query( $args );
    if ( $query->have_posts() ) {
        $post_id = $query->posts[0];
        $log[] = "Imóvel já existe no WordPress com ID {$post_id}. Atualizando...";
        return $post_id;
    } else {
        $post_id = wp_insert_post( [ 'post_type' => 'imoveis', 'post_status' => 'publish', 'post_title' => 'Imóvel ' . $vista_code ] );
        $log[] = "Imóvel não encontrado. Criando novo post com ID {$post_id}.";
        return $post_id;
    }
}

function vit_update_property_fields( $post_id, $property_data, &$log ) {
    $log[] = 'Mapeando e salvando campos...';
    $post_title = ! empty( $property_data['TituloSite'] ) ? $property_data['TituloSite'] : trim( ($property_data['Cidade'] ?? '') . ' - ' . ($property_data['Bairro'] ?? '') );
    wp_update_post( [ 'ID' => $post_id, 'post_title' => sanitize_text_field( $post_title ), 'post_content' => wp_kses_post( $property_data['DescricaoWeb'] ?? '' ) ] );
    $log[] = "Título definido como: '{$post_title}'.";
    $meta_map = [ '_vista_codigo' => 'Codigo', 'codigo' => 'Codigo', 'codigo_corretor' => 'CodigoCorretor', 'bairro' => 'Bairro', 'cidade' => 'Cidade', 'uf' => 'UF', 'latitude' => 'Latitude', 'longitude' => 'Longitude', 'status' => 'Status', 'finalidade' => 'Finalidade', 'categoria' => 'Categoria', 'moeda' => 'Moeda', 'dormitorios' => 'Dormitorios', 'suites' => 'Suites', 'banheiros' => 'BanheiroSocialQtd', 'vagas' => 'Vagas', 'area_total' => 'AreaTotal', 'area_privativa' => 'AreaPrivativa', 'valor_venda' => 'ValorVenda', 'valor_locacao' => 'ValorLocacao', 'valor_iptu' => 'ValorIptu', 'valor_condominio' => 'ValorCondominio' ];
    foreach ( $meta_map as $meta_key => $api_key ) { if ( isset( $property_data[$api_key] ) ) update_post_meta( $post_id, $meta_key, sanitize_text_field( $property_data[$api_key] ) ); }
    if ( ! empty( $property_data['Latitude'] ) && ! empty( $property_data['Longitude'] ) ) update_post_meta( $post_id, 'mapa', "{$property_data['Latitude']},{$property_data['Longitude']}" );
    $feature_arrays = [ 'caracteristicas' => 'Caracteristicas', 'infraestrutura'  => 'InfraEstrutura', 'imediacoes' => 'Imediacoes' ];
    foreach ( $feature_arrays as $meta_key => $api_key ) {
        if ( ! empty( $property_data[$api_key] ) && is_array( $property_data[$api_key] ) ) {
            $positive_items = array_keys( $property_data[$api_key], 'Sim' );
            if ( ! empty( $positive_items ) ) {
                update_post_meta( $post_id, $meta_key, implode( ', ', $positive_items ) );
                update_post_meta( $post_id, "_{$meta_key}_raw", $property_data[$api_key] );
            }
        }
    }
    $log[] = 'Campos salvos com sucesso.';
}

function vit_process_property_images( $post_id, $property_data, &$log ) {
    require_once( ABSPATH . 'wp-admin/includes/media.php' ); require_once( ABSPATH . 'wp-admin/includes/file.php' ); require_once( ABSPATH . 'wp-admin/includes/image.php' );
    if ( empty( $property_data['Foto'] ) || ! is_array( $property_data['Foto'] ) ) { $log[] = 'Nenhuma imagem encontrada.'; return; }
    $photos = $property_data['Foto'];
    $log[] = 'Encontradas ' . count( $photos ) . ' imagens na API.';
    if ( isset( $photos[0]['Ordem'] ) ) usort( $photos, fn($a, $b) => $a['Ordem'] <=> $b['Ordem'] );
    $gallery_ids = []; $featured_image_id = null;
    foreach ( $photos as $photo ) {
        $image_url = $photo['URL'] ?? $photo['URLFoto'] ?? $photo['Foto'] ?? $photo['FotoGrande'] ?? null;
        if ( empty( $image_url ) ) continue;
        $attachment_id = vit_sideload_image( $image_url, $post_id, get_the_title( $post_id ) );
        if ( is_wp_error( $attachment_id ) ) { $log[] = 'ERRO ao baixar imagem ' . $image_url . ': ' . $attachment_id->get_error_message(); } else {
            $gallery_ids[] = $attachment_id;
            update_post_meta( $attachment_id, '_vista_image_origin_url', esc_url_raw( $image_url ) );
            if ( ! empty( $photo['Destaque'] ) && strtolower( $photo['Destaque'] ) === 'sim' ) $featured_image_id = $attachment_id;
        }
    }
    $log[] = "Total de imagens importadas: " . count($gallery_ids) . ".";
    if ( $featured_image_id ) set_post_thumbnail( $post_id, $featured_image_id );
    elseif ( ! empty( $gallery_ids ) ) set_post_thumbnail( $post_id, $gallery_ids[0] );
    if ( ! empty( $gallery_ids ) ) {
        update_post_meta( $post_id, '_vista_gallery_ids', implode( ',', $gallery_ids ) );
        $log[] = "Galeria salva no meta '_vista_gallery_ids' para compatibilidade.";
        update_post_meta( $post_id, 'galeria', $gallery_ids );
    }
}

function vit_sideload_image( $file_url, $post_id, $desc ) {
    $args = [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $post_id, 'meta_query' => [ [ 'key' => '_vista_image_origin_url', 'value' => esc_url_raw( $file_url ) ] ], 'posts_per_page' => 1, 'fields' => 'ids' ];
    $existing = new WP_Query($args);
    if ($existing->have_posts()) return $existing->posts[0];
    $tmp = download_url( $file_url );
    if ( is_wp_error( $tmp ) ) return $tmp;
    preg_match( '/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $file_url, $matches );
    $file_array = [ 'tmp_name' => $tmp, 'name' => basename( $matches[0] ?? $file_url ) ];
    if ( ! $file_array['name'] ) { @unlink( $file_array['tmp_name'] ); return new WP_Error( 'image_sideload_failed', 'Não foi possível determinar o nome do arquivo.' ); }
    $id = media_handle_sideload( $file_array, $post_id, $desc );
    if ( is_wp_error( $id ) ) @unlink( $file_array['tmp_name'] );
    return $id;
}
