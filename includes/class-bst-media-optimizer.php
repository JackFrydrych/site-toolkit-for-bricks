<?php
/**
 * Media Optimizer — run on newly uploaded WordPress media, before attachments
 * are inserted. Existing media and sideload/import jobs are not modified.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BST_Media_Optimizer {
    public static function boot() {
        add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'rename_before_upload' ), 20 );
        add_filter( 'wp_handle_upload', array( __CLASS__, 'optimize_uploaded_file' ), 20, 2 );
    }

    private static function config() {
        $options = BST_Settings::get();
        return $options['media'];
    }

    public static function rename_before_upload( $file ) {
        if ( ! is_array( $file ) || empty( $file['name'] ) || ! empty( $file['error'] ) ) {
            return $file;
        }
        $options = self::config();
        $mode = $options['rename_mode'];
        if ( 'original' === $mode ) {
            return $file;
        }
        $name = (string) $file['name'];
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
            return $file;
        }
        $base = pathinfo( $name, PATHINFO_FILENAME );
        if ( 'camera' === $mode && ! self::looks_unhelpful( $base ) ) {
            return $file;
        }
        $prefix = sanitize_title( $options['filename_prefix'] );
        $prefix = $prefix ?: 'foto';
        $date   = wp_date( 'Ymd' );
        $random = strtolower( wp_generate_password( 8, false, false ) );
        $file['name'] = sanitize_file_name( $prefix . '-' . $date . '-' . $random . '.' . $ext );
        return $file;
    }

    public static function looks_unhelpful( $name ) {
        $name = trim( (string) $name );
        if ( '' === $name ) {
            return true;
        }
        if ( preg_match( '/^(?:IMG|DSC|DSCF|DSCN|PXL|IMAGE|PHOTO|SCREENSHOT)[ _-]?(?:\d{3,}|[A-Z0-9]{12,})(?:[ _-]|$)/i', $name ) ) {
            return true;
        }
        if ( preg_match( '/^(?:img|photo|image|dsc)[ _-]?(?:\d{3,}|[A-Za-z0-9]{12,})/i', $name ) ) {
            return true;
        }
        if ( preg_match( '/^(?:foto|image|photo|obrazek)[-_]?[0-9]{5,}$/i', $name ) ) {
            return true;
        }
        return false;
    }

    public static function optimize_uploaded_file( $upload, $context = 'upload' ) {
        if ( 'upload' !== $context || ! is_array( $upload ) || ! empty( $upload['error'] )
            || empty( $upload['file'] ) || empty( $upload['url'] ) || empty( $upload['type'] ) ) {
            return $upload;
        }
        $file = $upload['file'];
        if ( ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) {
            return $upload;
        }

        $declared = (string) $upload['type'];
        if ( ! in_array( $declared, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
            return $upload;
        }
        $actual = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $file ) : false;
        if ( ! $actual || $actual !== $declared ) {
            return $upload;
        }

        $options = self::config();
        if ( 'image/png' === $actual ) {
            if ( 'skip' === $options['png_policy'] ) {
                return $upload;
            }
            if ( 'opaque' === $options['png_policy'] && self::png_may_be_transparent( $file ) ) {
                return $upload;
            }
        }

        $size = @getimagesize( $file );
        if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
            return $upload;
        }
        $portrait = (int) $size[1] > (int) $size[0];
        $max_edge = (int) $options['max_edge'];
        $max_short = min( $max_edge, (int) $options['max_short_edge'] );
        $limit_width = $portrait ? $max_short : $max_edge;
        $limit_height = $portrait ? $max_edge : $max_short;
        $resize = (int) $size[0] > $limit_width || (int) $size[1] > $limit_height;
        $convert = 'image/webp' !== $actual && ! empty( $options['webp'] );
        if ( ! $resize && ! $convert ) {
            return $upload;
        }

        $output_mime = $convert ? 'image/webp' : $actual;
        if ( ! function_exists( 'wp_image_editor_supports' ) ) {
            return $upload;
        }
        if ( ! wp_image_editor_supports( array( 'mime_type' => $output_mime ) ) ) {
            if ( ! $resize || ! wp_image_editor_supports( array( 'mime_type' => $actual ) ) ) {
                return $upload;
            }
            $output_mime = $actual;
        }
        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return $upload;
        }
        // Cameras and phones commonly store pixels sideways and declare their
        // intended display orientation in JPEG EXIF. WordPress normally fixes
        // this when generating attachment metadata, but this plugin converts
        // uploads to WebP *before* that stage. Normalize orientation FIRST.
        // Otherwise the WebP can lose the EXIF flag and display sideways.
        if ( 'image/jpeg' === $actual ) {
            // With no EXIF reader we cannot reliably tell which way to turn the
            // pixels. In that case, leave the original to WordPress rather than
            // risk creating incorrectly oriented output.
            if ( ! is_callable( 'exif_read_data' ) ) {
                return $upload;
            }
            $exif = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $orientation = isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
            if ( $orientation < 1 || $orientation > 8 ) {
                return $upload;
            }
            $rotated = $editor->maybe_exif_rotate();
            // Distinguish "not rotated" from success when EXIF requires it.
            // Also cover editors unable to reset the embedded orientation.
            if ( is_wp_error( $rotated ) || ( 1 !== $orientation && true !== $rotated ) ) {
                return $upload;
            }
        }

        // Rotate/mirror before calculating the dimensions or resizing: JPEGs
        // with orientation 5, 6, 7 or 8 exchange their physical dimensions.
        $editor_size = $editor->get_size();
        if ( ! is_array( $editor_size ) || empty( $editor_size['width'] ) || empty( $editor_size['height'] ) ) {
            return $upload;
        }
        $width = (int) $editor_size['width'];
        $height = (int) $editor_size['height'];
        $portrait = $height > $width;
        $limit_width = $portrait ? $max_short : $max_edge;
        $limit_height = $portrait ? $max_edge : $max_short;
        $resize = $width > $limit_width || $height > $limit_height;
        if ( $resize ) {
            $result = $editor->resize( $limit_width, $limit_height, false );
            if ( is_wp_error( $result ) ) {
                return $upload;
            }
        }
        $editor->set_quality( (int) $options['quality'] );

        $directory = dirname( $file );
        $stem = pathinfo( $file, PATHINFO_FILENAME );
        $extension = 'image/webp' === $output_mime ? 'webp' : strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $filename  = $stem . '.' . $extension;
        if ( $file === $directory . DIRECTORY_SEPARATOR . $filename ) {
            $filename = $stem . '-optimized.' . $extension;
        }
        $filename = wp_unique_filename( $directory, $filename );
        $dest = $directory . DIRECTORY_SEPARATOR . $filename;
        $result = $editor->save( $dest, $output_mime );
        if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['path'] )
            || ! is_file( $dest ) || ( $result['mime-type'] ?? '' ) !== $output_mime
            || $result['path'] !== $dest ) {
            self::remove_failed_output( $dest, $file );
            return $upload;
        }
        $out_size = @getimagesize( $dest );
        $new_bytes = filesize( $dest );
        $old_bytes = filesize( $file );
        if ( ! $out_size || ! $new_bytes || ! $old_bytes
            || $out_size[0] > $limit_width || $out_size[1] > $limit_height
            || ( ! $resize && $new_bytes >= $old_bytes ) ) {
            self::remove_failed_output( $dest, $file );
            return $upload;
        }
        if ( ! @unlink( $file ) ) {
            self::remove_failed_output( $dest, $file );
            return $upload;
        }
        $upload['file'] = $dest;
        $upload['url'] = substr( $upload['url'], 0, strrpos( $upload['url'], '/' ) + 1 ) . rawurlencode( $filename );
        $upload['type'] = $output_mime;
        return $upload;
    }

    private static function remove_failed_output( $dest, $source ) {
        if ( $dest !== $source && is_file( $dest ) ) {
            @unlink( $dest );
        }
    }

    /** Be conservative about transparency without decoding massive PNGs. */
    public static function png_may_be_transparent( $path ) {
        $fh = @fopen( $path, 'rb' );
        if ( ! $fh ) {
            return true;
        }
        $signature = fread( $fh, 8 );
        if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
            fclose( $fh );
            return true;
        }
        $chunk_len = fread( $fh, 4 );
        $chunk_type = fread( $fh, 4 );
        $ihdr = fread( $fh, 13 );
        if ( strlen( $chunk_len ) !== 4 || 'IHDR' !== $chunk_type || strlen( $ihdr ) !== 13
            || unpack( 'N', $chunk_len )[1] !== 13 ) {
            fclose( $fh );
            return true;
        }
        $color_type = ord( $ihdr[9] );
        if ( in_array( $color_type, array( 4, 6 ), true ) ) {
            fclose( $fh );
            return true;
        }
        if ( ! in_array( $color_type, array( 0, 2, 3 ), true ) ) {
            fclose( $fh );
            return true;
        }
        fseek( $fh, 4, SEEK_CUR );
        for ( $n = 0; $n < 64; $n++ ) {
            $header = fread( $fh, 8 );
            if ( strlen( $header ) !== 8 ) {
                fclose( $fh );
                return true;
            }
            $length = unpack( 'N', substr( $header, 0, 4 ) )[1];
            $type = substr( $header, 4, 4 );
            if ( 'tRNS' === $type ) {
                fclose( $fh );
                return true;
            }
            if ( 'IDAT' === $type ) {
                fclose( $fh );
                return false;
            }
            if ( $length > 1024 * 1024 || fseek( $fh, $length + 4, SEEK_CUR ) !== 0 ) {
                fclose( $fh );
                return true;
            }
        }
        fclose( $fh );
        return true;
    }
}
