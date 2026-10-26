<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Typed, validated settings, shared across all modules. */
final class BST_Settings {
    public const OPTION = 'bst_settings';

    public static function defaults() {
        return array(
            'modules' => array(
                'data'      => true,
                'login'     => false,
                'menu'      => false,
                'comments'  => false,
                'revisions' => false,
                'media' => false,
            ),
            'data' => array(
                'logo'    => 0,
                'company' => '',
                'email'   => '',
                'phone'   => '',
                'address' => '',
            ),
            'fields' => array(),
            'login' => array(
                'logo'          => 0,
                'background'    => '#f2f5f9',
                'panel'         => '#ffffff',
                'button'        => '#1769d2',
                'links'         => '#1769d2',
                'logo_width'    => 180,
                'background_id' => 0,
                'logo_url'      => '',
                'custom_url_enabled' => false,
                'slug'          => 'moje-prihlaseni',
            ),
            'menu_hidden'    => array(),
            'revisions_limit' => 10,
            'media' => array('max_edge'=>1920,'max_short_edge'=>1080,'quality'=>85,'webp'=>true,'png_policy'=>'opaque','rename_mode'=>'camera','filename_prefix'=>'foto'),
        );
    }

    public static function get() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        return array_replace_recursive( self::defaults(), $saved );
    }

    public static function enabled( $module ) {
        $settings = self::get();
        return ! empty( $settings['modules'][ $module ] );
    }

    public static function sanitize_module( $tab, $raw ) {
        $raw      = is_array( $raw ) ? wp_unslash( $raw ) : array();
        $settings = self::get();
        $settings['modules'][ $tab ] = ! empty( $raw['enabled'] );

        switch ( $tab ) {
            case 'data':
                $data = isset( $raw['data'] ) && is_array( $raw['data'] ) ? $raw['data'] : array();
                $settings['data'] = array(
                    'logo'    => self::image_id( $data['logo'] ?? 0 ),
                    'company' => sanitize_text_field( $data['company'] ?? '' ),
                    'email'   => sanitize_email( $data['email'] ?? '' ),
                    'phone'   => sanitize_text_field( $data['phone'] ?? '' ),
                    'address' => sanitize_textarea_field( $data['address'] ?? '' ),
                );
                $settings['fields'] = self::sanitize_fields( $raw['fields'] ?? array() );
                break;
            case 'login':
                $login = isset( $raw['login'] ) && is_array( $raw['login'] ) ? $raw['login'] : array();
                $settings['login'] = array(
                    'logo'          => self::image_id( $login['logo'] ?? 0 ),
                    'background'    => self::color( $login['background'] ?? '', '#f2f5f9' ),
                    'panel'         => self::color( $login['panel'] ?? '', '#ffffff' ),
                    'button'        => self::color( $login['button'] ?? '', '#1769d2' ),
                    'links'         => self::color( $login['links'] ?? '', '#1769d2' ),
                    'logo_width'    => min( 400, max( 60, absint( $login['logo_width'] ?? 180 ) ) ),
                    'background_id' => self::image_id( $login['background_id'] ?? 0 ),
                    'logo_url'      => esc_url_raw( $login['logo_url'] ?? '' ),
                    'custom_url_enabled' => ! empty( $login['custom_url_enabled'] ),
                    'slug' => sanitize_title( $login['slug'] ?? 'moje-prihlaseni' ),
                );
                break;
            case 'menu':
                $hidden = isset( $raw['menu_hidden'] ) && is_array( $raw['menu_hidden'] ) ? $raw['menu_hidden'] : array();
                $settings['menu_hidden'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $hidden ), static function ( $slug ) {
                    return '' !== $slug && 'bst-toolkit' !== $slug;
                } ) ) );
                break;
            case 'revisions':
                $settings['revisions_limit'] = min( 200, absint( $raw['revisions_limit'] ?? 10 ) );
                break;
            case 'media':
                $media = isset($raw['media']) && is_array($raw['media']) ? $raw['media'] : array();
                $policy = sanitize_key($media['png_policy'] ?? 'opaque');
                $rename = sanitize_key($media['rename_mode'] ?? 'camera');
                $prefix = substr(sanitize_title($media['filename_prefix'] ?? 'foto'),0,40);
                $settings['media'] = array(
                    'max_edge'=>min(4096,max(800,absint($media['max_edge'] ?? 1920))),
                    'max_short_edge'=>min(4096,max(480,absint($media['max_short_edge'] ?? 1080))),
                    'quality'=>min(100,max(65,absint($media['quality'] ?? 85))),
                    'webp'=>!empty($media['webp']),
                    'png_policy'=>in_array($policy,array('skip','opaque','all'),true)?$policy:'opaque',
                    'rename_mode'=>in_array($rename,array('original','camera','all'),true)?$rename:'camera',
                    'filename_prefix'=>$prefix ?: 'foto',
                );
                $settings['media']['max_short_edge']=min($settings['media']['max_edge'],$settings['media']['max_short_edge']);
                break;
            case 'comments':
                // Only the enable switch is needed. No existing comments are removed.
                break;
        }
        return $settings;
    }

    private static function color( $value, $fallback ) {
        return sanitize_hex_color( $value ) ?: $fallback;
    }

    public static function image_id( $value ) {
        $id = absint( $value );
        return $id && wp_attachment_is_image( $id ) ? $id : 0;
    }

    private static function sanitize_fields( $fields ) {
        if ( ! is_array( $fields ) ) {
            return array();
        }
        $clean = array();
        $used  = array( 'logo', 'logo_url', 'company', 'email', 'email_url', 'phone', 'phone_url', 'address' );
        $types = array( 'text', 'textarea', 'email', 'tel', 'url', 'number', 'image' );
        foreach ( array_slice( $fields, 0, 100 ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key   = sanitize_key( $field['key'] ?? '' );
            $label = sanitize_text_field( $field['label'] ?? '' );
            $type  = sanitize_key( $field['type'] ?? 'text' );
            if ( '' === $key || '' === $label || in_array( $key, $used, true ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
                continue;
            }
            $used[] = $key;
            if ( ! in_array( $type, $types, true ) ) {
                $type = 'text';
            }
            $value = $field['value'] ?? '';
            if ( is_array( $value ) ) {
                $value = '';
            }
            $value = (string) $value;
            switch ( $type ) {
                case 'email':
                    $value = sanitize_email( $value );
                    break;
                case 'url':
                    $value = esc_url_raw( $value );
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field( $value );
                    break;
                case 'number':
                    $value = '' === trim( $value ) ? '' : ( is_numeric( $value ) ? (string) (float) $value : '' );
                    break;
                case 'image':
                    $value = (string) self::image_id( $value );
                    break;
                default:
                    $value = sanitize_text_field( $value );
            }
            $clean[] = array( 'key' => $key, 'label' => $label, 'type' => $type, 'value' => $value );
        }
        return $clean;
    }
}
