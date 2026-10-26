<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BST_Admin {
    private static $menu_entries = array();

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'capture_menu_entries' ), 900 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_post_bst_save_settings', array( __CLASS__, 'save' ) );
        add_action( 'wp_ajax_bst_purge_comments', array( __CLASS__, 'purge_comments' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( BST_FILE ), array( __CLASS__, 'settings_link' ) );
    }

    public static function settings_link( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=bst-toolkit' ) ) . '">Nastavení</a>' );
        return $links;
    }

    public static function register_page() {
        add_menu_page( 'Site Toolkit for Bricks', 'Site Toolkit', 'manage_options', 'bst-toolkit', array( __CLASS__, 'render_page' ), BST_URL . 'assets/ew-logo-48.png', 60 );
    }

    public static function capture_menu_entries() {
        global $menu;
        if ( ! is_array( $menu ) ) {
            return;
        }
        foreach ( $menu as $entry ) {
            if ( empty( $entry[2] ) || 'bst-toolkit' === $entry[2] ) {
                continue;
            }
            $slug  = (string) $entry[2];
            $title = trim( wp_strip_all_tags( (string) ( $entry[0] ?? $slug ) ) );
            if ( '' !== $title ) {
                self::$menu_entries[ $slug ] = $title;
            }
        }
    }

    public static function enqueue( $hook ) {
        if ( 'toplevel_page_bst-toolkit' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'bst-admin', BST_URL . 'assets/admin.css', array(), BST_VERSION );
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_media();
        wp_enqueue_script( 'bst-admin', BST_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), BST_VERSION, true );
        wp_localize_script( 'bst-admin', 'BSTAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'purgeNonce' => wp_create_nonce( 'bst_purge_comments' ),
        ) );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Nedostatečná oprávnění.', 'site-toolkit-for-bricks' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'bst_save_settings', 'bst_nonce' );
        $tab = sanitize_key( wp_unslash( $_POST['bst_tab'] ?? '' ) );
        if ( ! in_array( $tab, array( 'data', 'login', 'menu', 'comments', 'revisions', 'media' ), true ) ) {
            wp_die( 'Neplatná sekce.', '', array( 'response' => 400 ) );
        }
        $raw = isset( $_POST['bst'] ) && is_array( $_POST['bst'] ) ? $_POST['bst'] : array();
        if ( 'login' === $tab && ! empty( $raw['login']['custom_url_enabled'] ) ) {
            $slug = sanitize_title( wp_unslash( $raw['login']['slug'] ?? '' ) );
            $error = '';
            if ( is_multisite() ) {
                $error = 'multisite';
            } elseif ( BST_Login_URL::conflicting_plugin() ) {
                $error = 'conflict';
            } elseif ( ! BST_Login_URL::valid_slug( $slug ) || BST_Login_URL::slug_conflicts_with_content( $slug ) ) {
                $error = 'slug';
            }
            if ( $error ) {
                wp_safe_redirect( add_query_arg( array( 'page' => 'bst-toolkit', 'tab' => 'login', 'error' => $error ), admin_url( 'admin.php' ) ) );
                exit;
            }
        }
        update_option( BST_Settings::OPTION, BST_Settings::sanitize_module( $tab, $raw ), false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'bst-toolkit', 'tab' => $tab, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Deletes every comment type and comment metadata through WordPress APIs in short AJAX batches. */
    public static function purge_comments() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Nedostatečná oprávnění.' ), 403 );
        }
        check_ajax_referer( 'bst_purge_comments', 'nonce' );
        $scope = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
        if ( ! in_array( $scope, array( 'all', 'spam' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Neplatný typ mazání.' ), 400 );
        }
        global $wpdb;
        // Table names come exclusively from WordPress; only the fixed values above reach this query.
        $where = 'spam' === $scope ? " WHERE comment_approved = 'spam'" : '';
        $ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments}{$where} ORDER BY comment_ID ASC LIMIT 75" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( wp_delete_comment( (int) $id, true ) ) {
                ++$deleted;
            }
        }
        $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}{$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $ids && 0 === $deleted ) {
            wp_send_json_error( array( 'message' => 'Mazání selhalo. Zkontrolujte další pluginy.' ), 500 );
        }
        wp_send_json_success( array( 'deleted' => $deleted, 'remaining' => $remaining ) );
    }

    private static function tabs() {
        return array(
            'data'      => array( 'label' => 'Dynamická data', 'icon' => 'database' ),
            'login'     => array( 'label' => 'Přihlášení', 'icon' => 'admin-users' ),
            'menu'      => array( 'label' => 'Admin menu', 'icon' => 'menu' ),
            'comments'  => array( 'label' => 'Komentáře', 'icon' => 'admin-comments' ),
            'revisions' => array( 'label' => 'Revize', 'icon' => 'backup' ),
            'media' => array( 'label' => 'Media Optimizer', 'icon' => 'format-image' ),
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $tabs     = self::tabs();
        $tab      = sanitize_key( wp_unslash( $_GET['tab'] ?? 'data' ) );
        $tab      = isset( $tabs[ $tab ] ) ? $tab : 'data';
        $settings = BST_Settings::get();
        $enabled  = ! empty( $settings['modules'][ $tab ] );
        ?>
        <div class="wrap bst-wrap">
          <header class="bst-top">
            <div class="bst-mark"><img src="<?php echo esc_url( BST_URL . 'assets/ew-logo-48.png' ); ?>" alt="EW"></div>
            <div class="bst-heading"><span class="bst-kicker">WORDPRESS &amp; BRICKS</span><h1>Site Toolkit</h1><p>Vše potřebné pro správu klientského webu na jednom místě.</p></div>
            <span class="bst-version">verze <?php echo esc_html( BST_VERSION ); ?></span>
          </header>
          <?php if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) : ?>
            <div class="notice notice-success is-dismissible bst-notice"><p>Nastavení bylo úspěšně uloženo.</p></div>
          <?php endif; ?>
          <?php if ( 'login' === $tab && ! empty( $_GET['error'] ) ) : ?>
            <?php $errors = array( 'slug' => 'Zadaná adresa přihlášení je neplatná, vyhrazená nebo už ji používá existující stránka. Nastavení nebylo změněno.', 'conflict' => 'Je aktivní WPS Hide Login. Nejprve vypněte jeho funkci, aby se adresy přihlášení nekřížily.', 'multisite' => 'Změna URL přihlášení zatím není podporována na WordPress Multisite.' ); ?>
            <?php if ( isset( $errors[ $_GET['error'] ] ) ) : ?><div class="notice notice-error bst-notice"><p><?php echo esc_html( $errors[ $_GET['error'] ] ); ?></p></div><?php endif; ?>
          <?php endif; ?>
          <?php if ( 'data' === $tab && ! defined( 'BRICKS_VERSION' ) && ! class_exists( '\\Bricks\\Bricks' ) ) : ?>
            <div class="notice notice-info bst-notice"><p>Bricks není právě detekován. Data lze používat přes shortcody; dynamické tagy se objeví po aktivaci Bricks.</p></div>
          <?php endif; ?>
          <div class="bst-layout">
            <nav class="bst-nav" aria-label="Moduly Site Toolkit">
              <div class="bst-nav-caption">MODULY</div>
              <?php foreach ( $tabs as $name => $info ) : ?>
                <a class="bst-nav-link <?php echo $tab === $name ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'bst-toolkit', 'tab' => $name ), admin_url( 'admin.php' ) ) ); ?>">
                  <span class="dashicons dashicons-<?php echo esc_attr( $info['icon'] ); ?>"></span>
                  <span><?php echo esc_html( $info['label'] ); ?></span>
                  <i class="bst-dot <?php echo ! empty( $settings['modules'][ $name ] ) ? 'is-on' : ''; ?>"></i>
                </a>
              <?php endforeach; ?>
              <div class="bst-nav-note"><span class="dashicons dashicons-shield"></span> Nastavení mohou měnit pouze administrátoři.</div>
            </nav>
            <main class="bst-main">
              <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'bst_save_settings', 'bst_nonce' ); ?>
                <input type="hidden" name="action" value="bst_save_settings">
                <input type="hidden" name="bst_tab" value="<?php echo esc_attr( $tab ); ?>">
                <div class="bst-card bst-module-header">
                  <div>
                    <div class="bst-section-kicker">NASTAVENÍ MODULU</div>
                    <h2><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h2>
                    <p><?php echo esc_html( self::description( $tab ) ); ?></p>
                  </div>
                  <label class="bst-switch" title="Zapnout nebo vypnout modul">
                    <input type="checkbox" name="bst[enabled]" value="1" <?php checked( $enabled ); ?>>
                    <span class="bst-switch-slider"></span>
                    <span class="screen-reader-text">Zapnout modul</span>
                  </label>
                </div>
                <?php
                switch ( $tab ) {
                    case 'data': self::section_data( $settings ); break;
                    case 'login': self::section_login( $settings ); break;
                    case 'menu': self::section_menu( $settings ); break;
                    case 'comments': self::section_comments(); break;
                    case 'revisions': self::section_revisions( $settings ); break;
                    case 'media': self::section_media( $settings ); break;
                }
                ?>
                <div class="bst-savebar"><span><span class="dashicons dashicons-saved"></span> Změny se projeví po uložení.</span><?php submit_button( 'Uložit nastavení', 'primary bst-submit', 'submit', false ); ?></div>
              </form>
              <?php if ( 'comments' === $tab ) : self::section_comment_danger_zone(); endif; ?>
            </main>
          </div>
          <footer class="bst-footer">Site Toolkit for Bricks · Bez závislosti na ACF · Nastavení zůstává uloženo i po deaktivaci pluginu</footer>
        </div>
        <?php
    }

    private static function description( $tab ) {
        $texts = array(
            'data'      => 'Jednou upravíte kontaktní údaje a následně je použijete kdekoliv ve webu.',
            'login'     => 'Přizpůsobte vzhled přihlášení a případně nastavte vlastní přihlašovací adresu.',
            'menu'      => 'Skryjte nepotřebné hlavní položky v administraci WordPressu.',
            'comments'  => 'Vypněte komentáře na celém webu. Uložené komentáře můžete samostatně trvale smazat.',
            'revisions' => 'Určete maximální počet uchovávaných revizí obsahu.',
            'media' => 'Optimalizace nových fotografií, WebP a přejmenování.',
        );
        return $texts[ $tab ];
    }

    private static function row_input( $label, $name, $value, $placeholder = '', $type = 'text', $help = '' ) {
        ?>
        <label class="bst-control"><span class="bst-label"><?php echo esc_html( $label ); ?></span>
          <input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
          <?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
        </label>
        <?php
    }

    private static function media_input( $label, $name, $id, $hint = '' ) {
        $url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
        ?>
        <div class="bst-control"><span class="bst-label"><?php echo esc_html( $label ); ?></span>
          <div class="bst-media-control">
            <div class="bst-media-preview"><img <?php if ( ! $url ) : ?>hidden<?php endif; ?> src="<?php echo esc_url( $url ); ?>" alt="Náhled obrázku"><span class="dashicons dashicons-format-image"></span></div>
            <div><input class="bst-media-id" type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( absint( $id ) ); ?>">
              <button type="button" class="button bst-media-choose">Vybrat obrázek</button>
              <button type="button" class="button-link bst-media-clear">Odebrat</button>
              <?php if ( $hint ) : ?><small><?php echo esc_html( $hint ); ?></small><?php endif; ?>
            </div>
          </div>
        </div>
        <?php
    }

    private static function section_data( $settings ) {
        $data = $settings['data'];
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Základní údaje</h3><p>Využitelné jako nativní dynamická data v Bricks Builderu.</p></div>
          <?php self::media_input( 'Logo webu', 'bst[data][logo]', $data['logo'], 'Když není vybráno, použije se logo ze vzhledu WordPressu.' ); ?>
          <div class="bst-form-grid">
            <?php
            self::row_input( 'Název firmy', 'bst[data][company]', $data['company'], 'Název firmy nebo projektu' );
            self::row_input( 'E-mail', 'bst[data][email]', $data['email'], 'info@firma.cz', 'email' );
            self::row_input( 'Telefon', 'bst[data][phone]', $data['phone'], '+420 123 456 789', 'tel' );
            ?>
            <label class="bst-control bst-wide"><span class="bst-label">Adresa</span><textarea name="bst[data][address]" rows="2" placeholder="Ulice, město, PSČ"><?php echo esc_textarea( $data['address'] ); ?></textarea></label>
          </div>
        </section>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Dynamické tagy</h3><p>V Bricks klikněte na ikonu dynamických dat a otevřete skupinu „Site Toolkit for Bricks“.</p></div>
          <div class="bst-tags">
            <?php foreach ( BST_Bricks::definitions() as $key => $definition ) : ?>
              <div class="bst-tag-item"><span><?php echo esc_html( $definition['label'] ); ?></span><code><?php echo esc_html( '{bst_' . $key . '}' ); ?></code><button type="button" class="bst-copy" data-bst-copy="<?php echo esc_attr( '{bst_' . $key . '}' ); ?>" title="Kopírovat tag"><span class="dashicons dashicons-admin-page"></span></button></div>
            <?php endforeach; ?>
          </div>
          <p class="bst-help">Příklad bez Bricks: <code>[bst_field key="phone"]</code>. Pro tlačítka použijte <code>{bst_phone_url}</code> nebo <code>{bst_email_url}</code> v URL odkazu. Logo lze vložit jako zdroj prvku Image.</p>
        </section>
        <section class="bst-card">
          <div class="bst-card-title bst-card-title-inline"><div><h3>Vlastní dynamická pole</h3><p>Přidejte vlastní hodnoty. Klíč zadávejte malými písmeny bez diakritiky.</p></div><button type="button" class="button bst-add-field"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><span>Přidat pole</span></button></div>
          <div id="bst-fields" class="bst-fields">
            <?php foreach ( $settings['fields'] as $i => $field ) : self::field_row( $i, $field ); endforeach; ?>
          </div>
          <div class="bst-fields-empty" <?php if ( ! empty( $settings['fields'] ) ) : ?>hidden<?php endif; ?>>Zatím nejsou přidaná žádná vlastní pole.</div>
          <template id="bst-field-template"><?php self::field_row( '__INDEX__', array( 'key' => '', 'label' => '', 'type' => 'text', 'value' => '' ) ); ?></template>
          <p class="bst-help">Příklad: název „Cena za hodinu“, klíč <code>cena_hodina</code> → tag <code>{bst_cena_hodina}</code>. Pole typu obrázek vrací Bricks prvku Image ID z knihovny médií.</p>
        </section>
        <?php
    }

    private static function field_row( $i, $field ) {
        $name = 'bst[fields][' . $i . ']';
        ?>
        <div class="bst-field-row">
          <label class="bst-control"><span class="bst-label">Název pole</span><input required type="text" name="<?php echo esc_attr( $name . '[label]' ); ?>" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="Cena za hodinu"></label>
          <label class="bst-control"><span class="bst-label">Klíč</span><input required pattern="[a-z][a-z0-9_]*" title="Malá písmena a-z, číslice a podtržítko; začíná písmenem" type="text" name="<?php echo esc_attr( $name . '[key]' ); ?>" value="<?php echo esc_attr( $field['key'] ); ?>" placeholder="cena_hodina"></label>
          <label class="bst-control"><span class="bst-label">Typ</span><select class="bst-field-type" name="<?php echo esc_attr( $name . '[type]' ); ?>">
            <?php foreach ( array( 'text' => 'Text', 'textarea' => 'Víceřádkový text', 'email' => 'E-mail', 'tel' => 'Telefon', 'url' => 'URL', 'number' => 'Číslo', 'image' => 'Obrázek' ) as $type => $title ) : ?>
              <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field['type'], $type ); ?>><?php echo esc_html( $title ); ?></option>
            <?php endforeach; ?>
          </select></label>
          <label class="bst-control bst-field-value-label"><span class="bst-label">Hodnota <?php if ( 'image' === $field['type'] ) : ?><span>(ID obrázku)</span><?php endif; ?></span><textarea class="bst-field-value" name="<?php echo esc_attr( $name . '[value]' ); ?>" rows="2" placeholder="Hodnota pole"><?php echo esc_textarea( $field['value'] ); ?></textarea></label>
          <div class="bst-field-buttons"><button type="button" class="button bst-field-image" <?php if ( 'image' !== $field['type'] ) : ?>hidden<?php endif; ?>>Vybrat obrázek</button><button type="button" class="button-link-delete bst-remove-field">Odstranit pole</button></div>
        </div>
        <?php
    }

    private static function section_login( $settings ) {
        $login = $settings['login'];
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Vzhled přihlašovací stránky</h3><p>Vzhled můžete zapnout hlavním přepínačem nahoře. Vlastní URL přihlášení je samostatná funkce níže.</p></div>
          <?php self::media_input( 'Vlastní logo přihlášení', 'bst[login][logo]', $login['logo'], 'Nevyplněné logo = logo ze sekce Dynamická data nebo výchozí WordPress.' ); ?>
          <?php self::media_input( 'Obrázek pozadí', 'bst[login][background_id]', $login['background_id'] ); ?>
          <div class="bst-form-grid">
            <?php foreach ( array( 'background' => 'Barva pozadí', 'panel' => 'Barva formuláře', 'button' => 'Barva tlačítka', 'links' => 'Barva odkazů' ) as $key => $label ) : ?>
              <div class="bst-control"><label class="bst-label" for="bst-color-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label><input id="bst-color-<?php echo esc_attr( $key ); ?>" class="bst-color" type="text" name="<?php echo esc_attr( 'bst[login][' . $key . ']' ); ?>" value="<?php echo esc_attr( $login[ $key ] ); ?>" data-default-color="<?php echo esc_attr( $login[ $key ] ); ?>"></div>
            <?php endforeach; ?>
            <?php self::row_input( 'Šířka loga (px)', 'bst[login][logo_width]', $login['logo_width'], '', 'number', '60–400 px' ); ?>
            <?php self::row_input( 'Odkaz po kliknutí na logo', 'bst[login][logo_url]', $login['logo_url'], home_url( '/' ), 'url', 'Pokud zůstane prázdný, použije se úvodní stránka webu.' ); ?>
          </div>
          <p class="bst-help">Náhled: <a href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener noreferrer">Otevřít přihlašovací stránku ↗</a></p>
        </section>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Vlastní URL přihlášení</h3><p>Podobně jako WPS Hide Login: standardní <code>/wp-login.php</code> a nepřihlášený přístup do <code>/wp-admin/</code> vrátí 404. Žádné soubory WordPressu se nepřejmenovávají.</p></div>
          <label class="bst-checkbox-line"><input type="checkbox" name="bst[login][custom_url_enabled]" value="1" <?php checked( ! empty( $login['custom_url_enabled'] ) ); ?> <?php disabled( is_multisite() ); ?>> <span>Zapnout vlastní adresu přihlášení (nezávisle na úpravě vzhledu)</span></label>
          <label class="bst-control"><span class="bst-label">Adresa přihlášení – koncová část URL</span><input type="text" name="bst[login][slug]" value="<?php echo esc_attr( $login['slug'] ?? 'moje-prihlaseni' ); ?>" placeholder="moje-prihlaseni" pattern="[a-z0-9][a-z0-9-]{1,59}" autocomplete="off"><small>Pouze malá písmena bez diakritiky, číslice a pomlčky. Např. moje-prihlaseni.</small></label>
          <?php if ( BST_Login_URL::active() ) : ?>
            <div class="bst-login-url"><strong>Aktuální přihlašovací adresa:</strong> <a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( BST_Login_URL::url() ); ?>"><?php echo esc_html( BST_Login_URL::url() ); ?></a> <button type="button" class="button bst-copy" data-bst-copy="<?php echo esc_attr( BST_Login_URL::url() ); ?>">Kopírovat</button></div>
          <?php endif; ?>
          <?php if ( BST_Login_URL::conflicting_plugin() ) : ?><div class="bst-alert"><span class="dashicons dashicons-warning"></span><p><strong>Konflikt:</strong> Na webu je aktivní WPS Hide Login. Dvě řešení změny URL přihlášení nelze používat současně.</p></div><?php endif; ?>
          <?php if ( is_multisite() ) : ?><div class="bst-alert"><span class="dashicons dashicons-warning"></span><p>Funkce vlastní URL je pro bezpečnost vypnutá na multisite instalacích.</p></div><?php endif; ?>
          <div class="bst-alert"><span class="dashicons dashicons-warning"></span><p><strong>Důležité:</strong> Novou URL si uložte ještě před odhlášením a otestujte v anonymním okně. Při ztrátě přístupu deaktivujte tento plugin přes FTP nebo správce souborů hostingu; obnoví se standardní wp-login.php. Stránku vylučte z cache. Nejde o náhradu silného hesla, 2FA ani ochrany proti bruteforce útokům.</p></div>
        </section>
        <?php
    }

    private static function section_menu( $settings ) {
        $items = self::$menu_entries;
        $defaults = array( 'index.php' => 'Nástěnka', 'edit.php' => 'Příspěvky', 'upload.php' => 'Média', 'edit.php?post_type=page' => 'Stránky', 'edit-comments.php' => 'Komentáře', 'themes.php' => 'Vzhled', 'plugins.php' => 'Pluginy', 'users.php' => 'Uživatelé', 'tools.php' => 'Nástroje', 'options-general.php' => 'Nastavení' );
        $items = array_merge( $defaults, $items );
        foreach ( $settings['menu_hidden'] as $slug ) {
            if ( ! isset( $items[ $slug ] ) ) {
                $items[ $slug ] = $slug;
            }
        }
        unset( $items['bst-toolkit'] );
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Skrýt hlavní položky menu</h3><p>Zaškrtnuté položky zmizí z levého menu administrace pro všechny uživatele včetně administrátorů. Site Toolkit zůstane vždy viditelný.</p></div>
          <div class="bst-menu-grid">
            <?php foreach ( $items as $slug => $title ) : ?>
              <label class="bst-menu-item"><input type="checkbox" name="bst[menu_hidden][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $settings['menu_hidden'], true ) ); ?>><span><?php echo esc_html( $title ); ?><small><?php echo esc_html( $slug ); ?></small></span></label>
            <?php endforeach; ?>
          </div>
          <div class="bst-alert"><span class="dashicons dashicons-info"></span><p><strong>Pozor:</strong> Skrytí položek pouze upravuje vzhled menu, neodebírá uživatelům oprávnění. K omezení přístupu použijte role a capabilities WordPressu.</p></div>
        </section>
        <?php
    }

    private static function section_comments() {
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Co provede vypnutí komentářů?</h3><p>Po zapnutí modulu se následující změny projeví na celém webu.</p></div>
          <ul class="bst-checklist"><li>Zakáže nové komentáře a pingbacky u příspěvků, stránek i dalších typů obsahu.</li><li>Skryje hlavní položku Komentáře, komentářovou ikonu v horní liště a sloupce s komentáři.</li><li>Odstraní komentářové formuláře a seznamy komentářů ve standardních šablonách.</li><li>Zablokuje WordPress REST API endpointy pro komentáře.</li><li>Skryje nastavení Diskuze a zabrání otevření administračních obrazovek komentářů.</li></ul>
          <div class="bst-alert"><span class="dashicons dashicons-warning"></span><p><strong>Vypnutí samo o sobě nemaže uložené komentáře.</strong> Pokud chcete smazat i historické záznamy, použijte níže samostatnou funkci. Některé systémy třetích stran mohou vyžadovat dodatečné nastavení.</p></div>
        </section>
        <?php
    }

    private static function section_comment_danger_zone() {
        global $wpdb;
        $all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $spam = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ?>
        <section class="bst-card bst-danger-card">
          <div class="bst-card-title"><h3>Trvalé odstranění komentářů</h3><p>Smaže komentáře z databáze včetně jejich metadat. Nejde vrátit zpět. Před smazáním doporučujeme zálohu databáze.</p></div>
          <div class="bst-purge-grid">
            <div class="bst-purge-item"><strong>Spam komentáře</strong><p>Nalezeno: <b><?php echo esc_html( number_format_i18n( $spam ) ); ?></b>. Smaže pouze spamové komentáře, bez ohledu na typ.</p><button type="button" class="button bst-purge" data-bst-purge="spam" <?php disabled( 0 === $spam ); ?>>Smazat veškerý spam</button></div>
            <div class="bst-purge-item bst-purge-danger"><strong>Všechny komentáře</strong><p>Nalezeno: <b><?php echo esc_html( number_format_i18n( $all ) ); ?></b>. Včetně schválených, čekajících, spamu, koše, pingbacků a hodnocení WooCommerce.</p><button type="button" class="button bst-purge" data-bst-purge="all" <?php disabled( 0 === $all ); ?>>Nenávratně smazat vše</button></div>
          </div>
          <p class="bst-purge-status" role="status" aria-live="polite"></p>
        </section>
        <?php
    }

    private static function section_revisions( $settings ) {
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Limit revizí</h3><p>Maximální počet uložených revizí pro každý příspěvek nebo stránku, které revize podporují.</p></div>
          <label class="bst-control"><span class="bst-label">Počet revizí (0–200)</span><input type="number" min="0" max="200" step="1" name="bst[revisions_limit]" value="<?php echo esc_attr( $settings['revisions_limit'] ); ?>"><small>0 = revize vypnout; 1 až 200 = omezit na zadaný počet. Doporučené výchozí nastavení: 10.</small></label>
          <div class="bst-alert"><span class="dashicons dashicons-info"></span><p>Limit se použije při ukládání obsahu. Nejde o okamžité hromadné smazání historických revizí a nemění interval automatického ukládání.</p></div>
        </section>
        <?php
    }
    private static function section_media($settings) {
        $m=$settings['media'];
        $available=function_exists('wp_image_editor_supports') && wp_image_editor_supports(array('mime_type'=>'image/webp'));
        ?>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Optimalizace nových fotografií</h3><p>Po zapnutí modulu optimalizuje běžná nová nahrání. Existující obrázky zůstanou beze změny.</p></div>
          <div class="bst-alert"><span class="dashicons dashicons-info"></span><p>WebP na tomto serveru: <strong><?php echo $available ? 'Podporováno' : 'Nepodporováno – použije se původní formát, pokud lze zmenšovat'; ?></strong>. PNG s průhledností doporučujeme ponechat beze změny.</p></div>
          <div class="bst-form-grid">
            <label class="bst-control"><span class="bst-label">Maximální delší strana (px)</span><input type="number" name="bst[media][max_edge]" min="800" max="4096" value="<?php echo esc_attr($m['max_edge']); ?>"><small>Výchozí 1920 px</small></label>
            <label class="bst-control"><span class="bst-label">Maximální kratší strana (px)</span><input type="number" name="bst[media][max_short_edge]" min="480" max="4096" value="<?php echo esc_attr($m['max_short_edge']); ?>"><small>Výchozí 1080 px. Zachová proporce, bez ořezu.</small></label>
            <label class="bst-control"><span class="bst-label">Kvalita (65–100 %)</span><input type="number" name="bst[media][quality]" min="65" max="100" value="<?php echo esc_attr($m['quality']); ?>"><small>Doporučeno 85 %. Komprese může být ztrátová.</small></label>
          </div>
          <label class="bst-checkbox-line"><input type="checkbox" name="bst[media][webp]" value="1" <?php checked($m['webp']); ?>> <span>Převádět JPG a vhodné PNG na WebP</span></label>
          <label class="bst-control"><span class="bst-label">PNG obrázky</span><select name="bst[media][png_policy]">
          <?php foreach(array('opaque'=>'Převádět pouze neprůhledné PNG (doporučeno)','skip'=>'PNG neupravovat','all'=>'Povolit i průhledné PNG') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($m['png_policy'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
          </select></label>
        </section>
        <section class="bst-card">
          <div class="bst-card-title"><h3>Automatické názvy souborů</h3><p>Z názvů typu IMG_1234.jpg vytvoří například foto-20261026-a1b2c3d4.webp. Smysluplné názvy ponechá.</p></div>
          <label class="bst-control"><span class="bst-label">Přejmenování</span><select name="bst[media][rename_mode]">
          <?php foreach(array('camera'=>'Pouze názvy fotoaparátů a náhodné názvy','all'=>'Přejmenovat všechny nové fotografie','original'=>'Ponechat původní název') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($m['rename_mode'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?>
          </select></label>
          <label class="bst-control"><span class="bst-label">Prefix</span><input type="text" name="bst[media][filename_prefix]" maxlength="40" value="<?php echo esc_attr($m['filename_prefix']); ?>"><small>Název není automaticky SEO popisek. ALT text je potřeba nastavit samostatně.</small></label>
          <div class="bst-alert"><span class="dashicons dashicons-warning"></span><p>Na existující média se nic neaplikuje. GIF, SVG, PDF, AVIF a importy jsou vynechány. Pokud se konverze nezdaří, zůstane originál. Před nasazením klientům otestujte nahrání na stagingu.</p></div>
        </section>
        <?php
    }
}
