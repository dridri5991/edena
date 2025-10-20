<?php
/**
 * Plugin Name: Edena – Garantie par carte (réservations Amelia)
 * Description: Unifie : bloc "garantie par carte" au checkout réservation, SetupIntent Stripe (enregistrement carte + 3DS si besoin), pré-alerte panier sur pages Amelia, dédoublonnage Stripe v3, libellé bouton "Réserver", placement du texte de confidentialité sous le bouton, endpoints AJAX serveur (Stripe).
 * Version: 1.0.0
 * Author: Edena
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('EDENA_GC_VER', '1.0.0');
define('EDENA_GC_DIR', plugin_dir_path(__FILE__));
define('EDENA_GC_URL', plugin_dir_url(__FILE__));

// -----------------------------
// Utilitaires
// -----------------------------
function edena_gc_is_resa_product( $product_id ){
    if ( ! $product_id ) return false;
    if ( has_term( 'reservation-amelia', 'product_cat', $product_id ) || has_term( 'reservation-amelia', 'product_tag', $product_id ) ) return true;
    $parent = wp_get_post_parent_id( $product_id );
    return $parent ? ( has_term( 'reservation-amelia', 'product_cat', $parent ) || has_term( 'reservation-amelia', 'product_tag', $parent ) ) : false;
}
function edena_gc_cart_is_resa(){
    if ( ! function_exists('WC') || ! WC()->cart ) return false;
    foreach ( WC()->cart->get_cart() as $ci ){
        $p = $ci['data'];
        if ( $p && edena_gc_is_resa_product( $p->get_id() ) ) return true;
    }
    return false;
}
function edena_gc_is_resa_checkout_ctx(){
    if ( ! function_exists('is_checkout') || ! is_checkout() ) return false;
    if ( function_exists('is_checkout_pay_page') && is_checkout_pay_page() ) return false;
    return edena_gc_cart_is_resa();
}

// -----------------------------
// Settings (clé secrète Stripe)
// -----------------------------
add_action('admin_init', function(){
    register_setting('edena_gc', 'edena_gc_secret_key');
    register_setting('edena_gc', 'edena_gc_precheck_pages', ['default' => 'service-catalogue,service-catalogue-homme,service-catalogue-enfant']);
});
add_action('admin_menu', function(){
    add_options_page('Edena Garantie Carte', 'Edena Garantie Carte', 'manage_options', 'edena-gc', function(){
        ?>
        <div class="wrap">
          <h1>Edena – Garantie par carte</h1>
          <form method="post" action="options.php">
            <?php settings_fields('edena_gc'); do_settings_sections('edena_gc'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="edena_gc_secret_key">Stripe Secret key (sk_...)</label></th>
                <td><input name="edena_gc_secret_key" id="edena_gc_secret_key" type="password" value="<?php echo esc_attr(get_option('edena_gc_secret_key','')); ?>" class="regular-text" placeholder="sk_live_... / sk_test_..."><p class="description">Clé secrète utilisée côté serveur pour créer les SetupIntents et attacher les cartes.</p></td>
              </tr>
              <tr>
                <th scope="row"><label for="edena_gc_precheck_pages">Pages de réservation (slugs séparés par des virgules)</label></th>
                <td><input name="edena_gc_precheck_pages" id="edena_gc_precheck_pages" type="text" value="<?php echo esc_attr(get_option('edena_gc_precheck_pages','service-catalogue,service-catalogue-homme,service-catalogue-enfant')); ?>" class="regular-text"></td>
              </tr>
            </table>
            <?php submit_button(); ?>
          </form>
        </div>
        <?php
    });
});

// -----------------------------
// Détecter/Exposer la PK Stripe côté front
// -----------------------------
function edena_gc_guess_pk(){
    $s=(array)get_option('woocommerce_stripe_settings',[]);
    $u=(array)get_option('woocommerce_stripe_upe_settings',[]);
    $w=(array)get_option('woocommerce_woocommerce_payments_settings',[]);
    $flags=['testmode','test_mode','enabled_test_mode']; $is=false;
    foreach($flags as $f){ foreach([$s,$u,$w] as $o){ if(isset($o[$f]) && ($o[$f]==='yes'||$o[$f]==='1'||$o[$f]===1)){ $is=true; break 2; } } }
    $c=$is ? [$s['test_publishable_key']??null,$u['test_publishable_key']??null,$w['test_publishable_key']??null]
           : [$s['publishable_key']??null,     $u['publishable_key']??null,     $w['publishable_key']??null];
    $opt=get_option('edena_publishable_key'); if(!empty($opt)) $c[]=$opt;
    foreach($c as $pk){ $pk=is_string($pk)?trim($pk):''; if($pk!=='') return $pk; }
    return '';
}
add_action('wp_head', function(){
    if ( ! edena_gc_is_resa_checkout_ctx() ) return;
    $opts = [
        'publishableKey' => edena_gc_guess_pk(),
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('edena_gc_nonce'),
    ];
    echo '<script>window.EDENA_STRIPE_OPTS = Object.assign((window.EDENA_STRIPE_OPTS||{}),'.wp_json_encode($opts).');</script>';
}, 6);

// -----------------------------
// Bloc "Garantie par carte"
// -----------------------------
add_action('woocommerce_review_order_before_submit', function(){
    if ( ! is_checkout() || ! edena_gc_cart_is_resa() ) return; ?>
    <div class="woocommerce-info edena-card-wrap" id="edena-card-wrap">
      <div class="edena-card-text">
        <strong>Garantie par carte</strong>
        <p>Aucun débit immédiat maintenant. En cas de no-show/annulation tardive, un prélèvement pourra être effectué selon nos conditions.</p>
      </div>
      <div class="edena-card-field">
        <div id="edena-card-element" aria-label="Champ carte"></div>
        <p id="edena-card-errors" class="edena-card-errors" role="alert" aria-live="polite"></p>
      </div>
    </div>
    <input type="hidden" name="edena_gc_customer" id="edena_gc_customer" value="">
    <input type="hidden" name="edena_gc_payment_method" id="edena_gc_payment_method" value="">
<?php }, 5);

// -----------------------------
// Enqueue JS/CSS sur checkout réservation
// -----------------------------
add_action('wp_enqueue_scripts', function(){
    if ( ! edena_gc_is_resa_checkout_ctx() ) return;
    wp_enqueue_script('edena-gc-setupintent', EDENA_GC_URL.'assets/js/edena-setupintent.js', ['stripe'], EDENA_GC_VER, true);
    wp_enqueue_style('edena-gc-card', EDENA_GC_URL.'assets/css/edena-card.css', [], EDENA_GC_VER);
}, 20);

// -----------------------------
// Dédoublonnage Stripe v3
// -----------------------------
add_action('wp_print_scripts', function(){
    $wp_scripts = wp_scripts(); if (!$wp_scripts) return;
    $seen = false;
    foreach ((array)$wp_scripts->queue as $h){
        $reg = $wp_scripts->registered[$h] ?? null; if (!$reg) continue;
        $src = $reg->src;
        if ($src && strpos($src,'http')!==0) $src = $wp_scripts->base_url.$src;
        if ($src && strpos($src,'js.stripe.com/v3')!==false){
            if ($seen){ wp_dequeue_script($h); wp_deregister_script($h); }
            else { $seen = true; }
        }
    }
}, 100);
add_filter('script_loader_tag', function($tag, $handle, $src){
    static $printed = false;
    if (strpos($src, 'js.stripe.com/v3') !== false) {
        if ($printed) return '';
        $printed = true;
        $src = 'https://js.stripe.com/v3/';
        return sprintf('<script src="%s" id="%s-js"></script>'."\n", esc_url($src), esc_attr($handle));
    }
    return $tag;
}, 10, 3);

// -----------------------------
// Libellé bouton → "Réserver"
// -----------------------------
add_filter('woocommerce_order_button_text', function($txt){
    return edena_gc_cart_is_resa() ? __('Réserver', 'woocommerce') : $txt;
});

// -----------------------------
// Privacy sous le bouton
// -----------------------------
add_action('init', function(){
    remove_action('woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20);
    add_action('woocommerce_review_order_after_submit', 'wc_checkout_privacy_policy_text', 5);
});

// -----------------------------
// Pré-alerte sur pages Amelia
// -----------------------------
function edena_gc_is_precheck_target(){
    if ( ! is_singular() ) return false;
    $slugs = array_filter(array_map('trim', explode(',', get_option('edena_gc_precheck_pages','service-catalogue,service-catalogue-homme,service-catalogue-enfant'))));
    if ( empty($slugs) ) return false;
    return is_page($slugs);
}
function edena_gc_cart_has_non_resa(){
    if ( ! function_exists('WC') || ! WC()->cart ) return false;
    foreach ( WC()->cart->get_cart() as $ci ){
        $p = $ci['data'];
        if ( $p && ! edena_gc_is_resa_product( $p->get_id() ) ) return true;
    }
    return false;
}
add_action('wp_footer', function(){
    if ( ! edena_gc_is_precheck_target() || ! edena_gc_cart_has_non_resa() ) return;
    $ajax  = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('edena_gc_precheck');
    $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/panier/');
    ?>
    <style>
      .edena-precheck {position:fixed;top:0;left:0;right:0;z-index:9999;background:#fde68a;color:#7c2d12;border-bottom:1px solid #f59e0b;font-size:14px}
      .edena-precheck__inner{max-width:1120px;margin:0 auto;padding:12px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
      .edena-precheck strong{font-weight:600}
      .edena-precheck__actions{margin-left:auto;display:flex;gap:8px}
      .edena-precheck .button{border-radius:4px;padding:8px 12px;line-height:1}
      .edena-precheck .button-primary{background:#b45309;border-color:#b45309;color:#fff}
      .edena-precheck .button-primary:disabled{opacity:.6}
      html{scroll-padding-top:56px} body{padding-top:56px}
      @media (max-width:782px){ body{padding-top:72px} }
    </style>
    <div class="edena-precheck" role="region" aria-label="Alerte panier">
      <div class="edena-precheck__inner">
        <div>
          <strong>Vous avez déjà des produits dans votre panier.</strong>
          <span>Pour réserver, veuillez d’abord vider votre panier, ou terminer votre achat.</span>
        </div>
        <div class="edena-precheck__actions">
          <button id="edena-precheck-clear" class="button button-primary">Vider le panier</button>
          <a href="<?php echo esc_url($cart_url); ?>" class="button">Voir mon panier</a>
        </div>
      </div>
    </div>
    <script>
      (function(){
        var btn=document.getElementById('edena-precheck-clear'); if(!btn) return;
        btn.addEventListener('click',function(e){
          e.preventDefault(); btn.disabled=true;
          fetch('<?php echo esc_js($ajax); ?>', {
            method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=edena_gc_empty_cart&nonce=<?php echo esc_js($nonce); ?>'
          })
          .then(function(r){return r.json();})
          .then(function(res){ if(res&&res.success){ location.reload(); } else { btn.disabled=false; alert('Impossible de vider le panier.'); } })
          .catch(function(){ btn.disabled=false; alert('Erreur réseau.'); });
        });
      })();
    </script>
    <?php
});
add_action('wp_ajax_edena_gc_empty_cart', function(){
    check_ajax_referer('edena_gc_precheck','nonce');
    if ( function_exists('WC') && WC()->cart ){ WC()->cart->empty_cart(); wp_send_json_success(); }
    wp_send_json_error();
});
add_action('wp_ajax_nopriv_edena_gc_empty_cart', function(){ do_action('wp_ajax_edena_gc_empty_cart'); });

// -----------------------------
// Endpoints AJAX SetupIntent
// -----------------------------
function edena_gc_secret(){
    $sk = get_option('edena_gc_secret_key', '');
    return trim($sk);
}
function edena_gc_stripe_request($method, $path, $body = []){
    $sk = edena_gc_secret();
    if (empty($sk)) return new WP_Error('no_secret', 'Stripe secret key manquante.');
    $args = [
        'method'  => $method,
        'headers' => [
            'Authorization' => 'Basic '. base64_encode($sk.':'),
        ],
        'timeout' => 45,
    ];
    if (!empty($body)){
        $args['body'] = $body;
    }
    $res = wp_remote_request('https://api.stripe.com/v1/'.$path, $args);
    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300){
        return new WP_Error('stripe_error', isset($json['error']['message']) ? $json['error']['message'] : 'Erreur Stripe.');
    }
    return $json;
}

function edena_gc_get_or_create_customer($email, $name=''){
    // Chercher un customer lié à l'utilisateur WP
    $user = get_user_by('email', $email);
    if ($user){
        $cid = get_user_meta($user->ID, '_edena_stripe_customer_id', true);
        if ($cid) return $cid;
    }
    // Chercher par email côté Stripe (limité, ici on crée si absent)
    $res = edena_gc_stripe_request('POST', 'customers', [
        'email' => $email,
        'name'  => $name,
    ]);
    if (is_wp_error($res)) return $res;
    $customer_id = $res['id'];
    if ($user && $customer_id){
        update_user_meta($user->ID, '_edena_stripe_customer_id', $customer_id);
    }
    return $customer_id;
}

add_action('wp_ajax_edena_si_create', 'edena_gc_ajax_create_si');
add_action('wp_ajax_nopriv_edena_si_create', 'edena_gc_ajax_create_si');
function edena_gc_ajax_create_si(){
    check_ajax_referer('edena_gc_nonce', 'nonce');
    $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : (isset($_POST['email']) ? sanitize_email($_POST['email']) : '');
    if (!$email && is_user_logged_in()){
        $u = wp_get_current_user(); $email = $u && $u->user_email ? $u->user_email : '';
    }
    $name = '';
    if (isset($_POST['billing_first_name']) || isset($_POST['billing_last_name'])){
        $name = sanitize_text_field(($_POST['billing_first_name'] ?? '').' '.($_POST['billing_last_name'] ?? ''));
    }
    $customer = edena_gc_get_or_create_customer( $email, $name );
    if (is_wp_error($customer)) wp_send_json_error(['message'=>$customer->get_error_message()]);
    $si = edena_gc_stripe_request('POST', 'setup_intents', [
        'usage'    => 'off_session',
        'customer' => $customer,
        'confirm'  => 'false',
    ]);
    if (is_wp_error($si)) wp_send_json_error(['message'=>$si->get_error_message()]);
    wp_send_json_success([ 'client_secret' => $si['client_secret'], 'customer' => $customer ]);
}

add_action('wp_ajax_edena_si_attach', 'edena_gc_ajax_attach_pm');
add_action('wp_ajax_nopriv_edena_si_attach', 'edena_gc_ajax_attach_pm');
function edena_gc_ajax_attach_pm(){
    check_ajax_referer('edena_gc_nonce', 'nonce');
    $pm = isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '';
    $customer = isset($_POST['customer']) ? sanitize_text_field(wp_unslash($_POST['customer'])) : '';
    if (!$pm || !$customer) wp_send_json_error(['message'=>'Paramètres manquants.']);
    $res = edena_gc_stripe_request('POST', 'payment_methods/'.$pm.'/attach', [ 'customer' => $customer ]);
    if (is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
    $default = edena_gc_stripe_request('POST', 'customers/'.$customer, [ 'invoice_settings[default_payment_method]' => $pm ]);
    if (is_wp_error($default)) wp_send_json_error(['message'=>$default->get_error_message()]);
    wp_send_json_success(['ok'=>true, 'payment_method'=>$pm, 'customer'=>$customer]);
}

add_action('woocommerce_checkout_create_order', function($order){
    $customer = isset($_POST['edena_gc_customer']) ? sanitize_text_field(wp_unslash($_POST['edena_gc_customer'])) : '';
    $payment_method = isset($_POST['edena_gc_payment_method']) ? sanitize_text_field(wp_unslash($_POST['edena_gc_payment_method'])) : '';
    if (! $customer && ! $payment_method) return;
    if ($customer) {
        $order->update_meta_data('_edena_stripe_customer_id', $customer);
    }
    if ($payment_method) {
        $order->update_meta_data('_edena_stripe_payment_method', $payment_method);
    }
}, 20);
