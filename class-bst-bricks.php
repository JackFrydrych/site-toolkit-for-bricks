<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Native Bricks dynamic tags, with a shortcode fallback for other builders. */
final class BST_Bricks {
    private static $reserved = array(
        'company'   => 'Název firmy',
        'logo'      => 'Logo – obrázek',
        'logo_url'  => 'Logo – URL',
        'email'     => 'E-mail',
        'email_url' => 'E-mail – mailto:',
        'phone'     => 'Telefon',
        'phone_url' => 'Telefon – tel:',
        'address'   => 'Adresa',
    );

    public static function boot() {
        add_shortcode( 'bst_field', array( __CLASS__, 'shortcode' ) );
        if ( ! BST_Settings::enabled( 'data' ) ) {
            return;
        }
        add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'register_tags' ) );
        add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 20, 3 );
        add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 20, 3 );
        add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_content' ), 20, 2 );
    }

    public static function definitions() {
        $settings = BST_Settings::get();
        $defs     = array();
        foreach ( self::$reserved as $key => $label ) {
            $defs[ $key ] = array( 'label' => $label, 'type' => in_array( $key, array( 'logo', 'logo_url' ), true ) ? 'image' : 'text' );
        }
        foreach ( $settings['fields'] as $field ) {
            if ( ! empty( $field['key'] ) ) {
                $defs[ $field['key'] ] = array( 'label' => $field['label'], 'type' => $field['type'] );
            }
        }
        return $defs;
    }

    public static function register_tags( $tags ) {
        foreach ( self::definitions() as $key => $info ) {
            $tags[] = array(
                'name'  => '{bst_' . $key . '}',
                'label' => $info['label'],
                'group' => 'Site Toolkit for Bricks',
            );
        }
        return $tags;
    }

    private static function attachment_logo() {
        $settings = BST_Settings::get();
        $id = absint( $settings['data']['logo'] );
        if ( ! $id && has_custom_logo() ) {
            $id = absint( get_theme_mod( 'custom_logo' ) );
        }
        return $id;
    }

    private static function resolve( $key, $context = 'text' ) {
        $settings = BST_Settings::get();
        $data     = $settings['data'];
        $id       = self::attachment_logo();
        switch ( $key ) {
            case 'logo':
                // Bricks image elements expect an array containing an attachment ID.
                return 'image' === $context ? ( $id ? array( $id ) : array() ) : ( $id ? wp_get_attachment_image_url( $id, 'full' ) : '' );
            case 'logo_url':
                return $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
            case 'company':
                return '' !== $data['company'] ? $data['company'] : get_bloginfo( 'name' );
            case 'email':
                return $data['email'];
            case 'email_url':
                return $data['email'] ? 'mailto:' . $data['email'] : '';
            case 'phone':
                return $data['phone'];
            case 'phone_url':
                $number = preg_replace( '/[^\d+]/', '', $data['phone'] );
                return $number ? 'tel:' . $number : '';
            case 'address':
                return $data['address'];
            default:
                foreach ( $settings['fields'] as $field ) {
                    if ( $field['key'] !== $key ) {
                        continue;
                    }
                    if ( 'image' === $field['type'] ) {
                        $custom_id = absint( $field['value'] );
                        return 'image' === $context ? ( $custom_id ? array( $custom_id ) : array() ) : ( $custom_id ? wp_get_attachment_image_url( $custom_id, 'full' ) : '' );
                    }
                    return $field['value'];
                }
        }
        return null;
    }

    public static function render_tag( $tag, $post = null, $context = 'text' ) {
        if ( ! is_string( $tag ) ) {
            return $tag;
        }
        $key = self::parse_tag( $tag );
        if ( null === $key ) {
            return $tag;
        }
        $result = self::resolve( $key, $context );
        return null === $result ? $tag : $result;
    }

    private static function parse_tag( $tag ) {
        $name = trim( $tag, '{}' );
        if ( ! preg_match( '/^bst_([a-z][a-z0-9_]*)$/', $name, $matches ) ) {
            return null;
        }
        $key = $matches[1];
        return isset( self::definitions()[ $key ] ) ? $key : null;
    }

    public static function render_content( $content, $post = null, $context = 'text' ) {
        if ( ! is_string( $content ) || false === strpos( $content, '{bst_' ) ) {
            return $content;
        }
        return preg_replace_callback( '/\{(bst_[a-z][a-z0-9_]*)\}/', static function ( $matches ) use ( $post, $context ) {
            $key = self::parse_tag( $matches[1] );
            if ( null === $key ) {
                return $matches[0];
            }
            $value = self::resolve( $key, $context );
            if ( is_array( $value ) ) {
                $value = $value ? (string) reset( $value ) : '';
            }
            return null === $value ? $matches[0] : esc_html( (string) $value );
        }, $content );
    }

    public static function shortcode( $atts ) {
        if ( ! BST_Settings::enabled( 'data' ) ) {
            return '';
        }
        $atts = shortcode_atts( array( 'key' => '' ), $atts, 'bst_field' );
        $key  = sanitize_key( $atts['key'] );
        if ( ! isset( self::definitions()[ $key ] ) ) {
            return '';
        }
        if ( 'logo' === $key ) {
            $id = self::attachment_logo();
            return $id ? wp_get_attachment_image( $id, 'full' ) : '';
        }
        $value = self::resolve( $key );
        return is_scalar( $value ) ? esc_html( (string) $value ) : '';
    }
}
