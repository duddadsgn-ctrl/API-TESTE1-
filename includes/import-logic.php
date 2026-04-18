<?php
// Prevenir acesso direto.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Funções necessárias para o upload de imagens
require_once( ABSPATH . 'wp-admin/includes/media.php' );
require_once( ABSPATH . 'wp-admin/includes/file.php' );
require_once( ABSPATH . 'wp-admin/includes/image.php' );

/**
 * Manipula a submissão do formulário de importação.
 */
function vit_handle_import_single_property() {
    // 1. Validações de segurança
    if ( ! isset( $_POST['vit_import_nonce_field'] ) || ! wp_verify_nonce( $_POST['vit_import_nonce_field'], 'vit_import_nonce_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Você não tem permissão para executar esta ação.' );
    }

    // 2. Coleta e sanitiza os dados do formulário
    $api_url = esc_url_raw( $_POST['vit_api_url'] );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] );
    $property_code = sanitize_text_field( $_POST['vit_property_code'] );

    // Salva as opções para preenchimento futuro do formulário
    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );
    update_option( 'vit_property_code', $property_code );

    // 3. Executa a lógica de importação
    $report = vit_import_property( $api_url, $api_key, $property_code );

    // 4. Salva o relatório para exibição na página admin
    set_transient( 'vit_import_report', $report, 60 ); // Expira em 1 minuto

    // 5. Redireciona de volta para a página do plugin
    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}

/**
 * Função principal que orquestra a importação do imóvel.
 *
 * @param string $api_url       URL da API.
 * @param string $api_key       Chave da API.
 * @param string $property_code Código do imóvel (opcional).
 * @return array                Relatório da operação.
 */
function vit_import_property( $api_url, $api_key, $property_code = '' ) {
    $log = [];
    $log[] = 'Iniciando processo de importação...';

    // 1. Buscar dados do imóvel na API
    $log[] = 'Consultando API Vista...';
    $property_data = null;

    if ( ! empty( $property_code ) ) {
        // Busca direta pelo código do imóvel
        $log[] = "Buscando detalhes do imóvel com código: {$property_code}.";
        $endpoint = '/imoveis/detalhes';
        $query_params = [ 'imovel' => $property_code ];
        $response = vit_call_api( $api_url, $endpoint, $api_key, $query_params );
        
        if ( is_wp_error( $response ) ) {
            $log[] = 'ERRO: ' . $response->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }
        $property_data = $response;

    } else {
        // Busca o primeiro imóvel da lista e depois seus detalhes
        $log[] = 'Buscando lista de imóveis para pegar o primeiro...';
        $endpoint_list = '/imoveis/listar';
        $query_params_list = [ 'limit' => 1 ];
        $response_list = vit_call_api( $api_url, $endpoint_list, $api_key, $query_params_list );

        if ( is_wp_error( $response_list ) ) {
            $log[] = 'ERRO: ' . $response_list->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }

        if ( empty( $response_list ) || ! is_array( $response_list ) ) {
            $log[] = 'ERRO: A lista de imóveis retornou vazia ou em formato inesperado.';
            return [ 'status' => 'error', 'log' => $log ];
        }
        
        // Pega o código do primeiro imóvel da lista
        $first_property_key = array_key_first( $response_list );
        $property_code = $first_property_key;
        
        if ( empty( $property_code ) ) {
            $log[] = 'ERRO: Não foi possível encontrar um imóvel na listagem.';
            return [ 'status' => 'error', 'log' => $log ];
        }

        $log[] = "Imóvel encontrado na lista com código: {$property_code}. Buscando detalhes...";
        $endpoint_details = '/imoveis/detalhes';
        $query_params_details = [ 'imovel' => $property_code ];
        $response_details = vit_call_api( $api_url, $endpoint_details, $api_key, $query_params_details );

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

    // 2. Encontrar ou criar o post do imóvel
    $post_id = vit_get_or_create_property_post( $property_data['Codigo'], $log );

    // 3. Mapear e salvar os campos
    vit_update_property_fields( $post_id, $property_data, $log );

    // 4. Processar e salvar as imagens
    vit_process_property_images( $post_id, $property_data, $log );

    $log[] = '---------------------------------';
    $log[] = 'RESUMO:';
    $log[] = 'Status: SUCESSO';
    $log[] = 'ID do Post: ' . $post_id;
    $log[] = 'Código do Imóvel: ' . $property_data['Codigo'];
    $log[] = 'Título: ' . get_the_title( $post_id );
    $log[] = '---------------------------------';
    $log[] = 'Importação concluída!';

    return [ 'status' => 'success', 'log' => $log ];
}

/**
 * Faz a chamada para a API Vista.
 *
 * @param string $base_url      URL base da API.
 * @param string $endpoint      Endpoint a ser chamado.
 * @param string $api_key       Chave da API.
 * @param array  $query_params  Parâmetros da query.
 * @return array|WP_Error       Corpo da resposta decodificado ou um erro.
 */
function vit_call_api( $base_url, $endpoint, $api_key, $query_params = [] ) {
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( $query_params, $url );

    $headers = [
        'headers' => [
            'Accept'        => 'application/json',
            'chave'         => $api_key,
        ],
        'timeout' => 30, // 30 segundos de timeout
    ];

    $response = wp_remote_get( $url, $headers );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_connection_error', 'Falha ao conectar na API: ' . $response->get_error_message() );
    }

    $body = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response  );

    if ( $http_code !== 200  ) {
        return new WP_Error( 'api_http_error', "API retornou um erro HTTP {$http_code}. Resposta: " . substr($body, 0, 200 ) );
    }

    $data = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'api_json_error', 'Falha ao decodificar o JSON da API. Erro: ' . json_last_error_msg() );
    }

    return $data;
}

