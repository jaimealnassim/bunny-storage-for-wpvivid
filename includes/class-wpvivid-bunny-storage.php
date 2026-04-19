<?php
/**
 * WPvivid Bunny Storage provider — v1.3.3
 *
 * Three connection methods:
 *   ftp  – PHP ftp_* functions, port 21, passive mode
 *   api  – Bunny Storage REST API  PUT/GET/DELETE  AccessKey header
 *   s3   – Bunny S3-compatible API (preview), AWS Signature V4, no SDK
 *
 * Bunny FTP / API hostnames:
 *   de  → storage.bunnycdn.com          ny  → ny.storage.bunnycdn.com
 *   la  → la.storage.bunnycdn.com       sg  → sg.storage.bunnycdn.com
 *   syd → syd.storage.bunnycdn.com      uk  → uk.storage.bunnycdn.com
 *   se  → se.storage.bunnycdn.com       br  → br.storage.bunnycdn.com
 *   jh  → jh.storage.bunnycdn.com
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WPVIVID_REMOTE_BUNNY' ) ) {
    define( 'WPVIVID_REMOTE_BUNNY', 'bunny_storage' );
}

class WPvivid_Bunny_Storage extends WPvivid_Remote {

    private $options  = array();
    private $callback = '';

    // ── Region table ──────────────────────────────────────────────────────────

    public static function region_map() {
        static $map = null;
        if ( null === $map ) {
            $map = array(
                'de'  => array( 'label' => 'Falkenstein (DE)',    'host' => 'storage.bunnycdn.com' ),
                'ny'  => array( 'label' => 'New York (NY)',       'host' => 'ny.storage.bunnycdn.com' ),
                'la'  => array( 'label' => 'Los Angeles (LA)',    'host' => 'la.storage.bunnycdn.com' ),
                'sg'  => array( 'label' => 'Singapore (SG)',      'host' => 'sg.storage.bunnycdn.com' ),
                'syd' => array( 'label' => 'Sydney (SY)',         'host' => 'syd.storage.bunnycdn.com' ),
                'uk'  => array( 'label' => 'London (UK)',         'host' => 'uk.storage.bunnycdn.com' ),
                'se'  => array( 'label' => 'Stockholm (SE)',      'host' => 'se.storage.bunnycdn.com' ),
                'br'  => array( 'label' => 'Sao Paulo (BR)',      'host' => 'br.storage.bunnycdn.com' ),
                'jh'  => array( 'label' => 'Johannesburg (JHB)', 'host' => 'jh.storage.bunnycdn.com' ),
            );
        }
        return $map;
    }

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct( $options = array() ) {
        if ( empty( $options ) ) {
            // Guard against double-registration: WPvivid's load_hooks() instantiates
            // every registered class, and our plugins_loaded callback does the same.
            // Without this flag, every hook fires twice and the tab appears twice.
            static $hooks_registered = false;
            if ( $hooks_registered ) { return; }
            $hooks_registered = true;

            add_action( 'wpvivid_add_storage_tab',        array( $this, 'add_storage_tab'  ), 20 );
            add_action( 'wpvivid_add_storage_page',       array( $this, 'add_storage_page' ), 20 );
            add_action( 'wpvivid_edit_remote_page',       array( $this, 'edit_storage_page'), 20 );
            add_filter( 'wpvivid_remote_pic',             array( $this, 'remote_pic'       ), 11 );
            add_filter( 'wpvivid_get_out_of_date_remote', array( $this, 'get_out_of_date'  ), 10, 2 );
            add_filter( 'wpvivid_storage_provider_tran',  array( $this, 'provider_label'   ), 10 );
        } else {
            $this->options = $options;
        }
    }

    // ── WPvivid filter callbacks ──────────────────────────────────────────────

    public function remote_pic( $remote ) {
        // WPvivid renders storage icons in two places, both using WPVIVID_PLUGIN_URL as base:
        //
        //   A) Schedule / "Send Backup to Remote Storage" row:
        //        $url = apply_filters('wpvivid_get_wpvivid_pro_url', WPVIVID_PLUGIN_URL, $key);
        //        src  = esc_url( $url . $pic )
        //      → We do NOT override this filter. $url stays as WPVIVID_PLUGIN_URL (no trailing slash).
        //
        //   B) Backup history list (wpvivid_add_backup_list):
        //        src  = esc_url( WPVIVID_PLUGIN_URL . $pic )
        //      → Same base URL, same result.
        //
        // By using WPVIVID_PLUGIN_URL (no trailing slash) as the base in both cases, the
        // '/../bunny-storage-for-wpvivid/' prefix on $pic resolves correctly without
        // producing a double-slash sequence that some Nginx configs normalise incorrectly.
        //
        //   WPVIVID_PLUGIN_URL . '/../bunny-storage-for-wpvivid/assets/bunny-icon.png'
        //   → https://site.com/wp-content/plugins/wpvivid-backuprestore/../bunny-storage-for-wpvivid/assets/bunny-icon.png
        //   → resolves to: https://site.com/wp-content/plugins/bunny-storage-for-wpvivid/assets/bunny-icon.png  ✓
        $our_dir  = basename( rtrim( NAHNU_BUNNY_URL, '/' ) ); // 'bunny-storage-for-wpvivid'
        $rel_base = '/../' . $our_dir . '/assets/';

        $remote[ WPVIVID_REMOTE_BUNNY ] = array(
            'default_pic'  => $rel_base . 'bunny-icon-gray.png',
            'selected_pic' => $rel_base . 'bunny-icon.png',
            'title'        => 'Bunny Storage',
        );
        return $remote;
    }

    public function provider_label( $type ) {
        return ( $type === WPVIVID_REMOTE_BUNNY ) ? 'Bunny Storage' : $type;
    }

    public function get_out_of_date( $out_of_date, $remote ) {
        if ( isset( $remote['type'] ) && $remote['type'] === WPVIVID_REMOTE_BUNNY ) {
            $out_of_date = isset( $remote['path'] ) ? $remote['path'] : '';
        }
        return $out_of_date;
    }

    // =========================================================================
    // Admin UI
    // =========================================================================

    // ── Tab icon (left-hand provider selector) ────────────────────────────────

    public function add_storage_tab() {
        ?>
        <div class="storage-providers" remote_type="<?php echo esc_attr( WPVIVID_REMOTE_BUNNY ); ?>"
             onclick="select_remote_storage(event, 'storage_account_bunny');">
            <img src="<?php echo esc_url( NAHNU_BUNNY_URL . 'assets/bunny-icon.png' ); ?>"
                 style="vertical-align:middle;width:20px;height:20px;" />
            <?php esc_html_e( 'Bunny Storage', 'bunny-storage-for-wpvivid' ); ?>
        </div>
        <?php
        $this->print_js();   // Print once — guarded by static flag.
    }

    // ── Add storage form ──────────────────────────────────────────────────────

    public function add_storage_page() {
        $pfx = WPVIVID_REMOTE_BUNNY; // 'bunny_storage'
        ?>
        <div id="storage_account_bunny" class="storage-account-page" style="display:none;">
            <div style="padding:0 10px 10px 0;">
                <strong><?php esc_html_e( 'Connect a Bunny.net Storage Zone', 'bunny-storage-for-wpvivid' ); ?></strong>
            </div>
            <table class="wp-list-table widefat plugins nahnu-bunny-form" style="width:100%;">
                <tbody>
                <form>
                <?php $this->render_form_fields( $pfx ); ?>
                </form>
                <tr>
                    <td class="plugin-title column-primary">
                        <div class="wpvivid-storage-form">
                            <input class="button-primary" type="submit" option="add-remote"
                                   value="<?php esc_attr_e( 'Test and Add', 'bunny-storage-for-wpvivid' ); ?>" />
                        </div>
                    </td>
                    <td class="column-description desc">
                        <div class="wpvivid-storage-form-desc">
                            <i><?php esc_html_e( 'Tests the connection and adds the storage if successful.', 'bunny-storage-for-wpvivid' ); ?></i>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Edit storage form ─────────────────────────────────────────────────────

    public function edit_storage_page() {
        $pfx = 'edit-' . WPVIVID_REMOTE_BUNNY;
        ?>
        <div id="remote_storage_edit_<?php echo esc_attr( WPVIVID_REMOTE_BUNNY ); ?>"
             class="postbox storage-account-block remote-storage-edit" style="display:none;">
            <div style="padding:0 10px 10px 0;">
                <strong><?php esc_html_e( 'Edit Bunny Storage Zone', 'bunny-storage-for-wpvivid' ); ?></strong>
            </div>
            <table class="wp-list-table widefat plugins nahnu-bunny-form" style="width:100%;">
                <tbody>
                <form>
                <?php $this->render_form_fields( $pfx ); ?>
                </form>
                <tr>
                    <td class="plugin-title column-primary">
                        <div class="wpvivid-storage-form">
                            <input class="button-primary" type="submit" option="update-remote"
                                   value="<?php esc_attr_e( 'Save Changes', 'bunny-storage-for-wpvivid' ); ?>" />
                        </div>
                    </td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Shared form field renderer ────────────────────────────────────────────

    private function render_form_fields( $pfx ) {
        $t       = esc_attr( $pfx );
        $regions = self::region_map();

        // ── Alias ───────────────────────────────────────────────────────────
        $this->form_row(
            '<input type="text" autocomplete="off" option="' . $t . '" name="name"
                placeholder="' . esc_attr__( 'Unique alias e.g. Bunny-Backups', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text"
                onkeyup="value=value.replace(/[^a-zA-Z0-9\\-_]/g,\'\')" />',
            esc_html__( 'A display name to identify this storage connection.', 'bunny-storage-for-wpvivid' )
        );

        // ── Connection method ───────────────────────────────────────────────
        $this->form_row(
            '<select option="' . $t . '" name="connection_method" class="regular-text" style="height:30px;"
                onchange="nahnu_bunny_toggle_method(this,\'' . esc_js( $pfx ) . '\')">
                <option value="ftp">' . esc_html__( 'FTP',                     'bunny-storage-for-wpvivid' ) . '</option>
                <option value="api">' . esc_html__( 'REST API (recommended)',   'bunny-storage-for-wpvivid' ) . '</option>
                <option value="s3">'  . esc_html__( 'S3-compatible (preview)',  'bunny-storage-for-wpvivid' ) . '</option>
            </select>',
            esc_html__( 'FTP works on any host. REST API is fastest and requires no extra extensions. S3 is Bunny\'s in-preview S3-compatible interface.', 'bunny-storage-for-wpvivid' )
        );

        // ── Region ──────────────────────────────────────────────────────────
        $region_opts = '';
        foreach ( $regions as $key => $info ) {
            $region_opts .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $info['label'] ) . '</option>';
        }
        $this->form_row(
            '<select option="' . $t . '" name="region" class="regular-text" style="height:30px;">'
            . $region_opts . '</select>',
            esc_html__( 'Must match your Storage Zone\'s primary region in the Bunny dashboard.', 'bunny-storage-for-wpvivid' )
        );

        // ── Zone Name (always visible) ───────────────────────────────────────
        $this->form_row(
            '<input type="text" autocomplete="off" option="' . $t . '" name="zone_name"
                placeholder="' . esc_attr__( 'Storage Zone name', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text" />',
            esc_html__( 'Your Bunny Storage Zone name — this is the FTP username, REST API path component, and S3 bucket name.', 'bunny-storage-for-wpvivid' )
        );

        // ── Zone Password — FTP + API ────────────────────────────────────────
        $this->form_row_conditional(
            $pfx,
            'ftp api',
            '<input type="password" autocomplete="new-password" option="' . $t . '" name="zone_password"
                id="nahnu-bunny-zone-pw-' . esc_attr( $pfx ) . '"
                placeholder="' . esc_attr__( 'Storage Zone password', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text" />',
            '<span id="nahnu-bunny-pw-desc-' . esc_attr( $pfx ) . '">'
            . esc_html__( 'FTP: your Storage Zone password. REST API: same value — Bunny uses it as the AccessKey header. Found on the FTP &amp; API Access tab of your Storage Zone.', 'bunny-storage-for-wpvivid' )
            . '</span>'
        );

        // ── S3 Access Key ────────────────────────────────────────────────────
        $this->form_row_conditional(
            $pfx,
            's3',
            '<input type="text" autocomplete="off" option="' . $t . '" name="s3_access_key"
                placeholder="' . esc_attr__( 'S3 Access Key ID', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text" />',
            esc_html__( 'Your Bunny S3 Access Key ID. Found in the S3-compatible credentials section of your Storage Zone.', 'bunny-storage-for-wpvivid' )
        );

        // ── S3 Secret Key ────────────────────────────────────────────────────
        $this->form_row_conditional(
            $pfx,
            's3',
            '<input type="password" autocomplete="new-password" option="' . $t . '" name="s3_secret_key"
                placeholder="' . esc_attr__( 'S3 Secret Key', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text" />',
            esc_html__( 'Your Bunny S3 Secret Key (often the same as the Storage Zone password).', 'bunny-storage-for-wpvivid' )
        );

        // ── S3 Endpoint ──────────────────────────────────────────────────────
        $this->form_row_conditional(
            $pfx,
            's3',
            '<input type="text" autocomplete="off" option="' . $t . '" name="s3_endpoint"
                placeholder="https://s3.bunnycdn.com"
                class="regular-text" />',
            esc_html__( 'Bunny S3 endpoint URL. Leave blank for https://s3.bunnycdn.com — override only if Bunny documents a region-specific S3 endpoint.', 'bunny-storage-for-wpvivid' )
        );

        // ── Path ────────────────────────────────────────────────────────────
        $this->form_row(
            '<input type="text" autocomplete="off" option="' . $t . '" name="path"
                placeholder="' . esc_attr__( '/subdirectory or leave blank', 'bunny-storage-for-wpvivid' ) . '"
                class="regular-text" />',
            esc_html__( 'Optional subdirectory inside your Storage Zone. Leave blank to store backups in the zone root.', 'bunny-storage-for-wpvivid' )
        );

        // ── Default ─────────────────────────────────────────────────────────
        echo '<tr><td class="plugin-title column-primary"><div class="wpvivid-storage-select">';
        echo '<label><input type="checkbox" option="' . $t . '" name="default" checked />&nbsp;';
        esc_html_e( 'Set as the default remote storage.', 'bunny-storage-for-wpvivid' );
        echo '</label></div></td><td></td></tr>';
    }

    // ── Row helpers ───────────────────────────────────────────────────────────

    /** Always-visible row. */
    private function form_row( $field_html, $desc_html ) {
        echo '<tr>';
        echo '<td class="plugin-title column-primary"><div class="wpvivid-storage-form">' . $field_html . '</div></td>';
        echo '<td class="column-description desc"><div class="wpvivid-storage-form-desc"><i>' . $desc_html . '</i></div></td>';
        echo '</tr>';
    }

    /**
     * Conditionally visible row — shown only for the listed methods.
     *
     * @param string $pfx      Form prefix ('bunny_storage' or 'edit-bunny_storage').
     * @param string $methods  Space-separated list of methods that show this row e.g. 'ftp api'.
     * @param string $field    Field HTML.
     * @param string $desc     Description HTML.
     */
    private function form_row_conditional( $pfx, $methods, $field, $desc ) {
        $method_list  = explode( ' ', $methods );
        // For the default method (ftp) — show immediately; others start hidden.
        $default_show = in_array( 'ftp', $method_list, true );
        $style        = $default_show ? '' : ' style="display:none;"';
        $data_methods = esc_attr( $methods ); // stored as data attr for JS

        echo '<tr data-pfx="' . esc_attr( $pfx ) . '" data-methods="' . $data_methods . '"' . $style . '>';
        echo '<td class="plugin-title column-primary"><div class="wpvivid-storage-form">' . $field . '</div></td>';
        echo '<td class="column-description desc"><div class="wpvivid-storage-form-desc"><i>' . $desc . '</i></div></td>';
        echo '</tr>';
    }

    // ── Inline JS (printed once) ──────────────────────────────────────────────

    private function print_js() {
        static $done = false;
        if ( $done ) return;
        $done = true;
        ?>
        <script>
        /**
         * Toggle visible rows and update field labels based on selected connection method.
         */
        function nahnu_bunny_toggle_method( sel, pfx ) {
            var method = sel.value;

            // Walk up to the closest .nahnu-bunny-form wrapper.
            var node = sel, form_table = null;
            while ( node && node !== document.body ) {
                node = node.parentNode;
                if ( node && node.classList && node.classList.contains( 'nahnu-bunny-form' ) ) {
                    form_table = node; break;
                }
            }
            if ( ! form_table ) return;

            // Show/hide rows based on data-methods attribute.
            form_table.querySelectorAll( 'tr[data-pfx="' + pfx + '"]' ).forEach( function( row ) {
                var methods = ( row.getAttribute( 'data-methods' ) || '' ).split( ' ' );
                row.style.display = ( methods.indexOf( method ) !== -1 ) ? '' : 'none';
            } );

            // Update the password field placeholder so it makes sense for each method.
            var pw = document.getElementById( 'nahnu-bunny-zone-pw-' + pfx );
            if ( pw ) {
                pw.placeholder = ( method === 'api' )
                    ? 'Storage Zone password (used as API AccessKey)'
                    : 'Storage Zone password';
            }
        }
        </script>
        <?php
    }

    // =========================================================================
    // Options sanitisation
    // =========================================================================

    public function sanitize_options( $skip_name = '' ) {
        $regions = self::region_map();

        // ── Name ──
        if ( empty( $this->options['name'] ) ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: An alias for remote storage is required.' );
        }
        $this->options['name'] = sanitize_text_field( $this->options['name'] );

        $existing = WPvivid_Setting::get_all_remote_options();
        if ( ! empty( $existing ) ) {
            foreach ( $existing as $stored ) {
                if ( isset( $stored['name'] ) && $stored['name'] === $this->options['name'] && $skip_name !== $stored['name'] ) {
                    return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The alias already exists in storage list.' );
                }
            }
        }

        // ── Connection method ──
        $method = isset( $this->options['connection_method'] ) ? sanitize_text_field( $this->options['connection_method'] ) : 'ftp';
        if ( ! in_array( $method, array( 'ftp', 'api', 's3' ), true ) ) { $method = 'ftp'; }
        $this->options['connection_method'] = $method;

        // ── Region ──
        $region = isset( $this->options['region'] ) ? sanitize_text_field( $this->options['region'] ) : 'de';
        if ( ! array_key_exists( $region, $regions ) ) { $region = 'de'; }
        $this->options['region'] = $region;
        $this->options['host']   = $regions[ $region ]['host'];

        // ── Zone name ──
        if ( empty( $this->options['zone_name'] ) ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The Bunny Storage Zone name is required.' );
        }
        $this->options['zone_name'] = sanitize_text_field( $this->options['zone_name'] );

        // ── Method-specific credentials ──
        if ( $method === 's3' ) {

            if ( empty( $this->options['s3_access_key'] ) ) {
                return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The Bunny S3 Access Key ID is required.' );
            }
            $this->options['s3_access_key'] = sanitize_text_field( $this->options['s3_access_key'] );

            if ( empty( $this->options['s3_secret_key'] ) && empty( $skip_name ) ) {
                return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The Bunny S3 Secret Key is required.' );
            }
            if ( ! empty( $this->options['s3_secret_key'] ) ) {
                // wp_unslash() only — do not mangle credentials with sanitize_text_field().
                $this->options['s3_secret_key']     = base64_encode( wp_unslash( $this->options['s3_secret_key'] ) );
                $this->options['s3_secret_encrypt'] = 1;
            }
            // Enforce https:// — reject any other protocol on the S3 endpoint.
            $ep = isset( $this->options['s3_endpoint'] ) ? trim( wp_unslash( $this->options['s3_endpoint'] ) ) : '';
            if ( ! empty( $ep ) ) {
                if ( strpos( $ep, 'https://' ) !== 0 ) {
                    return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The S3 endpoint must begin with https://.' );
                }
                $ep = esc_url_raw( $ep );
            }
            $this->options['s3_endpoint'] = ! empty( $ep ) ? $ep : 'https://s3.bunnycdn.com';

        } else { // ftp or api

            if ( empty( $this->options['zone_password'] ) && empty( $skip_name ) ) {
                return array( 'result' => WPVIVID_FAILED, 'error' => 'Warning: The Storage Zone password is required.' );
            }
            if ( ! empty( $this->options['zone_password'] ) ) {
                // Use wp_unslash() only — sanitize_text_field() strips tags and trims
                // whitespace which can silently corrupt passwords with special characters.
                $this->options['zone_password'] = base64_encode( wp_unslash( $this->options['zone_password'] ) );
                $this->options['is_encrypt']    = 1;
            }
        }

        // ── Path ──
        $path = isset( $this->options['path'] ) ? sanitize_text_field( wp_unslash( $this->options['path'] ) ) : '';
        if ( empty( $path ) ) { $path = '/'; }
        if ( substr( $path, 0, 1 ) !== '/' ) { $path = '/' . $path; }
        // Strip path traversal sequences — split on /, remove '..' and '.', rejoin.
        $segs = array_filter( explode( '/', $path ), function( $s ) {
            return $s !== '..' && $s !== '.' && $s !== '';
        } );
        $path = '/' . implode( '/', array_map( 'sanitize_file_name', $segs ) );
        $this->options['path'] = $path;

        $this->options['default'] = isset( $this->options['default'] ) ? (int) $this->options['default'] : 0;
        $this->options['type']    = WPVIVID_REMOTE_BUNNY;

        return array( 'result' => WPVIVID_SUCCESS, 'options' => $this->options );
    }

    // =========================================================================
    // Abstract method dispatch
    // =========================================================================

    public function test_connect() {
        $m = isset( $this->options['connection_method'] ) ? $this->options['connection_method'] : 'ftp';
        switch ( $m ) {
            case 'api': return $this->api_test_connect();
            case 's3':  return $this->s3_test_connect();
            default:    return $this->ftp_test_connect();
        }
    }

    public function upload( $task_id, $files, $callback = '' ) {
        $m = isset( $this->options['connection_method'] ) ? $this->options['connection_method'] : 'ftp';
        switch ( $m ) {
            case 'api': return $this->api_upload( $task_id, $files, $callback );
            case 's3':  return $this->s3_upload( $task_id, $files, $callback );
            default:    return $this->ftp_upload( $task_id, $files, $callback );
        }
    }

    public function download( $file, $local_path, $callback = '' ) {
        $m = isset( $this->options['connection_method'] ) ? $this->options['connection_method'] : 'ftp';
        switch ( $m ) {
            case 'api': return $this->api_download( $file, $local_path, $callback );
            case 's3':  return $this->s3_download( $file, $local_path, $callback );
            default:    return $this->ftp_download( $file, $local_path, $callback );
        }
    }

    public function cleanup( $files ) {
        $m = isset( $this->options['connection_method'] ) ? $this->options['connection_method'] : 'ftp';
        switch ( $m ) {
            case 'api': return $this->api_cleanup( $files );
            case 's3':  return $this->s3_cleanup( $files );
            default:    return $this->ftp_cleanup( $files );
        }
    }

    // =========================================================================
    // FTP
    // =========================================================================

    private function ftp_password() {
        return ( ! empty( $this->options['is_encrypt'] ) )
            ? base64_decode( $this->options['zone_password'] )
            : $this->options['zone_password'];
    }

    private function ftp_open() {
        $host = $this->options['host'];
        $conn = @ftp_connect( $host, 21, 30 );
        if ( ! $conn ) {
            return array( 'result' => WPVIVID_FAILED,
                'error' => 'Could not connect to Bunny FTP host ' . $host . '. Verify the selected region.' );
        }
        if ( ! @ftp_login( $conn, $this->options['zone_name'], $this->ftp_password() ) ) {
            ftp_close( $conn );
            return array( 'result' => WPVIVID_FAILED,
                'error' => 'FTP login failed. Check your Storage Zone name and password.' );
        }
        ftp_pasv( $conn, true ); // Bunny requires passive mode.
        return $conn;
    }

    /**
     * Build the FTP remote path for a file, relative to the zone root.
     * Bunny Storage FTP chroots to the zone root, so all paths must be relative
     * (no leading slash). '/backups' → 'backups', '/' → '' (zone root).
     */
    private function ftp_rel_path( $filename = '' ) {
        $path = trim( $this->options['path'], '/' ); // strip all slashes
        if ( $path && $filename ) { return $path . '/' . $filename; }
        if ( $path )              { return $path; }
        return $filename;
    }

    /**
     * Navigate into (and create if needed) the configured subdirectory.
     * Uses only relative paths — Bunny chroots the FTP session to the zone root,
     * so absolute paths like /subdir fail with a permissions error.
     */
    private function ftp_mkpath( $conn, $path ) {
        // Strip slashes and split into segments.
        $segs = array_filter( explode( '/', trim( $path, '/' ) ) );
        if ( empty( $segs ) ) { return array( 'result' => WPVIVID_SUCCESS ); }

        foreach ( $segs as $seg ) {
            if ( @ftp_chdir( $conn, $seg ) ) {
                continue; // Segment already exists; navigated into it.
            }
            // Segment doesn't exist — create it, then navigate into it.
            if ( ! @ftp_mkdir( $conn, $seg ) ) {
                return array( 'result' => WPVIVID_FAILED,
                    'error' => 'Cannot create subdirectory "' . esc_html( $seg ) . '" inside your Bunny Storage Zone. '
                             . 'Check that your Storage Zone password has write permission.' );
            }
            @ftp_chdir( $conn, $seg );
        }
        return array( 'result' => WPVIVID_SUCCESS );
    }

    private function ftp_test_connect() {
        // Only verify that the FTP credentials are valid.
        // We deliberately do NOT test directory creation here — the subdirectory may
        // not exist yet and that is fine; ftp_upload creates it on the first backup.
        // Testing mkdir during "Test and Add" would block saving whenever the
        // user enters a new subdirectory that hasn't been created yet.
        $conn = $this->ftp_open();
        if ( is_array( $conn ) ) { return $conn; }
        ftp_close( $conn );
        return array( 'result' => WPVIVID_SUCCESS );
    }

    private function ftp_upload( $task_id, $files, $callback ) {
        global $wpvivid_plugin;
        $uj = $this->init_upload_job( $task_id, $files );
        if ( isset( $uj['result'] ) ) { return $uj; } // error from init

        $conn = $this->ftp_open();
        if ( is_array( $conn ) ) { return $conn; }
        $r = $this->ftp_mkpath( $conn, $this->options['path'] );
        if ( $r['result'] !== WPVIVID_SUCCESS ) { ftp_close( $conn ); return $r; }

        $flag = true; $error = '';
        foreach ( $files as $file ) {
            $bn = basename( $file );
            if ( ! empty( $uj['job_data'][ $bn ]['uploaded'] ) ) { continue; }
            if ( ! file_exists( $file ) ) {
                ftp_close( $conn );
                return array( 'result' => WPVIVID_FAILED, 'error' => $bn . ' not found. Please re-run the backup.' );
            }
            $wpvivid_plugin->set_time_limit( $task_id );
            $this->current_file_name = $bn;
            // Reuse size stored in job_data — avoids a redundant filesize() syscall.
            $this->current_file_size = isset( $uj['job_data'][ $bn ]['size'] ) ? $uj['job_data'][ $bn ]['size'] : filesize( $file );
            $this->last_time = time(); $this->last_size = 0;
            // ftp_mkpath() already chdir'd into the target subdirectory, so use the
            // bare filename here — not the full relative path.  Using ftp_rel_path()
            // here would double-prefix the path: subdir/subdir/file.zip.
            $remote = $bn;

            for ( $i = 0; $i < WPVIVID_REMOTE_CONNECT_RETRY_TIMES; $i++ ) {
                $fh = @fopen( $file, 'rb' );
                if ( ! $fh ) { ftp_close( $conn ); return array( 'result' => WPVIVID_FAILED, 'error' => 'Cannot open ' . $bn ); }
                $st = ftp_nb_fput( $conn, $remote, $fh, FTP_BINARY, 0 );
                while ( FTP_MOREDATA === $st ) {
                    $st = ftp_nb_continue( $conn );
                    if ( time() - $this->last_time > 3 ) {
                        is_callable( $callback ) && call_user_func_array( $callback,
                            array( ftell( $fh ), $bn, $this->current_file_size, $this->last_time, $this->last_size ) );
                        $this->last_size = ftell( $fh ); $this->last_time = time();
                    }
                }
                fclose( $fh );
                if ( FTP_FINISHED === $st ) {
                    WPvivid_taskmanager::wpvivid_reset_backup_retry_times( $task_id );
                    $this->mark_uploaded( $task_id, $uj, $bn );
                    break;
                }
                if ( $i === WPVIVID_REMOTE_CONNECT_RETRY_TIMES - 1 ) {
                    $flag = false; $error = 'FTP upload of ' . $bn . ' failed after retries.'; break 2;
                }
                sleep( WPVIVID_REMOTE_CONNECT_RETRY_INTERVAL );
            }
        }
        ftp_close( $conn );
        return $flag ? array( 'result' => WPVIVID_SUCCESS ) : array( 'result' => WPVIVID_FAILED, 'error' => $error );
    }

    private function ftp_download( $file, $local_path, $callback ) {
        try {
            global $wpvivid_plugin;
            $local = trailingslashit( $local_path ) . $file['file_name'];
            $this->current_file_name = $file['file_name'];
            $this->current_file_size = $file['size'];
            $this->last_time = time(); $this->last_size = 0;

            $conn = $this->ftp_open();
            if ( is_array( $conn ) ) { return $conn; }

            // Navigate into the subdirectory first (same pattern as upload).
            // Bunny FTP requires chdir before using bare filenames — passing
            // 'subdir/file.zip' directly to ftp_nb_fget may fail on some regions.
            $subdir = trim( $this->options['path'], '/' );
            if ( $subdir && ! @ftp_chdir( $conn, $subdir ) ) {
                ftp_close( $conn );
                return array( 'result' => WPVIVID_FAILED,
                    'error' => 'Cannot access directory "' . esc_html( $subdir ) . '" on Bunny Storage.' );
            }

            $fh = @fopen( $local, 'ab' );
            if ( ! $fh ) {
                ftp_close( $conn );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'Cannot create local file for writing.' );
            }
            $offset = fstat( $fh )['size'];

            // Use bare filename — CWD is now the target directory.
            $st = ftp_nb_fget( $conn, $fh, $file['file_name'], FTP_BINARY, $offset );
            while ( FTP_MOREDATA === $st ) {
                $st = ftp_nb_continue( $conn );
                if ( time() - $this->last_time > 3 ) {
                    is_callable( $callback ) && call_user_func_array( $callback,
                        array( ftell( $fh ), $file['file_name'], $file['size'], $this->last_time, $this->last_size ) );
                    $this->last_size = ftell( $fh ); $this->last_time = time();
                }
            }
            fclose( $fh );

            $ok = ( filesize( $local ) == $file['size'] ) && $wpvivid_plugin->wpvivid_check_zip_valid();
            if ( FTP_FINISHED !== $st || ! $ok ) {
                @wp_delete_file( $local );
                ftp_close( $conn );
                return array( 'result' => WPVIVID_FAILED,
                    'error' => 'FTP download of ' . $file['file_name'] . ' failed or file is corrupt. '
                             . 'Check the Logs tab for details.' );
            }
            ftp_close( $conn );
            return array( 'result' => WPVIVID_SUCCESS );
        } catch ( Exception $e ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => $e->getMessage() );
        }
    }

    private function ftp_cleanup( $files ) {
        $conn = $this->ftp_open();
        if ( is_array( $conn ) ) { return $conn; }
        // Navigate into subdirectory first (bare filenames only, same as upload/download).
        $subdir = trim( $this->options['path'], '/' );
        if ( $subdir ) { @ftp_chdir( $conn, $subdir ); }
        foreach ( $files as $f ) { @ftp_delete( $conn, $f ); }
        ftp_close( $conn );
        return array( 'result' => WPVIVID_SUCCESS );
    }

    // =========================================================================
    // REST API  (PUT/GET/DELETE  https://{host}/{zone}/{subpath}/{file})
    // =========================================================================

    private function api_password() {
        return ( ! empty( $this->options['is_encrypt'] ) )
            ? base64_decode( $this->options['zone_password'] )
            : $this->options['zone_password'];
    }

    private function api_url( $filename = '' ) {
        $zone = $this->options['zone_name'];
        $sub  = trim( $this->options['path'], '/' );
        $url  = 'https://' . $this->options['host'] . '/' . $zone . '/';
        if ( $sub ) { $url .= $sub . '/'; }
        if ( $filename ) { $url .= rawurlencode( $filename ); }
        return $url;
    }

    private function api_test_connect() {
        // Test credentials by listing the zone root (not the configured subpath —
        // the subpath may not exist yet and that is fine; it is created on first upload).
        $zone_root = 'https://' . $this->options['host'] . '/' . rawurlencode( $this->options['zone_name'] ) . '/';
        $response  = wp_remote_get( $zone_root, array(
            'headers' => array( 'AccessKey' => $this->api_password() ),
            'timeout' => 20,
        ) );
        if ( is_wp_error( $response ) ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => 'API connection failed: ' . $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        // 200 = listed OK.  404 = zone is empty or path not yet created — credentials
        // are still valid.  401 / 403 = wrong password or zone name.
        if ( $code === 200 || $code === 404 ) { return array( 'result' => WPVIVID_SUCCESS ); }
        if ( $code === 401 || $code === 403 ) {
            return array( 'result' => WPVIVID_FAILED,
                'error' => 'API authentication failed (HTTP ' . $code . '). Check your Storage Zone password.' );
        }
        return array( 'result' => WPVIVID_FAILED,
            'error' => 'Bunny Storage API returned HTTP ' . $code . '. Check your zone name and region.' );
    }

    private function api_upload( $task_id, $files, $callback ) {
        global $wpvivid_plugin;
        $uj = $this->init_upload_job( $task_id, $files );
        if ( isset( $uj['result'] ) ) { return $uj; }

        $flag = true; $error = '';
        foreach ( $files as $file ) {
            $bn = basename( $file );
            if ( ! empty( $uj['job_data'][ $bn ]['uploaded'] ) ) { continue; }
            if ( ! file_exists( $file ) ) { return array( 'result' => WPVIVID_FAILED, 'error' => $bn . ' not found. Please re-run the backup.' ); }
            $wpvivid_plugin->set_time_limit( $task_id );
            $this->current_file_name = $bn;
            $this->current_file_size = isset( $uj['job_data'][ $bn ]['size'] ) ? $uj['job_data'][ $bn ]['size'] : filesize( $file );

            for ( $i = 0; $i < WPVIVID_REMOTE_CONNECT_RETRY_TIMES; $i++ ) {
                $res = $this->curl_put( $this->api_url( $bn ), $file,
                    array( 'AccessKey: ' . $this->api_password(), 'Content-Type: application/octet-stream' ) );
                if ( $res['result'] === WPVIVID_SUCCESS ) {
                    WPvivid_taskmanager::wpvivid_reset_backup_retry_times( $task_id );
                    $this->mark_uploaded( $task_id, $uj, $bn );
                    break;
                }
                if ( $i === WPVIVID_REMOTE_CONNECT_RETRY_TIMES - 1 ) {
                    $flag = false; $error = 'API upload of ' . $bn . ' failed: ' . $res['error']; break 2;
                }
                sleep( WPVIVID_REMOTE_CONNECT_RETRY_INTERVAL );
            }
        }
        return $flag ? array( 'result' => WPVIVID_SUCCESS ) : array( 'result' => WPVIVID_FAILED, 'error' => $error );
    }

    private function api_download( $file, $local_path, $callback ) {
        try {
            global $wpvivid_plugin;
            $local = trailingslashit( $local_path ) . $file['file_name'];
            $fh    = @fopen( $local, 'ab' );
            if ( ! $fh ) { return array( 'result' => WPVIVID_FAILED, 'error' => 'Cannot create local file.' ); }
            $offset = fstat( $fh )['size'];

            $ch = curl_init( $this->api_url( $file['file_name'] ) );
            if ( ! $ch ) {
                fclose( $fh );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'cURL initialisation failed.' );
            }
            curl_setopt_array( $ch, array(
                CURLOPT_HTTPHEADER     => array( 'AccessKey: ' . $this->api_password(), 'Range: bytes=' . $offset . '-' ),
                CURLOPT_FILE           => $fh,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_CONNECTTIMEOUT => 30,
            ) );
            curl_exec( $ch );
            $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $cerr = curl_error( $ch );
            curl_close( $ch ); fclose( $fh );

            if ( $cerr || ( $code !== 200 && $code !== 206 ) ) {
                @wp_delete_file( $local );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'API download of ' . $file['file_name'] . ' failed (HTTP ' . $code . ') ' . $cerr );
            }
            if ( filesize( $local ) != $file['size'] || ! $wpvivid_plugin->wpvivid_check_zip_valid() ) {
                @wp_delete_file( $local );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'File size mismatch or corrupt archive.' );
            }
            return array( 'result' => WPVIVID_SUCCESS );
        } catch ( Exception $e ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => $e->getMessage() );
        }
    }

    private function api_cleanup( $files ) {
        foreach ( $files as $fn ) {
            wp_remote_request( $this->api_url( $fn ), array(
                'method'  => 'DELETE',
                'headers' => array( 'AccessKey' => $this->api_password() ),
                'timeout' => 20,
            ) );
        }
        return array( 'result' => WPVIVID_SUCCESS );
    }

    // =========================================================================
    // S3-compatible  (AWS Signature V4 — no external SDK)
    // =========================================================================

    private function s3_secret() {
        return ( ! empty( $this->options['s3_secret_encrypt'] ) )
            ? base64_decode( $this->options['s3_secret_key'] )
            : $this->options['s3_secret_key'];
    }

    private function s3_url( $filename = '' ) {
        $ep     = rtrim( isset( $this->options['s3_endpoint'] ) ? $this->options['s3_endpoint'] : 'https://s3.bunnycdn.com', '/' );
        $bucket = $this->options['zone_name'];
        $prefix = trim( $this->options['path'], '/' );
        $url    = $ep . '/' . $bucket . '/';
        if ( $prefix ) { $url .= $prefix . '/'; }
        if ( $filename ) { $url .= rawurlencode( $filename ); }
        return $url;
    }

    /**
     * AWS Signature V4 — returns a signed assoc-headers array.
     *
     * @param string $method       HTTP verb.
     * @param string $url          Full URL.
     * @param array  $extra        Additional headers to sign (lowercase keys).
     * @param string $payload_hash SHA-256 of body, or 'UNSIGNED-PAYLOAD'.
     */
    private function s3_sign( $method, $url, $extra = array(), $payload_hash = 'UNSIGNED-PAYLOAD' ) {
        $ak     = $this->options['s3_access_key'];
        $sk     = $this->s3_secret();
        $region = $this->options['region'];
        $dt     = gmdate( 'Ymd\THis\Z' );
        $d      = substr( $dt, 0, 8 );

        $parsed = wp_parse_url( $url );
        $host   = $parsed['host'];
        $uri    = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        $query  = isset( $parsed['query'] ) ? $parsed['query'] : '';

        // URI encode each path segment, keep slashes.
        $uri_enc = implode( '/', array_map( 'rawurlencode', explode( '/', $uri ) ) );

        $hdrs = array_merge( $extra, array(
            'host'                 => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $dt,
        ) );
        ksort( $hdrs );

        $canon_hdrs = ''; $signed_list = array();
        foreach ( $hdrs as $k => $v ) {
            $kl = strtolower( trim( $k ) );
            $canon_hdrs   .= $kl . ':' . trim( $v ) . "\n";
            $signed_list[] = $kl;
        }
        $signed_str  = implode( ';', $signed_list );
        $canon_req   = strtoupper( $method ) . "\n" . $uri_enc . "\n" . $query . "\n"
                       . $canon_hdrs . "\n" . $signed_str . "\n" . $payload_hash;
        $scope       = $d . '/' . $region . '/s3/aws4_request';
        $sts         = "AWS4-HMAC-SHA256\n{$dt}\n{$scope}\n" . hash( 'sha256', $canon_req );
        $signing_key = hash_hmac( 'sha256', 'aws4_request',
                        hash_hmac( 'sha256', 's3',
                         hash_hmac( 'sha256', $region,
                          hash_hmac( 'sha256', $d, 'AWS4' . $sk, true ), true ), true ), true );
        $sig  = hash_hmac( 'sha256', $sts, $signing_key );
        $auth = "AWS4-HMAC-SHA256 Credential={$ak}/{$scope}, SignedHeaders={$signed_str}, Signature={$sig}";
        return array_merge( $hdrs, array( 'Authorization' => $auth ) );
    }

    private function s3_test_connect() {
        $url    = $this->s3_url();
        $signed = $this->s3_sign( 'HEAD', $url );
        $r = wp_remote_head( $url, array( 'headers' => $signed, 'timeout' => 20 ) );
        if ( is_wp_error( $r ) ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => 'S3 connection failed: ' . $r->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $r );
        if ( $code == 200 || $code == 404 ) { return array( 'result' => WPVIVID_SUCCESS ); }
        return array( 'result' => WPVIVID_FAILED,
            'error' => 'Bunny S3 returned HTTP ' . $code . '. Check credentials and endpoint.' );
    }

    private function s3_upload( $task_id, $files, $callback ) {
        global $wpvivid_plugin;
        $uj = $this->init_upload_job( $task_id, $files );
        if ( isset( $uj['result'] ) ) { return $uj; }

        $flag = true; $error = '';
        foreach ( $files as $file ) {
            $bn = basename( $file );
            if ( ! empty( $uj['job_data'][ $bn ]['uploaded'] ) ) { continue; }
            if ( ! file_exists( $file ) ) { return array( 'result' => WPVIVID_FAILED, 'error' => $bn . ' not found. Please re-run the backup.' ); }
            $wpvivid_plugin->set_time_limit( $task_id );
            $url  = $this->s3_url( $bn );
            $size = isset( $uj['job_data'][ $bn ]['size'] ) ? $uj['job_data'][ $bn ]['size'] : filesize( $file );
            $extra = array( 'content-length' => (string) $size, 'content-type' => 'application/octet-stream' );

            for ( $i = 0; $i < WPVIVID_REMOTE_CONNECT_RETRY_TIMES; $i++ ) {
                $signed = $this->s3_sign( 'PUT', $url, $extra, 'UNSIGNED-PAYLOAD' );
                $res    = $this->curl_put( $url, $file, $this->hdrs_to_curl( $signed ) );
                if ( $res['result'] === WPVIVID_SUCCESS ) {
                    WPvivid_taskmanager::wpvivid_reset_backup_retry_times( $task_id );
                    $this->mark_uploaded( $task_id, $uj, $bn );
                    break;
                }
                if ( $i === WPVIVID_REMOTE_CONNECT_RETRY_TIMES - 1 ) {
                    $flag = false; $error = 'S3 upload of ' . $bn . ' failed: ' . $res['error']; break 2;
                }
                sleep( WPVIVID_REMOTE_CONNECT_RETRY_INTERVAL );
            }
        }
        return $flag ? array( 'result' => WPVIVID_SUCCESS ) : array( 'result' => WPVIVID_FAILED, 'error' => $error );
    }

    private function s3_download( $file, $local_path, $callback ) {
        try {
            global $wpvivid_plugin;
            $local  = trailingslashit( $local_path ) . $file['file_name'];
            $url    = $this->s3_url( $file['file_name'] );
            $fh     = @fopen( $local, 'ab' );
            if ( ! $fh ) { return array( 'result' => WPVIVID_FAILED, 'error' => 'Cannot create local file.' ); }
            $offset = fstat( $fh )['size'];
            $extra  = $offset > 0 ? array( 'range' => 'bytes=' . $offset . '-' ) : array();
            $signed = $this->s3_sign( 'GET', $url, $extra );

            $ch = curl_init( $url );
            if ( ! $ch ) {
                fclose( $fh );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'cURL initialisation failed.' );
            }
            curl_setopt_array( $ch, array(
                CURLOPT_HTTPHEADER     => $this->hdrs_to_curl( $signed ),
                CURLOPT_FILE           => $fh,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_CONNECTTIMEOUT => 30,
            ) );
            curl_exec( $ch );
            $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $cerr = curl_error( $ch );
            curl_close( $ch ); fclose( $fh );

            if ( $cerr || ( $code !== 200 && $code !== 206 ) ) {
                @wp_delete_file( $local );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'S3 download of ' . $file['file_name'] . ' failed (HTTP ' . $code . ') ' . $cerr );
            }
            if ( filesize( $local ) != $file['size'] || ! $wpvivid_plugin->wpvivid_check_zip_valid() ) {
                @wp_delete_file( $local );
                return array( 'result' => WPVIVID_FAILED, 'error' => 'File size mismatch or corrupt archive.' );
            }
            return array( 'result' => WPVIVID_SUCCESS );
        } catch ( Exception $e ) {
            return array( 'result' => WPVIVID_FAILED, 'error' => $e->getMessage() );
        }
    }

    private function s3_cleanup( $files ) {
        foreach ( $files as $fn ) {
            $url    = $this->s3_url( $fn );
            $signed = $this->s3_sign( 'DELETE', $url );
            $ch     = curl_init( $url );
            if ( ! $ch ) { continue; }
            curl_setopt_array( $ch, array(
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => $this->hdrs_to_curl( $signed ),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 20,
            ) );
            curl_exec( $ch ); curl_close( $ch );
        }
        return array( 'result' => WPVIVID_SUCCESS );
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /** Streaming PUT via cURL — file is never loaded into memory. */
    private function curl_put( $url, $local_file, $curl_headers ) {
        $size = filesize( $local_file );
        $fh   = @fopen( $local_file, 'rb' );
        if ( ! $fh ) { return array( 'result' => WPVIVID_FAILED, 'error' => 'Cannot open ' . basename( $local_file ) ); }
        $ch = curl_init( $url );
        if ( ! $ch ) {
            fclose( $fh );
            return array( 'result' => WPVIVID_FAILED, 'error' => 'cURL initialisation failed. Ensure the cURL extension is installed.' );
        }
        curl_setopt_array( $ch, array(
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_HTTPHEADER     => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,   // Verify cert hostname matches.
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
        ) );
        $body = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $cerr = curl_error( $ch );
        curl_close( $ch ); fclose( $fh );
        if ( $cerr ) { return array( 'result' => WPVIVID_FAILED, 'error' => 'cURL error: ' . $cerr ); }
        if ( $code < 200 || $code >= 300 ) { return array( 'result' => WPVIVID_FAILED, 'error' => 'HTTP ' . $code . ': ' . $body ); }
        return array( 'result' => WPVIVID_SUCCESS );
    }

    /** Assoc headers → cURL CURLOPT_HTTPHEADER array. */
    private function hdrs_to_curl( $headers ) {
        $out = array();
        foreach ( $headers as $k => $v ) { $out[] = $k . ': ' . $v; }
        return $out;
    }

    /** Initialise or resume the WPvivid upload job tracker for this task. */
    private function init_upload_job( $task_id, $files ) {
        $uj = WPvivid_taskmanager::get_backup_sub_task_progress( $task_id, 'upload', WPVIVID_REMOTE_BUNNY );
        if ( ! empty( $uj ) ) { return $uj; }
        $jd = array();
        foreach ( $files as $file ) {
            if ( ! file_exists( $file ) ) {
                // Use basename — never expose absolute server paths in error messages.
                return array( 'result' => WPVIVID_FAILED, 'error' => basename( $file ) . ' not found. Please re-run the backup.' );
            }
            $jd[ basename( $file ) ] = array( 'size' => filesize( $file ), 'uploaded' => 0 );
        }
        WPvivid_taskmanager::update_backup_sub_task_progress(
            $task_id, 'upload', WPVIVID_REMOTE_BUNNY, WPVIVID_UPLOAD_UNDO, 'Starting Bunny upload.', $jd );
        return WPvivid_taskmanager::get_backup_sub_task_progress( $task_id, 'upload', WPVIVID_REMOTE_BUNNY );
    }

    /** Mark a file as successfully uploaded. */
    private function mark_uploaded( $task_id, &$uj, $basename ) {
        $uj['job_data'][ $basename ]['uploaded'] = 1;
        WPvivid_taskmanager::update_backup_sub_task_progress(
            $task_id, 'upload', WPVIVID_REMOTE_BUNNY,
            WPVIVID_UPLOAD_SUCCESS, 'Uploaded ' . $basename, $uj['job_data'] );
    }
}
