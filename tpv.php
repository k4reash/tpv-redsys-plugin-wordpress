<?php
/**
 * Plugin Name: TPV Redsys
 * Description: Terminal Punto de Venta integrado con Redsys para WordPress
 * Version: 1.5.0
 * Author: Alejandro Salas
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes
define('TPV_REDSYS_VERSION', '1.0.0');
define('TPV_REDSYS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TPV_REDSYS_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Clase principal del plugin TPV Redsys
 */
class TPV_Redsys {
    
    private $option_name = 'tpv_redsys';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_tpv_redsys_ajax', array($this, 'handle_ajax'));
        add_action('wp_ajax_nopriv_tpv_redsys_ajax', array($this, 'handle_ajax'));
        add_shortcode('tpv_redsys', array($this, 'shortcode'));
        
        // Hook de activación
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        $this->handle_redsys_response();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('tpv-redsys', TPV_REDSYS_PLUGIN_URL . 'assets/script.js', array('jquery'), TPV_REDSYS_VERSION, true);
        wp_localize_script('tpv-redsys', 'tpv_ajax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tpv_redsys_nonce')
        ));
        
        // Cargar CSS personalizado desde assets
        wp_enqueue_style('tpv-redsys-style', TPV_REDSYS_PLUGIN_URL . 'assets/style.css', array(), TPV_REDSYS_VERSION);
    }
    
    /**
     * FUNCIÓN GENERADORA
     */
    private function generar_codigo($longitud = 3) {
        $pattern = '1234567890abcdefghijklmnopqrstuvwxyz';
        return substr(str_shuffle($pattern), 0, $longitud);
    }
    
    /**
     * AJAX Handler
     */
    public function handle_ajax() {
        check_ajax_referer('tpv_redsys_nonce', 'noncesecure');
        
        // Valores de entrada
        $fuc = get_option($this->option_name . '_idfuc');
        $terminal = get_option($this->option_name . '_terminal');
        $moneda = get_option($this->option_name . '_moneda', '978');
        $trans = 0;
        $kc = get_option($this->option_name . '_encriptkey');
        $entornoact = get_option($this->option_name . '_entornoact', 'test');
        
        // URLs según entorno
        $form_tpv = ($entornoact === 'real') 
            ? 'https://sis.redsys.es/sis/realizarPago'
            : 'https://sis-t.redsys.es:25443/sis/realizarPago';
        
        // Obtener datos del formulario
        $amount = isset($_REQUEST['c']) ? floatval($_REQUEST['c']) : 0;
        $concepto = isset($_REQUEST['concepto']) ? sanitize_text_field($_REQUEST['concepto']) : 'Pago TPV';
        
        // Generar número de pedido automáticamente SIEMPRE
        $random = $this->generar_codigo(3);
        $id = $random . '-' . intval($amount * 100);
        
        // Validar longitud del número de pedido y ajustar si es necesario
        if (strlen($id) > 8) {
            $id = $random . intval($amount);
        }
        if (strlen($id) > 8) {
            $id = $this->generar_codigo(4) . intval($amount % 100);
        }
        
        // URLs de retorno - usar páginas específicas
        $url_ok = home_url('/tpv-pago-exitoso/');
        $url_ko = home_url('/tpv-pago-error/');
        
        // Crear objeto RedsysAPI
        $mi_obj = new RedsysAPI();
        
        // CONFIGURAR PARÁMETROS
        $mi_obj->set_parameter('DS_MERCHANT_AMOUNT', (int)($amount * 100));
        $mi_obj->set_parameter('DS_MERCHANT_ORDER', $id);
        $mi_obj->set_parameter('DS_MERCHANT_MERCHANTCODE', $fuc);
        $mi_obj->set_parameter('DS_MERCHANT_CURRENCY', $moneda);
        $mi_obj->set_parameter('DS_MERCHANT_TRANSACTIONTYPE', $trans); // 0 autorización
        $mi_obj->set_parameter('DS_MERCHANT_TERMINAL', $terminal);
        $mi_obj->set_parameter('DS_MERCHANT_MERCHANTURL', $url_ok);
        $mi_obj->set_parameter('DS_MERCHANT_URLOK', $url_ok);
        $mi_obj->set_parameter('DS_MERCHANT_URLKO', $url_ko);
        $mi_obj->set_parameter('DS_MERCHANT_MERCHANTNAME', get_bloginfo('name'));
        $mi_obj->set_parameter('DS_MERCHANT_PRODUCTDESCRIPTION', $concepto);
        
        // Datos de configuración
        $version = 'HMAC_SHA256_V1';
        
        // Generar parámetros y firma
        $params = $mi_obj->create_merchant_parameters();
        $signature = $mi_obj->create_merchant_signature($kc);
        
        // Crear formulario para envío automático
        echo '<form name="frm" id="form_tpv" action="' . esc_url($form_tpv) . '" method="POST">
            <input type="hidden" name="Ds_SignatureVersion" value="' . esc_attr($version) . '"/>
            <input type="hidden" name="Ds_MerchantParameters" value="' . esc_attr($params) . '"/>
            <input type="hidden" name="Ds_Signature" value="' . esc_attr($signature) . '"/>
        </form>
        <script>document.getElementById("form_tpv").submit();</script>';
        
        wp_die();
    }
    
    /**
     * Shortcode principal con diseño mejorado
     */
    public function shortcode($atts, $content = null) {
        $atts = shortcode_atts(array(
            'c' => '',
            'concepto' => '',
            'url_ok' => '',
            'url_ko' => '',
            'title' => 'Terminal Punto de Venta'
        ), $atts);
        
        // Verificar si hay respuesta de Redsys
        if (isset($_REQUEST['Ds_SignatureVersion'])) {
            return $this->handle_redsys_return();
        }
        
        // Mostrar resultado del pago
        $result_message = '';
        if (isset($_GET['tpv_result'])) {
            if ($_GET['tpv_result'] === 'ok') {
                $result_message = '<div class="alert alert-success">✓ Pago realizado correctamente</div>';
            } else {
                $result_message = '<div class="alert alert-error">❌ Error en el pago. Inténtelo de nuevo.</div>';
            }
        }
        
        // Mostrar formulario
        ob_start();
        ?>

        <div class="tpv-container">
            <h1><?php echo esc_html($atts['title']); ?></h1>
            
            <?php echo $result_message; ?>
            <?php echo $content ? '<div>' . $content . '</div>' : ''; ?>
        
        <form id="tpv_form" class="tpv-form">
            <div class="form-group">
                <label for="orderConcepto">Concepto del pago:</label>
                <input type="text" id="orderConcepto" name="concepto" value="<?php echo esc_attr($atts['concepto']); ?>" 
                        placeholder="Concepto del pago" maxlength="125" />
                <p class="description">Descripción que aparecerá en el extracto bancario</p>
            </div>
            
            <div class="form-group">
                <label for="amountTPV">Importe a cobrar (€):</label>
                <input type="number" step="0.01" id="amountTPV" name="c" value="<?php echo esc_attr($atts['c']); ?>" 
                        placeholder="0.00" min="0.01" max="99999.99" />
                <p class="description">Introduce el importe con decimales (ej: 25.50)</p>
            </div>
            
            <input type="hidden" name="action" value="tpv_redsys_ajax" />
            <input type="hidden" name="noncesecure" value="<?php echo wp_create_nonce('tpv_redsys_nonce'); ?>" />
            
            <?php if ($atts['url_ok']): ?>
            <input type="hidden" name="url_ok" value="<?php echo esc_attr($atts['url_ok']); ?>" />
            <?php endif; ?>
            
            <?php if ($atts['url_ko']): ?>
            <input type="hidden" name="url_ko" value="<?php echo esc_attr($atts['url_ko']); ?>" />
            <?php endif; ?>
            <div class="form-button">
                <button type="submit" id="form_tpv_submit" class="pay-button">
                🔒 Proceder al Pago
            </button>
            </div>
        </form>
        
        <div class="tpv-info">
            <h3>Información importante:</h3>
            <ul>
                <li>El pago se procesará de forma segura a través de Redsys</li>
                <li>Acepta tarjetas Visa, Mastercard y otras principales</li>
                <li>La transacción es completamente segura</li>
            </ul>
        </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#form_tpv_submit').click(function(e) {
                e.preventDefault();
                
                var error = false;
                
                // Validaciones
                if ($('#amountTPV').val() == '' || parseFloat($('#amountTPV').val()) <= 0) {
                    alert('Debe especificar una cantidad válida mayor que 0');
                    error = true;
                }
                
                if ($('#amountTPV').val() && parseFloat($('#amountTPV').val()) > 99999.99) {
                    alert('El importe máximo permitido es 99.999,99€');
                    error = true;
                }
                
                if (!error) {
                    // Mostrar mensaje de procesamiento
                    $('#form_tpv_submit').prop('disabled', true).text('Procesando...');
                    
                    var formData = $('#tpv_form').serialize();
                    
                    $.post(tpv_ajax.ajaxurl, formData, function(response) {
                        $('body').html(response);
                    });
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Manejar respuesta de Redsys
     */
    private function handle_redsys_response() {
        if (isset($_GET['tpv_result'])) {
            if ($_GET['tpv_result'] === 'ok' && isset($_REQUEST['Ds_Signature'])) {
                // Procesar respuesta exitosa
                $this->process_redsys_response();
            }
        }
    }
    
    private function handle_redsys_return() {
        $mi_obj = new RedsysAPI();
        
        if (isset($_REQUEST['Ds_Signature'])) {
            $version = isset($_REQUEST['Ds_SignatureVersion']) ? $_REQUEST['Ds_SignatureVersion'] : '';
            $datos = isset($_REQUEST['Ds_MerchantParameters']) ? $_REQUEST['Ds_MerchantParameters'] : '';
            $signature_received = isset($_REQUEST['Ds_Signature']) ? $_REQUEST['Ds_Signature'] : '';
            
            $decodec = $mi_obj->decode_merchant_parameters($datos);
            $kc = get_option($this->option_name . '_encriptkey');
            $firma = $mi_obj->create_merchant_signature_notif($kc, $datos);
            $decodec = get_object_vars(json_decode($decodec));
            
            if ($firma === $signature_received && intval($decodec['Ds_Response']) < 100) {
                return '<div class="tpv-success">
                    <h3>Pago completado exitosamente</h3>
                    <p><strong>Número de pedido:</strong> ' . esc_html($decodec['Ds_Order']) . '</p>
                    <p><strong>Importe:</strong> ' . (floatval($decodec['Ds_Amount']) / 100) . '€</p>
                    <p><strong>Fecha:</strong> ' . esc_html($decodec['Ds_Date'] . ' ' . $decodec['Ds_Hour']) . '</p>
                </div>';
            } else {
                return '<div class="tpv-error">
                    <h3>Error en el pago</h3>
                    <p>La transacción no se pudo completar.</p>
                </div>';
            }
        }
        
        return '';
    }
    
    private function process_redsys_response() {
        // Procesar la respuesta de Redsys aquí si es necesario
        // Por ejemplo, guardar en base de datos, enviar emails, etc.
    }
    
    /**
     * Menú de administración
     */
    public function admin_menu() {
        add_options_page(
            'TPV Redsys',
            'TPV Redsys',
            'manage_options',
            'tpv-redsys',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('tpv_redsys_settings', $this->option_name . '_idfuc');
        register_setting('tpv_redsys_settings', $this->option_name . '_terminal');
        register_setting('tpv_redsys_settings', $this->option_name . '_encriptkey');
        register_setting('tpv_redsys_settings', $this->option_name . '_entornoact');
        register_setting('tpv_redsys_settings', $this->option_name . '_moneda');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Configuración TPV Redsys</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('tpv_redsys_settings');
                do_settings_sections('tpv_redsys_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Código FUC</th>
                        <td>
                            <input type="text" name="<?php echo $this->option_name; ?>_idfuc" 
                                   value="<?php echo esc_attr(get_option($this->option_name . '_idfuc')); ?>" />
                            <p class="description">Tu código de comercio proporcionado por el banco</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Terminal</th>
                        <td>
                            <input type="text" name="<?php echo $this->option_name; ?>_terminal" 
                                   value="<?php echo esc_attr(get_option($this->option_name . '_terminal', '1')); ?>" />
                            <p class="description">Número de terminal (normalmente 1)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Clave de encriptación</th>
                        <td>
                            <input type="password" name="<?php echo $this->option_name; ?>_encriptkey" 
                                   value="<?php echo esc_attr(get_option($this->option_name . '_encriptkey')); ?>" />
                            <p class="description">Clave secreta proporcionada por el banco</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Entorno</th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>_entornoact">
                                <option value="test" <?php selected(get_option($this->option_name . '_entornoact', 'test'), 'test'); ?>>Pruebas</option>
                                <option value="real" <?php selected(get_option($this->option_name . '_entornoact'), 'real'); ?>>Producción</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Moneda</th>
                        <td>
                            <select name="<?php echo $this->option_name; ?>_moneda">
                                <option value="978" <?php selected(get_option($this->option_name . '_moneda', '978'), '978'); ?>>EUR (€)</option>
                                <option value="840" <?php selected(get_option($this->option_name . '_moneda'), '840'); ?>>USD ($)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Uso del shortcode</h2>
            <p>Usa el shortcode: <code>[tpv_redsys]Texto del formulario[/tpv_redsys]</code></p>
            <p>Parámetros opcionales:</p>
            <ul>
                <li><code>c="25.50"</code> - Prefijar cantidad</li>
                <li><code>concepto="Descripción"</code> - Prefijar concepto del pago</li>
                <li><code>title="Mi TPV"</code> - Cambiar título del formulario</li>
            </ul>
            <p><strong>Nota:</strong> El número de pedido se genera automáticamente para cada transacción.</p>
            
            <div style="margin-top: 30px; padding: 20px; background: #f1f1f1; border-radius: 5px;">
                <h3>Información de la instalación</h3>
                <p><strong>Páginas creadas automáticamente:</strong></p>
                <ul>
                    <li><strong>TPV Principal:</strong> <a href="<?php echo home_url('/tpv-redsys/'); ?>" target="_blank"><?php echo home_url('/tpv-redsys/'); ?></a></li>
                    <li><strong>Pago Exitoso:</strong> <a href="<?php echo home_url('/tpv-pago-exitoso/'); ?>" target="_blank"><?php echo home_url('/tpv-pago-exitoso/'); ?></a></li>
                    <li><strong>Error en Pago:</strong> <a href="<?php echo home_url('/tpv-pago-error/'); ?>" target="_blank"><?php echo home_url('/tpv-pago-error/'); ?></a></li>
                </ul>
                <p><strong>URLs para configurar en Redsys:</strong></p>
                <ul>
                    <li><strong>URL OK:</strong> <?php echo home_url('/tpv-pago-exitoso/'); ?></li>
                    <li><strong>URL KO:</strong> <?php echo home_url('/tpv-pago-error/'); ?></li>
                    <li><strong>URL Notificación:</strong> <?php echo home_url('/tpv-pago-exitoso/'); ?></li>
                </ul>
                <p><strong>Características del plugin:</strong></p>
                <ul>
                    <li>✅ Generación automática OBLIGATORIA de números de pedido</li>
                    <li>✅ Diseño moderno y responsivo</li>
                    <li>✅ Validaciones de formulario mejoradas</li>
                    <li>✅ Páginas TPV creadas automáticamente</li>
                    <li>✅ Páginas de resultado (OK/KO) dedicadas</li>
                    <li>✅ URLs limpias sin parámetros GET</li>
                </ul>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #007cba; margin-top: 20px;">
                    <h4>📋 Instrucciones para activar las páginas:</h4>
                    <ol>
                        <li><strong>Desactiva</strong> el plugin desde el panel de WordPress</li>
                        <li><strong>Vuelve a activarlo</strong> para que se creen las nuevas páginas automáticamente</li>
                        <li>Las páginas se crearán con los slugs:
                            <ul>
                                <li><code>/tpv-redsys/</code> - Formulario principal</li>
                                <li><code>/tpv-pago-exitoso/</code> - Página de éxito</li>
                                <li><code>/tpv-pago-error/</code> - Página de error</li>
                            </ul>
                        </li>
                        <li>Configura las URLs en tu panel de Redsys con las URLs mostradas arriba</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Función de activación
     */
    public function activate() {
        // Crear página TPV automáticamente
        $page_title = 'TPV Redsys';
        $page_content = '[tpv_redsys][/tpv_redsys]';
        $page_slug = 'tpv-redsys';
        
        // Verificar si la página ya existe
        $page_exists = get_page_by_path($page_slug);
        
        if (!$page_exists) {
            $page_data = array(
                'post_title' => $page_title,
                'post_content' => $page_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $page_slug
            );
            wp_insert_post($page_data);
        }
        
        // Crear página de éxito TPV
        $success_page_title = 'Pago Exitoso - TPV';
        $success_page_content = '
                <div class="alert alert-success">
                    <h2>🎉 ¡Pago realizado con éxito!</h2>
                    <p>Su transacción ha sido procesada correctamente.</p>
                </div>
                <div style="text-align: center; margin-top: 30px;">
                    <a href="/tpv-redsys/" class="pay-button" style="display: inline-block; text-decoration: none; color: white;">
                        🔄 Realizar otro pago
                    </a>
                </div>
        
        <style>
        body { margin: 0; padding: 0; }
        .alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .pay-button { background: #007cba; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 18px; cursor: pointer; text-decoration: none; }
        .pay-button:hover { background: #005a87; }
        </style>';
        $success_page_slug = 'tpv-pago-exitoso';
        
        $success_page_exists = get_page_by_path($success_page_slug);
        if (!$success_page_exists) {
            $success_page_data = array(
                'post_title' => $success_page_title,
                'post_content' => $success_page_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $success_page_slug
            );
            wp_insert_post($success_page_data);
        }
        
        // Crear página de error TPV
        $error_page_title = 'Error en el Pago - TPV';
        $error_page_content = '
                <div class="alert alert-error">
                    <h2>❌ Error en el pago</h2>
                    <p>Lo sentimos, no se pudo procesar su transacción.</p>
                    <p>Por favor, inténtelo de nuevo o contacte con nosotros si el problema persiste.</p>
                </div>
                <div style="text-align: center; margin-top: 30px;">
                    <a href="/tpv-redsys/" class="pay-button" style="display: inline-block; text-decoration: none; color: white;">
                        🔄 Intentar de nuevo
                    </a>
                </div>

        
        <style>
        body { margin: 0; padding: 0; }
        .alert { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .pay-button { background: #007cba; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 18px; cursor: pointer; text-decoration: none; }
        .pay-button:hover { background: #005a87; }
        </style>';
        $error_page_slug = 'tpv-pago-error';
        
        $error_page_exists = get_page_by_path($error_page_slug);
        if (!$error_page_exists) {
            $error_page_data = array(
                'post_title' => $error_page_title,
                'post_content' => $error_page_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $error_page_slug
            );
            wp_insert_post($error_page_data);
        }
    }
}

/**
 * CLASE REDSYSAPI
 */
class RedsysAPI {
    
    private $vars_pay = array();
    
    public function set_parameter($key, $value) {
        $this->vars_pay[$key] = $value;
    }
    
    public function get_parameter($key) {
        return isset($this->vars_pay[$key]) ? $this->vars_pay[$key] : '';
    }
    
    /**
     * Cifrado 3DES
     */
    public function encrypt_3des($message, $key) {
        $l = ceil(strlen($message) / 8) * 8;
        return substr(openssl_encrypt($message . str_repeat("\0", $l - strlen($message)), 'des-ede3-cbc', $key, OPENSSL_RAW_DATA, "\0\0\0\0\0\0\0\0"), 0, $l);
    }
    
    /**
     * Base64 estándar (NO URL-safe)
     */
    public function encode_base64($data) {
        return base64_encode($data);
    }
    
    /**
     * Base64 URL decode (para notificaciones)
     */
    public function base64_url_decode($input) {
        return base64_decode(strtr($input, '-_', '+/'));
    }
    
    /**
     * Decodificar base64
     */
    public function decode_base_64($data) {
        return base64_decode($data);
    }
    
    /**
     * HMAC SHA256
     */
    public function mac256($ent, $key) {
        return hash_hmac('sha256', $ent, $key, true);
    }
    
    /**
     * Obtener número de pedido
     */
    public function get_order() {
        if (!empty($this->vars_pay['DS_MERCHANT_ORDER'])) {
            return $this->vars_pay['DS_MERCHANT_ORDER'];
        } else {
            return $this->vars_pay['Ds_Merchant_Order'];
        }
    }
    
    /**
     * Obtener número de pedido de notificación
     */
    public function get_order_notif() {
        if (!empty($this->vars_pay['Ds_Order'])) {
            return $this->vars_pay['Ds_Order'];
        } else {
            return $this->vars_pay['DS_ORDER'];
        }
    }
    
    /**
     * Convertir array a JSON
     */
    public function array_to_json() {
        return json_encode($this->vars_pay);
    }
    
    /**
     * Crear parámetros merchant
     */
    public function create_merchant_parameters() {
        $json = $this->array_to_json();
        return $this->encode_base64($json); // Base64 estándar
    }
    
    /**
     * Crear firma merchant
     */
    public function create_merchant_signature($key) {
        // Decodificar clave
        $key = $this->decode_base_64($key);
        // Generar parámetros
        $ent = $this->create_merchant_parameters();
        // Diversificar clave con número de pedido
        $key = $this->encrypt_3des($this->get_order(), $key);
        // HMAC SHA256
        $res = $this->mac256($ent, $key);
        // Base64 estándar
        return $this->encode_base64($res);
    }
    
    /**
     * String to array
     */
    public function string_to_array($datos_decod) {
        $this->vars_pay = json_decode($datos_decod, true);
    }
    
    /**
     * Decodificar parámetros merchant
     */
    public function decode_merchant_parameters($datos) {
        $decodec = $this->base64_url_decode($datos);
        $this->string_to_array($decodec);
        return $decodec;
    }
    
    /**
     * Crear firma de notificación
     */
    public function create_merchant_signature_notif($key, $datos) {
        // Decodificar clave
        $key = $this->decode_base_64($key);
        // Decodificar datos
        $decodec = $this->base64_url_decode($datos);
        // Pasar a array
        $this->string_to_array($decodec);
        // Diversificar clave
        $key = $this->encrypt_3des($this->get_order_notif(), $key);
        // HMAC
        $res = $this->mac256($datos, $key);
        // Base64 URL-safe para notificaciones
        return $this->base64_url_encode($res);
    }
    
    /**
     * Base64 URL encode (para notificaciones)
     */
    public function base64_url_encode($input) {
        return strtr(base64_encode($input), '+/', '-_');
    }
}

// Inicializar el plugin
new TPV_Redsys();

// Crear directorio de assets si no existe y generar archivos CSS
add_action('wp_loaded', function() {
    $assets_dir = TPV_REDSYS_PLUGIN_DIR . 'assets';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
        
    // Crear archivo CSS con el diseño mejorado del plugin anterior
    if (!file_exists($assets_dir . '/style.css')) {
        $css_content = '
body {
    margin: 0;
    padding: 0;
}

.tpv-container {
    max-width: 600px;
    width: 100%;
    padding: 30px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 0 20px rgba(0,0,0,0.1);
}

.tpv-form {
    margin-top: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
    color: #333;
}

.form-group input, .form-group select {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 5px;
    font-size: 16px;
    box-sizing: border-box;
}

.form-group input:focus, .form-group select:focus {
    border-color: #007cba;
    outline: none;
}

.pay-button {
    background: #007cba;
    color: white;
    padding: 15px 30px;
    border: none;
    border-radius: 5px;
    font-size: 18px;
    cursor: pointer;
    width: 100%;
    margin-top: 20px;
}

.pay-button:hover {
    background: #005a87;
}

.form-button {
    text-align: center;
}

.alert {
    padding: 15px;
    margin: 20px 0;
    border-radius: 5px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.amount-display {
    font-size: 24px;
    font-weight: bold;
    color: #007cba;
    text-align: center;
    margin: 20px 0;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 5px;
}

.tpv-info {
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
    font-size: 14px;
    color: #666;
}

.tpv-info h3 {
    margin-top: 0;
    color: #333;
}

.tpv-info ul {
    margin: 10px 0;
    padding-left: 20px;
}

.description {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.tpv-success {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    margin: 20px 0;
    border: 1px solid #c3e6cb;
    border-radius: 5px;
}

.tpv-error {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    margin: 20px 0;
    border: 1px solid #f5c6cb;
    border-radius: 5px;
}
';
        
        file_put_contents($assets_dir . '/style.css', $css_content);
    }
        
    // Crear script.js mejorado
    if (!file_exists($assets_dir . '/script.js')) {
        file_put_contents($assets_dir . '/script.js', '
// TPV Redsys
jQuery(document).ready(function($) {
    // Formatear entrada de importes solo al salir del campo (blur)
    $("#amountTPV").on("blur", function() {
        var value = $(this).val();
        if (value && !isNaN(value) && value !== "") {
            $(this).val(parseFloat(value).toFixed(2));
        }
    });
    
    // Validación en tiempo real
    $("#orderNumber").on("input", function() {
        var value = $(this).val();
        var length = value.length;
        var feedback = $(this).siblings(".validation-feedback");
        
        if (feedback.length === 0) {
            $(this).after("<small class=\"validation-feedback\"></small>");
            feedback = $(this).siblings(".validation-feedback");
        }
        
        if (value === "") {
            feedback.text("Se generará automáticamente").css("color", "#666");
        } else if (length < 4) {
            feedback.text("Mínimo 4 caracteres").css("color", "#dc3545");
        } else if (length > 8) {
            feedback.text("Máximo 8 caracteres").css("color", "#dc3545");
        } else {
            feedback.text("✓ Válido").css("color", "#28a745");
        }
    });
});
');
    }
});
?>
