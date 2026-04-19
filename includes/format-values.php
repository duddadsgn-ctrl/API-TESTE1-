<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sempre que um dos campos monetários brutos for salvo em um post do tipo
 * 'imoveis', gera automaticamente a versão formatada em BRL no campo
 * correspondente (*_formatado).
 *
 * Exemplo: valor_venda = "4600000"  =>  valor_venda_formatado = "R$4.600.000"
 */
add_action( 'updated_post_meta', 'vit_auto_format_money_meta', 10, 4 );
add_action( 'added_post_meta',   'vit_auto_format_money_meta', 10, 4 );

function vit_auto_format_money_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
    $money_fields = [
        'valor_venda'      => 'valor_venda_formatado',
        'valor_locacao'    => 'valor_locacao_formatado',
        'valor_iptu'       => 'valor_iptu_formatado',
        'valor_condominio' => 'valor_condominio_formatado',
    ];

    if ( ! isset( $money_fields[ $meta_key ] ) ) {
        return;
    }

    if ( get_post_type( $post_id ) !== 'imoveis' ) {
        return;
    }

    $formatted = vit_format_brl( $meta_value );
    $fmt_key   = $money_fields[ $meta_key ];

    if ( $formatted !== '' ) {
        update_post_meta( $post_id, $fmt_key, $formatted );
    } else {
        delete_post_meta( $post_id, $fmt_key );
    }
}

/**
 * Formata um número como moeda BRL: "R$4.000.000" ou "R$1.500,50".
 * Retorna string vazia para valor zero, vazio ou inválido.
 */
function vit_format_brl( $value ) {
    if ( $value === null || $value === '' ) {
        return '';
    }

    $s = is_string( $value ) ? trim( $value ) : (string) $value;
    $s = preg_replace( '/[^\d,.\-]/', '', $s );

    // Formato BR (ponto = milhar, vírgula = decimal): "1.234,56"
    if ( strpos( $s, ',' ) !== false && strpos( $s, '.' ) !== false ) {
        $s = str_replace( '.', '', $s );
        $s = str_replace( ',', '.', $s );
    } elseif ( strpos( $s, ',' ) !== false ) {
        $s = str_replace( ',', '.', $s );
    }

    if ( ! is_numeric( $s ) ) {
        return '';
    }

    $num = (float) $s;
    if ( $num <= 0 ) {
        return '';
    }

    $has_cents = abs( $num - floor( $num ) ) > 0.0001;
    $decimals  = $has_cents ? 2 : 0;

    return 'R$' . number_format( $num, $decimals, ',', '.' );
}
