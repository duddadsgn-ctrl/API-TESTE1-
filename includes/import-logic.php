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

    // Coleta e sanitiza os dados do formulário
    $api_url = esc_url_raw( $_POST['vit_api_url'] );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] );
    $property_code = sanitize_text_field( $_POST['vit_property_code'] );
    $api_filter_categoria = sanitize_text_field( $_POST['vit_api_filter_categoria'] );
    $api_filter_finalidade = sanitize_text_field( $_POST['vit_api_filter_finalidade'] );

    // Salva as opções para preenchimento futuro do formulário
    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );
    update_option( 'vit_property_code', $property_code );
    update_option( 'vit_api_filter_categoria', $api_filter_categoria );
    update_option( 'vit_api_filter_finalidade', $api_filter_finalidade );

    // Monta o array de filtros para a API
    $api_filters = [];
    if ( ! empty( $api_filter_categoria ) ) {
        $api_filters['Categoria'] = $api_filter_categoria;
    }
    if ( ! empty( $api_filter_finalidade ) ) {
        $api_filters['Finalidade'] = $api_filter_finalidade;
    }

    // Executa a lógica de importação
    $report = vit_import_property( $api_url, $api_key, $property_code, $api_filters );

    set_transient( 'vit_import_report', $report, 60 );
    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}

/**
 * Função principal que orquestra a importação do imóvel.
 */
function vit_import_property( $api_url, $api_key, $property_code = '', $api_filters = [] ) {
    $log = [];
    $log[] = 'Iniciando processo de importação...';
    $log[] = 'Consultando API Vista...';
    $property_data = null;

    if ( ! empty( $property_code ) ) {
        // Se um código é fornecido, a busca é direta para /detalhes via POST.
        $log[] = "Buscando detalhes do imóvel com código: {$property_code}.";
        $endpoint = '/imoveis/detalhes';
        $post_fields = [ 'imovel' => $property_code, 'fields' => [] ];
        $response = vit_call_api_post( $api_url, $endpoint, $api_key, $post_fields, $log );
        
        if ( is_wp_error( $response ) ) {
            $log[] = 'ERRO FINAL: ' . $response->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }
        $property_data = $response;

    } else {
        // Se nenhum código é fornecido, busca na lista usando GET com filtros.
        $log[] = 'Buscando lista de imóveis para pegar o primeiro...';
        $endpoint_list = '/imoveis/listar';
        
        // Monta os parâmetros para a chamada GET
        $list_params = [
            'paginacao' => [ 'pagina' => 1, 'quantidade' => 1 ]
        ];
        
        // Adiciona os filtros obrigatórios diretamente no corpo dos parâmetros
        if ( ! empty( $api_filters ) ) {
            $list_params = array_merge($list_params, $api_filters);
        }

        // A chamada para /listar é via GET, com os parâmetros enviados como um JSON na URL.
        $response_list = vit_call_api_get( $api_url, $endpoint_list, $api_key, $list_params, $log );

        if ( is_wp_error( $response_list ) ) {
            $log[] = 'ERRO FINAL: ' . $response_list->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }

        if ( empty( $response_list ) || ! is_array( $response_list ) || isset($response_list['status']) ) {
            $log[] = 'ERRO: A lista de imóveis retornou vazia ou em formato inesperado. Verifique se os filtros (Categoria) estão corretos.';
            $log[] = 'Resposta da API: ' . json_encode($response_list);
            return [ 'status' => 'error', 'log' => $log ];
        }
        
        // Forma robusta de pegar o primeiro imóvel e seu código
        $first_property_item = reset($response_list); // Pega o primeiro elemento do array
        
        if ( empty( $first_property_item ) || ! isset( $first_property_item['Codigo'] ) ) {
            $log[] = 'ERRO: Nenhum imóvel encontrado com os filtros fornecidos ou a resposta não contém um código de imóvel.';
            return [ 'status' => 'error', 'log' => $log ];
        }
        $property_code = $first_property_item['Codigo'];

        $log[] = "Imóvel encontrado na lista com código: {$property_code}. Buscando detalhes completos...";
        $endpoint_details = '/imoveis/detalhes';
        $post_fields_details = [ 'imovel' => $property_code, 'fields' => [] ];
        $response_details = vit_call_api_post( $api_url, $endpoint_details, $api_key, $post_fields_details, $log );

        if ( is_wp_error( $response_details ) ) {
            $log[] = 'ERRO FINAL: ' . $response_details->get_error_message();
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
 * Faz a chamada para a API Vista usando GET (para /listar).
 * Os parâmetros são enviados como uma string JSON em um parâmetro 'pesquisa'.
 */
function vit_call_api_get( $base_url, $endpoint, $api_key, $params = [], &$log ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    
    $query_args = [ 'key' => $api_key ];
    if ( ! empty( $params ) ) {
        $query_args['pesquisa'] = json_encode($params);
    }
    
    $url = add_query_arg( $query_args, $url );

    $log[] = "Endpoint: GET " . $endpoint;
    $log[] = "URL da Requisição (sem a chave): " . remove_query_arg('key', $url);
    $log[] = "Parâmetros enviados em 'pesquisa': " . json_encode($params, JSON_UNESCAPED_UNICODE);

    $args = [ 
        'method' => 'GET', 
        'timeout' => 30,
        'headers' => [ 'Accept' => 'application/json' ],
    ];
    $response = wp_remote_get( $url, $args );
    return vit_handle_api_response( $response, $log );
}

/**
 * Faz a chamada para a API Vista usando POST (para /detalhes).
 */
function vit_call_api_post( $base_url, $endpoint, $api_key, $post_fields = [], &$log ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( [ 'key' => $api_key ], $url );

    $log[] = "Endpoint: POST " . $endpoint;
    $log[] = "Corpo (Payload) enviado: " . json_encode($post_fields, JSON_UNESCAPED_UNICODE);

    $args = [
        'method'  => 'POST', 'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body'    => json_encode( $post_fields ),
    ];
    $response = wp_remote_post( $url, $args );
    return vit_handle_api_response( $response, $log );
}

/**
 * Função unificada para tratar a resposta da API e adicionar logs.
 */
function vit_handle_api_response( $response, &$log ) {
    if ( is_wp_error( $response ) ) {
        $log[] = "ERRO DE CONEXÃO: " . $response->get_error_message();
        return $response;
    }
    $body = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response  );
    
    $log[] = "HTTP Status Code: " . $http_code;
    $log[] = "Resposta Bruta (início ): " . substr($body, 0, 500);

    if ( $http_code !== 200  ) {
        return new WP_Error( 'api_http_error', "A API retornou um erro HTTP {$http_code}."  );
    }
    $data = json_decode( $body, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'api_json_error', 'Falha ao decodificar o JSON da API.' );
    }
    return $data;
}


// O restante do código (get_or_create_post, update_fields, process_images) permanece o mesmo.
// ... (as funções vit_get_or_create_property_post, vit_update_property_fields, etc. continuam aqui)
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
