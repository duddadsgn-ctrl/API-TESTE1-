<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handler do botão "Importar 1 imóvel de teste".
 */
function vit_handle_import_single_property() {
    if ( ! isset( $_POST['vit_import_nonce_field'] ) || ! wp_verify_nonce( $_POST['vit_import_nonce_field'], 'vit_import_nonce_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Você não tem permissão para executar esta ação.' );
    }

    $api_url = esc_url_raw( $_POST['vit_api_url'] ?? '' );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] ?? '' );

    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );

    $report = vit_import_property( $api_url, $api_key );

    set_transient( 'vit_import_report', $report, 120 );
    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}

/**
 * Função principal: descobre automaticamente 1 imóvel bom, busca detalhes e importa.
 */
function vit_import_property( $api_url, $api_key ) {
    $log = [];
    $log[] = '================ INÍCIO DA IMPORTAÇÃO ================';
    $log[] = 'Data/hora: ' . current_time( 'mysql' );

    if ( empty( $api_url ) || empty( $api_key ) ) {
        $log[] = 'ERRO: API URL e API Key são obrigatórios.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    // ============ FASE 1: buscar lista de candidatos ============
    $log[] = '';
    $log[] = '--- FASE 1: buscando lista de candidatos em /imoveis/listar ---';

    // IMPORTANTE: /imoveis/listar NÃO aceita o campo "Foto" — ele só existe em /detalhes.
    $list_params = [
        'fields'    => [ 'Codigo', 'TituloSite', 'Status', 'Categoria', 'Finalidade', 'Dormitorios', 'Cidade', 'Bairro' ],
        'paginacao' => [ 'pagina' => 1, 'quantidade' => 20 ],
    ];

    $list_response = vit_call_api_get( $api_url, '/imoveis/listar', $api_key, $list_params, $log );

    if ( is_wp_error( $list_response ) ) {
        $log[] = 'ERRO FINAL (listar): ' . $list_response->get_error_message();
        return [ 'status' => 'error', 'log' => $log ];
    }

    $candidates = vit_extract_candidates( $list_response, $log );
    if ( empty( $candidates ) ) {
        $log[] = 'ERRO: A API não retornou nenhum imóvel utilizável em /imoveis/listar.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    // ============ FASE 2: pegar o primeiro imóvel disponível ============
    $log[] = '';
    $log[] = '--- FASE 2: selecionando imóvel ---';
    $log[] = 'Total de imóveis recebidos: ' . count( $candidates );

    $chosen       = $candidates[0];
    $property_code = $chosen['Codigo'] ?? '';
    $categoria     = $chosen['Categoria'] ?? '';
    $finalidade    = $chosen['Finalidade'] ?? '';

    $log[] = sprintf(
        'Imóvel selecionado: código=%s | Categoria=%s | Status=%s | Cidade=%s',
        $property_code,
        $categoria ?: '-',
        $chosen['Status']      ?? '-',
        $chosen['Cidade']      ?? '-'
    );

    // ============ FASE 3: buscar detalhes completos ============
    $log[] = '';
    $log[] = '--- FASE 3: buscando detalhes em /imoveis/detalhes ---';

    $details_response = vit_call_detalhes( $api_url, $api_key, $property_code, $categoria, $finalidade, $log );

    if ( is_wp_error( $details_response ) ) {
        $log[] = 'ERRO FINAL (detalhes): ' . $details_response->get_error_message();
        return [ 'status' => 'error', 'log' => $log ];
    }

    if ( empty( $details_response ) || ! isset( $details_response['Codigo'] ) ) {
        $log[] = "ERRO: resposta de /detalhes inválida para código {$property_code}.";
        return [ 'status' => 'error', 'log' => $log ];
    }

    $property_data = $details_response;
    $log[] = "Detalhes do imóvel {$property_data['Codigo']} recebidos.";

    // ============ FASE 4: criar/atualizar post e salvar campos ============
    $log[] = '';
    $log[] = '--- FASE 4: criando/atualizando post no WordPress ---';

    $post_id = vit_get_or_create_property_post( $property_data['Codigo'], $log );
    if ( ! $post_id ) {
        $log[] = 'ERRO: falha ao criar/localizar o post.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    $field_counters = [ 'saved' => 0, 'empty' => 0 ];
    vit_update_property_fields( $post_id, $property_data, $log, $field_counters );

    // ============ FASE 5: imagens ============
    $log[] = '';
    $log[] = '--- FASE 5: processando imagens ---';
    $image_counters = [ 'found' => 0, 'imported' => 0, 'failed' => 0, 'thumbnail_set' => false, 'thumbnail_id' => 0 ];
    vit_process_property_images( $post_id, $property_data, $log, $image_counters );

    // ============ RESUMO FINAL ============
    $log[] = '';
    $log[] = '========== RELATÓRIO FINAL ==========';
    $log[] = 'ID do Post WordPress : ' . $post_id;
    $log[] = 'Código do Imóvel     : ' . $property_data['Codigo'];
    $log[] = 'Título               : ' . get_the_title( $post_id );
    $log[] = 'Categoria (CRM)      : ' . ( $property_data['Categoria']   ?? '-' );
    $log[] = 'Finalidade (CRM)     : ' . ( $property_data['Finalidade']  ?? '-' );
    $log[] = 'Status (CRM)         : ' . ( $property_data['Status']      ?? '-' );
    $log[] = '';
    $log[] = 'Campos salvos        : ' . $field_counters['saved'];
    $log[] = 'Campos vazios        : ' . $field_counters['empty'];
    $log[] = '';
    $log[] = 'Imagens encontradas  : ' . $image_counters['found'];
    $log[] = 'Imagens importadas   : ' . $image_counters['imported'];
    $log[] = 'Imagens falhadas     : ' . $image_counters['failed'];
    $log[] = 'Thumbnail definida   : ' . ( $image_counters['thumbnail_set'] ? ( 'SIM (ID: ' . $image_counters['thumbnail_id'] . ')' ) : 'NÃO' );
    $log[] = '';
    $log[] = 'Link para editar     : ' . get_edit_post_link( $post_id, 'raw' );
    $log[] = '=====================================';
    $log[] = 'Importação concluída com sucesso!';

    return [ 'status' => 'success', 'log' => $log ];
}

/**
 * Normaliza a resposta de /imoveis/listar em array de candidatos.
 * A API pode retornar: array numérico, objeto com chaves numéricas como strings, ou payload com "total".
 */
function vit_extract_candidates( $response, &$log ) {
    if ( ! is_array( $response ) ) {
        $log[] = 'Resposta da listar não é array. Ignorando.';
        return [];
    }

    $items = [];
    foreach ( $response as $key => $value ) {
        if ( in_array( $key, [ 'paginacao', 'total', 'pagina', 'quantidade' ], true ) ) continue;
        if ( is_array( $value ) && isset( $value['Codigo'] ) ) {
            $items[] = $value;
        }
    }
    return $items;
}

/**
 * Busca detalhes do imóvel via POST com pesquisa wrapper (formato correto desta API Vista).
 *
 * Diagnóstico do log anterior:
 *  - Tentativas 3 e 4 passaram da validação de Categoria mas falharam em "fields".
 *  - Causa: array_filter() removia fields:[] por ser array vazio (falsy).
 *  - Solução: pesquisa wrapper com fields sempre presente, sem array_filter.
 */
function vit_call_detalhes( $base_url, $api_key, $codigo, $categoria, $finalidade, &$log ) {
    $url = rtrim( $base_url, '/' ) . '/imoveis/detalhes';
    $url = add_query_arg( [ 'key' => $api_key ], $url );

    // Monta pesquisa sem array_filter — fields:[] DEVE ser mantido mesmo vazio.
    $pesquisa = [ 'imovel' => $codigo, 'Categoria' => $categoria, 'fields' => [] ];
    if ( ! empty( $finalidade ) ) {
        $pesquisa['Finalidade'] = $finalidade;
    }

    $body = wp_json_encode( [ 'pesquisa' => $pesquisa ] );

    $log[] = 'Endpoint   : POST /imoveis/detalhes';
    $log[] = 'Payload    : ' . $body;

    $raw = wp_remote_post( $url, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body'    => $body,
    ] );

    return vit_handle_api_response( $raw, $log );
}

/**
 * GET para /imoveis/listar — parâmetros via query string "pesquisa" em JSON.
 */
function vit_call_api_get( $base_url, $endpoint, $api_key, $params, &$log ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( [
        'key'      => $api_key,
        'pesquisa' => wp_json_encode( $params ),
        'showtotal' => 1,
    ], $url );

    $log[] = 'Endpoint   : GET ' . $endpoint;
    $log[] = 'Pesquisa   : ' . wp_json_encode( $params, JSON_UNESCAPED_UNICODE );

    $response = wp_remote_get( $url, [
        'timeout' => 30,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );
    return vit_handle_api_response( $response, $log );
}

/**
 * POST para /imoveis/detalhes.
 */
function vit_call_api_post( $base_url, $endpoint, $api_key, $post_fields, &$log ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( [ 'key' => $api_key ], $url );

    $log[] = 'Endpoint   : POST ' . $endpoint;
    $log[] = 'Payload    : ' . wp_json_encode( $post_fields, JSON_UNESCAPED_UNICODE );

    $response = wp_remote_post( $url, [
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body'    => wp_json_encode( $post_fields ),
    ] );
    return vit_handle_api_response( $response, $log );
}

function vit_handle_api_response( $response, &$log ) {
    if ( is_wp_error( $response ) ) {
        $log[] = 'ERRO DE CONEXÃO: ' . $response->get_error_message();
        return $response;
    }
    $body      = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response );

    $log[] = 'HTTP Status: ' . $http_code;
    $log[] = 'Resposta   : ' . substr( $body, 0, 500 ) . ( strlen( $body ) > 500 ? ' (...truncado)' : '' );

    $decoded = json_decode( $body, true );

    if ( $http_code !== 200 ) {
        // Tenta extrair a mensagem de erro estruturada da API Vista.
        $api_msg = '';
        if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
            $api_msg = is_array( $decoded['message'] ) ? implode( ' | ', $decoded['message'] ) : (string) $decoded['message'];
        }
        $log[] = 'ERRO da API: ' . ( $api_msg ?: '(sem mensagem)' );
        return new WP_Error( 'api_http_error', "HTTP {$http_code}" . ( $api_msg ? " — {$api_msg}" : '' ) );
    }
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'api_json_error', 'Falha ao decodificar JSON: ' . json_last_error_msg() );
    }
    return $decoded;
}