/**
 * Procura por um imóvel existente pelo _vista_codigo. Se não encontrar, cria um novo.
 *
 * @param string $vista_code Código do imóvel na API.
 * @param array  &$log       Array de logs (passado por referência).
 * @return int               O ID do post criado ou encontrado.
 */
function vit_get_or_create_property_post( $vista_code, &$log ) {
    $args = [
        'post_type'      => 'imoveis',
        'post_status'    => 'any',
        'meta_key'       => '_vista_codigo',
        'meta_value'     => $vista_code,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query( $args );

    if ( $query->have_posts() ) {
        $post_id = $query->posts[0];
        $log[] = "Imóvel já existe no WordPress com ID {$post_id}. Atualizando...";
        return $post_id;
    } else {
        $post_data = [
            'post_type'   => 'imoveis',
            'post_status' => 'publish',
            'post_title'  => 'Imóvel ' . $vista_code, // Título temporário
        ];
        $post_id = wp_insert_post( $post_data );
        $log[] = "Imóvel não encontrado. Criando novo post com ID {$post_id}.";
        return $post_id;
    }
}

/**
 * Atualiza os campos (metadados) de um post de imóvel.
 *
 * @param int   $post_id        ID do post.
 * @param array $property_data  Dados do imóvel vindos da API.
 * @param array &$log           Array de logs.
 */
function vit_update_property_fields( $post_id, $property_data, &$log ) {
    $log[] = 'Mapeando e salvando campos...';

    // 1. Título e Conteúdo
    $post_title = ! empty( $property_data['TituloSite'] ) ? $property_data['TituloSite'] : trim( ($property_data['Cidade'] ?? '') . ' - ' . ($property_data['Bairro'] ?? '') );
    $post_content = $property_data['DescricaoWeb'] ?? '';
    
    wp_update_post( [
        'ID'           => $post_id,
        'post_title'   => sanitize_text_field( $post_title ),
        'post_content' => wp_kses_post( $post_content ),
    ] );
    $log[] = "Título definido como: '{$post_title}'.";
    $log[] = 'Conteúdo do post atualizado.';

    // Mapeamento de campos diretos
    $meta_map = [
        '_vista_codigo'     => 'Codigo',
        'codigo'            => 'Codigo',
        'codigo_corretor'   => 'CodigoCorretor',
        'bairro'            => 'Bairro',
        'cidade'            => 'Cidade',
        'uf'                => 'UF',
        'latitude'          => 'Latitude',
        'longitude'         => 'Longitude',
        'status'            => 'Status',
        'finalidade'        => 'Finalidade',
        'categoria'         => 'Categoria',
        'moeda'             => 'Moeda',
        'dormitorios'       => 'Dormitorios',
        'suites'            => 'Suites',
        'banheiros'         => 'BanheiroSocialQtd',
        'vagas'             => 'Vagas',
        'area_total'        => 'AreaTotal',
        'area_privativa'    => 'AreaPrivativa',
        'valor_venda'       => 'ValorVenda',
        'valor_locacao'     => 'ValorLocacao',
        'valor_iptu'        => 'ValorIptu',
        'valor_condominio'  => 'ValorCondominio',
    ];

    foreach ( $meta_map as $meta_key => $api_key ) {
        if ( isset( $property_data[$api_key] ) ) {
            update_post_meta( $post_id, $meta_key, sanitize_text_field( $property_data[$api_key] ) );
        } else {
            $log[] = "Aviso: Campo '{$api_key}' não encontrado na API. Meta '{$meta_key}' não foi salvo.";
        }
    }

    // Campo 'mapa' composto
    if ( ! empty( $property_data['Latitude'] ) && ! empty( $property_data['Longitude'] ) ) {
        $map_value = "{$property_data['Latitude']},{$property_data['Longitude']}";
        update_post_meta( $post_id, 'mapa', $map_value );
        $log[] = "Meta 'mapa' salvo como: {$map_value}.";
    }

    // 2. Características, Infraestrutura e Imediações
    $feature_arrays = [
        'caracteristicas' => 'Caracteristicas',
        'infraestrutura'  => 'InfraEstrutura',
        'imediacoes'      => 'Imediacoes',
    ];

    foreach ( $feature_arrays as $meta_key => $api_key ) {
        if ( ! empty( $property_data[$api_key] ) && is_array( $property_data[$api_key] ) ) {
            $positive_items = [];
            foreach ( $property_data[$api_key] as $name => $value ) {
                if ( strtolower( $value ) === 'sim' ) {
                    $positive_items[] = $name;
                }
            }
            
            if ( ! empty( $positive_items ) ) {
                // Salva como uma string legível separada por vírgulas
                update_post_meta( $post_id, $meta_key, implode( ', ', $positive_items ) );
                $log[] = "Meta '{$meta_key}' salvo com " . count($positive_items) . " itens.";
                
                // Opcional: Salva o array bruto para depuração
                update_post_meta( $post_id, "_{$meta_key}_raw", $property_data[$api_key] );
            }
        } else {
             $log[] = "Aviso: Campo '{$api_key}' não encontrado ou não é um array.";
        }
    }
    
    $log[] = 'Campos salvos com sucesso.';
}

/**
 * Processa e anexa as imagens do imóvel.
 *
 * @param int   $post_id        ID do post.
 * @param array $property_data  Dados do imóvel vindos da API.
 * @param array &$log           Array de logs.
 */
function vit_process_property_images( $post_id, $property_data, &$log ) {
    if ( empty( $property_data['Foto'] ) || ! is_array( $property_data['Foto'] ) ) {
        $log[] = 'Nenhuma imagem encontrada para este imóvel.';
        return;
    }

    $photos = $property_data['Foto'];
    $log[] = 'Encontradas ' . count( $photos ) . ' imagens na API.';

    // Ordenar fotos se houver o campo 'Ordem'
    if ( isset( $photos[0]['Ordem'] ) ) {
        usort( $photos, function( $a, $b ) {
            return $a['Ordem'] <=> $b['Ordem'];
        } );
        $log[] = 'Imagens ordenadas pelo campo "Ordem".';
    }

    $imported_images_count = 0;
    $featured_image_id = null;
    $gallery_ids = [];

    foreach ( $photos as $photo ) {
        // A API pode retornar a URL em diferentes chaves
        $image_url = $photo['URL'] ?? $photo['URLFoto'] ?? $photo['Foto'] ?? $photo['FotoGrande'] ?? null;

        if ( empty( $image_url ) ) {
            $log[] = 'Aviso: Imagem sem URL válida encontrada. Pulando.';
            continue;
        }

        // Anexa a imagem ao post
        $attachment_id = vit_sideload_image( $image_url, $post_id, get_the_title( $post_id ) );

        if ( is_wp_error( $attachment_id ) ) {
            $log[] = 'ERRO ao baixar imagem ' . $image_url . ': ' . $attachment_id->get_error_message();
        } else {
            $imported_images_count++;
            $gallery_ids[] = $attachment_id;
            $log[] = "Imagem {$image_url} importada com sucesso. ID do anexo: {$attachment_id}.";

            // Salva a URL de origem para rastreamento
            update_post_meta( $attachment_id, '_vista_image_origin_url', esc_url_raw( $image_url ) );

            // Verifica se é imagem de destaque
            if ( ! empty( $photo['Destaque'] ) && strtolower( $photo['Destaque'] ) === 'sim' ) {
                $featured_image_id = $attachment_id;
            }
        }
    }

    $log[] = "Total de imagens importadas: {$imported_images_count}.";

    // Define a imagem destacada
    if ( $featured_image_id ) {
        set_post_thumbnail( $post_id, $featured_image_id );
        $log[] = "Imagem destacada definida com o ID: {$featured_image_id}.";
    } elseif ( ! empty( $gallery_ids ) ) {
        set_post_thumbnail( $post_id, $gallery_ids[0] );
        $log[] = 'Nenhuma imagem marcada como destaque. A primeira imagem da galeria foi definida como destacada.';
    } else {
        $log[] = 'Nenhuma imagem importada para definir como destacada.';
    }

    // Salva a galeria em múltiplos formatos
    if ( ! empty( $gallery_ids ) ) {
        // Formato 1: Array de IDs (serializado pelo WordPress)
        update_post_meta( $post_id, 'galeria', $gallery_ids );
        
        // Formato 2: String com IDs separados por vírgula (CSV)
        update_post_meta( $post_id, 'galeria_ids', implode( ',', $gallery_ids ) );
        
        // Formato 3: Array de IDs simples (para compatibilidade)
        update_post_meta( $post_id, 'galeria_imagens', $gallery_ids );

        $log[] = "Galeria salva nos metas 'galeria', 'galeria_ids' e 'galeria_imagens'.";
        $log[] = "-> 'galeria' e 'galeria_imagens' são salvas como um array PHP, útil para desenvolvedores que usam get_post_meta() diretamente.";
        $log[] = "-> 'galeria_ids' é salva como uma string (ex: '101,102,103'), útil para compatibilidade com plugins de galeria ou shortcodes que esperam uma lista de IDs.";
    }
}

/**
 * Baixa uma imagem de uma URL e a anexa a um post.
 *
 * @param string $file_url URL da imagem.
 * @param int    $post_id  ID do post ao qual anexar.
 * @param string $desc     Descrição para a imagem.
 * @return int|WP_Error    O ID do anexo ou um erro.
 */
function vit_sideload_image( $file_url, $post_id, $desc ) {
    // Verifica se a imagem já existe para este post (evita duplicação na mesma importação)
    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'post_parent' => $post_id,
        'meta_query' => [
            [
                'key' => '_vista_image_origin_url',
                'value' => esc_url_raw( $file_url ),
                'compare' => '=',
            ]
        ],
        'posts_per_page' => 1,
        'fields' => 'ids',
    ];
    $existing_attachment = new WP_Query($args);
    if ($existing_attachment->have_posts()) {
        return $existing_attachment->posts[0];
    }

    // Se não existe, faz o download
    $tmp = download_url( $file_url );
    if ( is_wp_error( $tmp ) ) {
        return $tmp;
    }

    $file_array = [];
    preg_match( '/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $file_url, $matches );
    $file_array['name'] = basename( $matches[0] );
    $file_array['tmp_name'] = $tmp;

    // Se o nome do arquivo não puder ser determinado, remove o arquivo temporário
    if ( ! $file_array['name'] ) {
        @unlink( $file_array['tmp_name'] );
        return new WP_Error( 'image_sideload_failed', 'Não foi possível determinar o nome do arquivo da imagem.' );
    }

    $id = media_handle_sideload( $file_array, $post_id, $desc );

    // Se o