/**
 * Procura post existente por _vista_codigo ou cria novo.
 */
function vit_get_or_create_property_post( $vista_code, &$log ) {
    $query = new WP_Query( [
        'post_type'      => 'imoveis',
        'post_status'    => 'any',
        'meta_key'       => '_vista_codigo',
        'meta_value'     => $vista_code,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );

    if ( $query->have_posts() ) {
        $post_id = $query->posts[0];
        $log[] = "Post existente localizado (ID {$post_id}). Atualizando.";
        return $post_id;
    }

    $post_id = wp_insert_post( [
        'post_type'   => 'imoveis',
        'post_status' => 'publish',
        'post_title'  => 'Imóvel ' . $vista_code,
    ] );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        $log[] = 'Falha ao criar post: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'erro desconhecido' );
        return 0;
    }
    $log[] = "Post novo criado (ID {$post_id}).";
    return $post_id;
}

/**
 * Salva todos os campos do imóvel como meta e loga um a um.
 */
function vit_update_property_fields( $post_id, $data, &$log, &$counters ) {
    // Título e conteúdo
    $title = ! empty( $data['TituloSite'] )
        ? $data['TituloSite']
        : trim( ( $data['Cidade'] ?? '' ) . ' - ' . ( $data['Bairro'] ?? '' ) );
    if ( empty( trim( $title, ' -' ) ) ) $title = 'Imóvel ' . ( $data['Codigo'] ?? '' );

    wp_update_post( [
        'ID'           => $post_id,
        'post_title'   => sanitize_text_field( $title ),
        'post_content' => wp_kses_post( $data['DescricaoWeb'] ?? '' ),
    ] );
    $log[] = "[TÍTULO] definido como: \"{$title}\"";

    // Mapa: meta_key WP => nome do campo na API Vista
    $map = [
        '_vista_codigo'   => 'Codigo',
        'codigo'          => 'Codigo',
        'codigo_corretor' => 'CodigoCorretor',
        'bairro'          => 'Bairro',
        'cidade'          => 'Cidade',
        'uf'              => 'UF',
        'latitude'        => 'Latitude',
        'longitude'       => 'Longitude',
        'status'          => 'Status',
        'finalidade'      => 'Finalidade',
        'categoria'       => 'Categoria',
        'moeda'           => 'Moeda',
        'dormitorios'     => 'Dormitorios',
        'suites'          => 'Suites',
        'banheiros'       => 'BanheiroSocialQtd',
        'vagas'           => 'Vagas',
        'area_total'      => 'AreaTotal',
        'area_privativa'  => 'AreaPrivativa',
        'valor_venda'     => 'ValorVenda',
        'valor_locacao'   => 'ValorLocacao',
        'valor_iptu'      => 'ValorIptu',
        'valor_condominio' => 'ValorCondominio',
    ];

    foreach ( $map as $meta_key => $api_key ) {
        $value = $data[ $api_key ] ?? null;
        if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
            $log[] = sprintf( "[CAMPO] API:'%s' -> WP:'%s' | Valor: \"\" | - VAZIO (ignorado)", $api_key, $meta_key );
            $counters['empty']++;
            continue;
        }
        $clean = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : maybe_serialize( $value );
        update_post_meta( $post_id, $meta_key, $clean );
        $preview = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
        if ( mb_strlen( $preview ) > 60 ) $preview = mb_substr( $preview, 0, 60 ) . '...';
        $log[] = sprintf( "[CAMPO] API:'%s' -> WP:'%s' | Valor: \"%s\" | OK SALVO", $api_key, $meta_key, $preview );
        $counters['saved']++;
    }

    // Mapa "latitude,longitude"
    if ( ! empty( $data['Latitude'] ) && ! empty( $data['Longitude'] ) ) {
        $mapa_val = $data['Latitude'] . ',' . $data['Longitude'];
        update_post_meta( $post_id, 'mapa', $mapa_val );
        $log[] = "[CAMPO] WP:'mapa' | Valor: \"{$mapa_val}\" | OK SALVO";
        $counters['saved']++;
    } else {
        $log[] = "[CAMPO] WP:'mapa' | Valor: \"\" | - VAZIO (ignorado)";
        $counters['empty']++;
    }

    // Características, Infraestrutura, Imediações
    $feature_map = [
        'caracteristicas' => 'Caracteristicas',
        'infraestrutura'  => 'InfraEstrutura',
        'imediacoes'      => 'Imediacoes',
    ];
    foreach ( $feature_map as $meta_key => $api_key ) {
        $group = $data[ $api_key ] ?? null;
        if ( empty( $group ) || ! is_array( $group ) ) {
            $log[] = "[CARACTERISTICAS] '{$api_key}': vazio/ignorado.";
            $counters['empty']++;
            continue;
        }
        $positive = array_keys( $group, 'Sim' );
        $total    = count( $group );
        $pos      = count( $positive );
        $ignored  = $total - $pos;

        $log[] = sprintf( "[CARACTERISTICAS] '%s' -> Total: %d | Positivos (Sim): %d | Ignorados: %d", $api_key, $total, $pos, $ignored );
        if ( $pos > 0 ) {
            $log[] = sprintf( "[CARACTERISTICAS] '%s' positivos: %s", $api_key, implode( ', ', $positive ) );
            update_post_meta( $post_id, $meta_key, implode( ', ', $positive ) );
            update_post_meta( $post_id, "_{$meta_key}_raw", $group );
            $counters['saved']++;
        } else {
            $counters['empty']++;
        }
    }
}

/**
 * Baixa todas as imagens do bloco Foto, define thumbnail e salva galeria em 4 metas.
 */
function vit_process_property_images( $post_id, $data, &$log, &$counters ) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $photos = $data['Foto'] ?? [];
    if ( empty( $photos ) || ! is_array( $photos ) ) {
        $log[] = 'Nenhuma imagem no bloco Foto.';
        return;
    }

    // Normaliza: se for objeto associativo com chaves numéricas-string, transforma em lista
    $photos = array_values( $photos );
    $counters['found'] = count( $photos );
    $log[] = 'Imagens encontradas na API: ' . $counters['found'];

    if ( isset( $photos[0]['Ordem'] ) ) {
        usort( $photos, fn( $a, $b ) => (int) ( $a['Ordem'] ?? 0 ) <=> (int) ( $b['Ordem'] ?? 0 ) );
    }

    $gallery_ids      = [];
    $featured_id      = 0;
    $total            = count( $photos );

    foreach ( $photos as $i => $photo ) {
        $idx  = $i + 1;
        $url  = null;
        $used_field = null;
        foreach ( [ 'URL', 'URLFoto', 'Foto', 'FotoGrande', 'Link' ] as $field ) {
            if ( ! empty( $photo[ $field ] ) ) {
                $url = $photo[ $field ];
                $used_field = $field;
                break;
            }
        }
        $destaque = ( ! empty( $photo['Destaque'] ) && strtolower( $photo['Destaque'] ) === 'sim' );

        if ( empty( $url ) ) {
            $log[] = sprintf( "[IMAGEM %d/%d] URL ausente (campos testados: URL,URLFoto,Foto,FotoGrande,Link). PULADA.", $idx, $total );
            $counters['failed']++;
            continue;
        }

        $log[] = sprintf( "[IMAGEM %d/%d] URL: %s | Campo usado: '%s' | Destaque: %s", $idx, $total, $url, $used_field, $destaque ? 'Sim' : 'Não' );

        $attachment_id = vit_sideload_image( $url, $post_id, get_the_title( $post_id ) );
        if ( is_wp_error( $attachment_id ) ) {
            $log[] = sprintf( "[IMAGEM %d/%d] Download: FALHOU | Motivo: %s", $idx, $total, $attachment_id->get_error_message() );
            $counters['failed']++;
            continue;
        }

        $gallery_ids[] = $attachment_id;
        update_post_meta( $attachment_id, '_vista_image_origin_url', esc_url_raw( $url ) );
        $log[] = sprintf( "[IMAGEM %d/%d] Download: SUCESSO | Attachment ID: %d", $idx, $total, $attachment_id );
        $counters['imported']++;

        if ( $destaque && ! $featured_id ) {
            $featured_id = $attachment_id;
            $log[] = sprintf( "[IMAGEM %d/%d] -> Marcada como THUMBNAIL (Destaque=Sim)", $idx, $total );
        }
    }

    // Se não houve destaque explícito, usa a primeira importada
    if ( ! $featured_id && ! empty( $gallery_ids ) ) {
        $featured_id = $gallery_ids[0];
        $log[] = sprintf( "[THUMBNAIL] Nenhuma imagem marcada como Destaque=Sim. Usando a primeira (ID %d).", $featured_id );
    }

    if ( $featured_id ) {
        set_post_thumbnail( $post_id, $featured_id );
        $counters['thumbnail_set'] = true;
        $counters['thumbnail_id']  = $featured_id;
    }

    if ( ! empty( $gallery_ids ) ) {
        $csv = implode( ',', $gallery_ids );
        // galeria -> array de IDs (para plugins que leem array)
        update_post_meta( $post_id, 'galeria', $gallery_ids );
        // galeria_ids e galeria_imagens -> CSV
        update_post_meta( $post_id, 'galeria_ids', $csv );
        update_post_meta( $post_id, 'galeria_imagens', $csv );
        // _vista_gallery_ids -> retrocompatibilidade
        update_post_meta( $post_id, '_vista_gallery_ids', $csv );

        $log[] = "[GALERIA] Salvo em 4 metas: galeria (array), galeria_ids (CSV), galeria_imagens (CSV), _vista_gallery_ids (CSV).";
        $log[] = '[GALERIA] IDs: ' . $csv;
    }
}

/**
 * Baixa 1 imagem e cria o attachment no WP, evitando duplicatas pela URL de origem.
 */
function vit_sideload_image( $file_url, $post_id, $desc ) {
    // Evita duplicata
    $existing = new WP_Query( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'meta_query'     => [ [ 'key' => '_vista_image_origin_url', 'value' => esc_url_raw( $file_url ) ] ],
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    if ( $existing->have_posts() ) {
        return (int) $existing->posts[0];
    }

    $tmp = download_url( $file_url );
    if ( is_wp_error( $tmp ) ) return $tmp;

    preg_match( '/[^\?]+\.(jpg|jpe|jpeg|gif|png|webp)/i', $file_url, $matches );
    $filename = ! empty( $matches[0] ) ? basename( $matches[0] ) : basename( parse_url( $file_url, PHP_URL_PATH ) ?: 'imovel.jpg' );

    $file_array = [ 'tmp_name' => $tmp, 'name' => $filename ?: 'imovel.jpg' ];

    $id = media_handle_sideload( $file_array, $post_id, $desc );
    if ( is_wp_error( $id ) ) {
        @unlink( $file_array['tmp_name'] );
        return $id;
    }
    return (int) $id;
}
