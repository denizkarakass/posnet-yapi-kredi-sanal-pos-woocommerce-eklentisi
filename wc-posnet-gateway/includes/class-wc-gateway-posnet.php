<?php
if (!defined('ABSPATH')) exit;

class WC_Gateway_Posnet_Custom extends WC_Payment_Gateway {

  public function __construct() {
    $this->id = 'posnet_custom';
    $this->method_title = 'Posnet (Yapı Kredi)';
    $this->method_description = 'Posnet 3D Secure - Kart bilgisi işyeri sayfasında alınır.';
    $this->has_fields = true; // <<< KART ALANLARI AÇIK

    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title');
    $this->merchant_id = trim((string)$this->get_option('merchant_id'));
    $this->terminal_id = trim((string)$this->get_option('terminal_id'));
    $this->posnet_id   = trim((string)$this->get_option('posnet_id'));
    $this->enc_key     = trim((string)$this->get_option('enc_key'));

    $this->xml_url = trim((string)$this->get_option('xml_url'));
    $this->oos_url = trim((string)$this->get_option('oos_url'));

    $this->debug = ($this->get_option('debug') === 'yes');

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

    add_action('woocommerce_api_posnet_redirect', [$this, 'handle_redirect_form']);
    add_action('woocommerce_api_posnet_return',   [$this, 'handle_bank_return']);
  }

  public function init_form_fields() {
    $this->form_fields = [
      'enabled' => [
        'title'   => 'Etkinleştir',
        'type'    => 'checkbox',
        'label'   => 'Posnet ödeme yöntemini etkinleştir',
        'default' => 'no',
      ],
      'title' => [
        'title'   => 'Başlık',
        'type'    => 'text',
        'default' => 'Kredi/Banka Kartı (Yapı Kredi)',
      ],
      'merchant_id' => ['title' => 'MID', 'type' => 'text'],
      'terminal_id' => ['title' => 'TID', 'type' => 'text'],
      'posnet_id'   => ['title' => 'Posnet ID', 'type' => 'text'],
      'enc_key'     => ['title' => 'ENCKEY', 'type' => 'text'],

      'xml_url' => [
        'title'   => 'XML_SERVICE_URL',
        'type'    => 'text',
        'default' => 'https://setmpos.ykb.com/PosnetWebService/XML',
      ],
      'oos_url' => [
        'title'   => 'OOS_TDS_SERVICE_URL',
        'type'    => 'text',
        'default' => 'https://setmpos.ykb.com/3DSWebService/YKBPaymentService',
      ],
      'debug' => [
        'title'   => 'Debug log',
        'type'    => 'checkbox',
        'label'   => 'WooCommerce loglarına istek/yanıt yaz (kart verisi maskelenir)',
        'default' => 'yes',
      ],
    ];
  }

  /** Checkout alanları (kartı sizin sitenizde alır) */
  public function payment_fields() {
    echo '<fieldset id="wc-posnet-cc-form" style="margin:0;padding:0;border:0;">';

    echo '<p class="form-row form-row-wide">
      <label>Kart Üzerindeki İsim <span class="required">*</span></label>
      <input type="text" name="posnet_name" autocomplete="cc-name" />
    </p>';

    echo '<p class="form-row form-row-wide">
      <label>Kart Numarası <span class="required">*</span></label>
      <input type="text" name="posnet_ccno" inputmode="numeric" autocomplete="cc-number" placeholder="#### #### #### ####" />
    </p>';

    echo '<p class="form-row form-row-first">
  <label>Son Kullanma (AA/YY) <span class="required">*</span></label>
  <input
    type="text"
    name="posnet_exp"
    id="posnet_exp"
    inputmode="numeric"
    autocomplete="cc-exp"
    placeholder="AA/YY"
    maxlength="5"
  />
</p>';


    echo '<p class="form-row form-row-last">
      <label>CVV <span class="required">*</span></label>
      <input type="password" name="posnet_cvc" inputmode="numeric" autocomplete="cc-csc" placeholder="***" />
    </p>';

    echo '<div style="clear:both"></div>';
    echo "<script>
(function(){
  const el = document.getElementById('posnet_exp');
  if(!el) return;

  el.addEventListener('input', function(){
    let v = el.value || '';
    v = v.replace(/\\D/g,'').slice(0,4); // sadece 4 rakam (MMYY)
    if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
    el.value = v;
  });

  el.addEventListener('keydown', function(e){
    if (e.key === 'Backspace' && (el.value || '').endsWith('/')) {
      el.value = el.value.slice(0, -1);
    }
  });
})();
</script>";

    echo '</fieldset>';
  }

  /** Alan doğrulama */
  public function validate_fields() {
    $name = trim((string)($_POST['posnet_name'] ?? ''));
    $ccno = preg_replace('/\D+/', '', (string)($_POST['posnet_ccno'] ?? ''));
    $exp  = trim((string)($_POST['posnet_exp'] ?? ''));
    $cvc  = preg_replace('/\D+/', '', (string)($_POST['posnet_cvc'] ?? ''));

    if ($name === '') {
      wc_add_notice('Kart üzerindeki isim zorunludur.', 'error');
      return false;
    }
    if (strlen($ccno) < 13 || strlen($ccno) > 19) {
      wc_add_notice('Kart numarası hatalı görünüyor.', 'error');
      return false;
    }
    if ($this->exp_to_yymm($exp) === null) {
      wc_add_notice('Son kullanma tarihi hatalı. Örn: 07/28', 'error');
      return false;
    }
    if (strlen($cvc) < 3 || strlen($cvc) > 4) {
      wc_add_notice('CVV hatalı.', 'error');
      return false;
    }
    return true;
  }

  public function process_payment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
      wc_add_notice('Sipariş bulunamadı.', 'error');
      return ['result' => 'fail'];
    }

    // Kart alanlarını al (KAYDETME, META'YA YAZMA)
    $cardHolderName = trim((string)($_POST['posnet_name'] ?? ''));
    $ccno = preg_replace('/\D+/', '', (string)($_POST['posnet_ccno'] ?? ''));
    $cvc  = preg_replace('/\D+/', '', (string)($_POST['posnet_cvc'] ?? ''));
    $expYyMm = $this->exp_to_yymm((string)($_POST['posnet_exp'] ?? '')); // Posnet: YYAA :contentReference[oaicite:2]{index=2}

    if ($expYyMm === null) {
      wc_add_notice('Son kullanma tarihi hatalı.', 'error');
      return ['result' => 'fail'];
    }

    // XID
    $xid = strtoupper(substr('WC' . $order_id . gmdate('ymdHis'), 0, 20));

    // amount: kuruş
    $amount_kurus = (string) round(((float)$order->get_total()) * 100);

    // currencyCode: "TL, US, EU" :contentReference[oaicite:3]{index=3}
    $currencyCode = $this->map_posnet_currency(get_woocommerce_currency());

    $installment = '00';
    $tranType = 'Sale';

    // Şifreleme isteği: cardHolderName/ccno/expDate/cvc DOLU GÖNDERİLİR :contentReference[oaicite:4]{index=4}
    $xml = $this->build_oos_request_data_xml(
      $xid, $amount_kurus, $currencyCode, $installment, $tranType,
      $cardHolderName, $ccno, $expYyMm, $cvc
    );

    $resp = $this->posnet_post_xml($xml, 'oosRequestData');

    if (!$resp || (string)$resp->approved !== '1') {
      $respText = $resp ? (string)$resp->respText : 'No response';
      wc_add_notice('Posnet şifreleme hatası: ' . $respText, 'error');
      return ['result' => 'fail'];
    }

    $data1 = (string)$resp->oosRequestDataResponse->data1;
    $data2 = (string)$resp->oosRequestDataResponse->data2;
    $sign  = (string)$resp->oosRequestDataResponse->sign;

    // Sadece şifreli alanları sakla (kart/CVV saklama!)
    $order->update_meta_data('_posnet_xid', $xid);
    $order->update_meta_data('_posnet_amount', $amount_kurus);
    $order->update_meta_data('_posnet_currency', $currencyCode);
    $order->update_meta_data('_posnet_data1', $data1);
    $order->update_meta_data('_posnet_data2', $data2);
    $order->update_meta_data('_posnet_sign', $sign);
    $order->save();

    $redirect = add_query_arg([
      'wc-api' => 'posnet_redirect',
      'order_id' => $order_id,
    ], home_url('/'));

    $order->update_status('pending', 'Posnet: 3D doğrulama bekleniyor.');
    return ['result' => 'success', 'redirect' => $redirect];
  }

  public function handle_redirect_form() {
    $order_id = absint($_GET['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) wp_die('Order not found');

    $data1 = (string)$order->get_meta('_posnet_data1');
    $data2 = (string)$order->get_meta('_posnet_data2');
    $sign  = (string)$order->get_meta('_posnet_sign');

    $return_url = add_query_arg(['wc-api' => 'posnet_return', 'order_id' => $order_id], home_url('/'));

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Yönlendiriliyor...</title></head>';
    echo '<body onload="document.forms[0].submit()">';
    echo '<form method="post" action="'.esc_attr($this->oos_url).'">';
    echo '<input type="hidden" name="mid" value="'.esc_attr($this->merchant_id).'">';
    echo '<input type="hidden" name="posnetID" value="'.esc_attr($this->posnet_id).'">';
    echo '<input type="hidden" name="posnetData" value="'.esc_attr($data1).'">';
    echo '<input type="hidden" name="posnetData2" value="'.esc_attr($data2).'">';
    echo '<input type="hidden" name="digest" value="'.esc_attr($sign).'">';
    echo '<input type="hidden" name="merchantReturnURL" value="'.esc_attr($return_url).'">';
    echo '<input type="hidden" name="lang" value="tr">';
    echo '<input type="hidden" name="openANewWindow" value="0">';
    echo '</form></body></html>';
    exit;
  }

  public function handle_bank_return() {
    $order_id = absint($_GET['order_id'] ?? 0);
    $order = wc_get_order($order_id);
    if (!$order) wp_die('Order not found');

    $merchantPacket = wc_clean($_POST['MerchantPacket'] ?? '');
    $bankPacket     = wc_clean($_POST['BankPacket'] ?? '');
    $sign           = wc_clean($_POST['Sign'] ?? '');

    if (!$merchantPacket || !$bankPacket || !$sign) {
      $order->update_status('failed', 'Posnet: Banka dönüş parametreleri eksik.');
      wp_safe_redirect($order->get_checkout_order_received_url());
      exit;
    }

    $xid      = (string)$order->get_meta('_posnet_xid');
    $amount   = (string)$order->get_meta('_posnet_amount');
    $currency = (string)$order->get_meta('_posnet_currency');

    $mac = $this->make_mac($xid, $amount, $currency, $this->merchant_id);

    $resolve_xml = $this->build_oos_resolve_xml($bankPacket, $merchantPacket, $sign, $mac);
    $resolve = $this->posnet_post_xml($resolve_xml, 'oosResolveMerchantData');

    if (!$resolve || (string)$resolve->approved !== '1') {
      $respText = $resolve ? (string)$resolve->respText : 'No response';
      $order->update_status('failed', 'Posnet: oosResolveMerchantData başarısız. ' . $respText);
      wp_safe_redirect($order->get_checkout_order_received_url());
      exit;
    }

    $rxid    = (string)$resolve->oosResolveMerchantDataResponse->xid;
    $ramount = (string)$resolve->oosResolveMerchantDataResponse->amount;

    if ($rxid !== $xid || $ramount !== $amount) {
      $order->update_status('failed', 'Posnet: xid/amount doğrulaması tutmadı.');
      wp_safe_redirect($order->get_checkout_order_received_url());
      exit;
    }

    $tran_xml = $this->build_oos_tran_xml($bankPacket, '0', $mac);
    $tran = $this->posnet_post_xml($tran_xml, 'oosTranData');

    if ($tran && (string)$tran->approved === '1') {
      $order->payment_complete((string)$tran->hostlogkey);
      $order->add_order_note('Posnet: Ödeme onaylandı. authCode='.(string)$tran->authCode);
    } else {
      $respText = $tran ? (string)$tran->respText : 'No response';
      $order->update_status('failed', 'Posnet: Finansallaştırma başarısız. ' . $respText);
    }

    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
  }

  private function posnet_post_xml(string $xml_raw, string $label) {
    $logger = wc_get_logger();

    $body = 'xmldata=' . rawurlencode($xml_raw);

    $headers = [
      'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
      'X-MERCHANT-ID' => $this->merchant_id,
      'X-TERMINAL-ID' => $this->terminal_id,
      'X-POSNET-ID'   => $this->posnet_id,
      'X-CORRELATION-ID' => wp_generate_uuid4(),
    ];

    if ($this->debug) {
      $logger->debug('POSNET '.$label.' xmldata(raw): ' . $this->mask_sensitive_xml($xml_raw), ['source' => 'posnet']);
    }

    $res = wp_remote_post($this->xml_url, [
      'headers' => $headers,
      'body' => $body,
      'timeout' => 30,
    ]);

    if (is_wp_error($res)) {
      if ($this->debug) {
        $logger->debug('POSNET '.$label.' wp_error: ' . $res->get_error_message(), ['source' => 'posnet']);
      }
      return null;
    }

    $resp_body = (string) wp_remote_retrieve_body($res);

    if ($this->debug) {
      $logger->debug('POSNET '.$label.' response(raw): ' . $resp_body, ['source' => 'posnet']);
    }

    if (!$resp_body) return null;

    libxml_use_internal_errors(true);
    return simplexml_load_string($resp_body);
  }

  /** Şifreleme XML’i (kart sizdeyse dolu gönderilir) */
  private function build_oos_request_data_xml($xid, $amount, $currencyCode, $installment, $tranType,
                                            $cardHolderName, $ccno, $expDateYYMM, $cvc) {

    // Doküman: expDate YYAA (YYMM) :contentReference[oaicite:5]{index=5}
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<posnetRequest>';
    $xml .= '<mid>'.$this->merchant_id.'</mid>';
    $xml .= '<tid>'.$this->terminal_id.'</tid>';
    $xml .= '<oosRequestData>';
    $xml .= '<posnetid>'.$this->posnet_id.'</posnetid>';
    $xml .= '<XID>'.$this->xml_escape($xid).'</XID>';
    $xml .= '<amount>'.$this->xml_escape($amount).'</amount>';
    $xml .= '<currencyCode>'.$this->xml_escape($currencyCode).'</currencyCode>';
    $xml .= '<installment>'.$this->xml_escape($installment).'</installment>';
    $xml .= '<tranType>'.$this->xml_escape($tranType).'</tranType>';
    $xml .= '<cardHolderName>'.$this->xml_escape($this->sanitize_cardholder_name($cardHolderName)).'</cardHolderName>';
    $xml .= '<ccno>'.$this->xml_escape($ccno).'</ccno>';
    $xml .= '<expDate>'.$this->xml_escape($expDateYYMM).'</expDate>';
    $xml .= '<cvc>'.$this->xml_escape($cvc).'</cvc>';
    $xml .= '</oosRequestData>';
    $xml .= '</posnetRequest>';

    return $xml;
  }

  private function build_oos_resolve_xml($bankData, $merchantData, $sign, $mac) {
    return '<?xml version="1.0" encoding="UTF-8"?>'
      .'<posnetRequest>'
      .'<mid>'.$this->merchant_id.'</mid>'
      .'<tid>'.$this->terminal_id.'</tid>'
      .'<oosResolveMerchantData>'
      .'<bankData>'.$this->xml_escape($bankData).'</bankData>'
      .'<merchantData>'.$this->xml_escape($merchantData).'</merchantData>'
      .'<sign>'.$this->xml_escape($sign).'</sign>'
      .'<mac>'.$this->xml_escape($mac).'</mac>'
      .'</oosResolveMerchantData>'
      .'</posnetRequest>';
  }

  private function build_oos_tran_xml($bankData, $wpAmount, $mac) {
    return '<?xml version="1.0" encoding="UTF-8"?>'
      .'<posnetRequest>'
      .'<mid>'.$this->merchant_id.'</mid>'
      .'<tid>'.$this->terminal_id.'</tid>'
      .'<oosTranData>'
      .'<bankData>'.$this->xml_escape($bankData).'</bankData>'
      .'<wpAmount>'.$this->xml_escape($wpAmount).'</wpAmount>'
      .'<mac>'.$this->xml_escape($mac).'</mac>'
      .'</oosTranData>'
      .'</posnetRequest>';
  }

  private function xml_escape($s) {
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
  }

  private function mask_sensitive_xml($xml) {
    $xml = (string)$xml;
    $xml = preg_replace('/<ccno>\d+<\/ccno>/', '<ccno>****MASKED****</ccno>', $xml);
    $xml = preg_replace('/<cvc>\d+<\/cvc>/', '<cvc>***</cvc>', $xml);
    return $xml;
  }

  private function sanitize_cardholder_name($name) {
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);

    // Türkçe karakterleri ASCII'ye indir (servis hassas olabilir)
    $map = [
      'Ç'=>'C','Ö'=>'O','Ş'=>'S','İ'=>'I','I'=>'I','Ü'=>'U','Ğ'=>'G',
      'ç'=>'C','ö'=>'O','ş'=>'S','ı'=>'I','i'=>'I','ü'=>'U','ğ'=>'G',
    ];
    $name = strtr($name, $map);

    $name = preg_replace('/[^A-Za-z0-9 ]/', '', $name);
    $name = strtoupper($name);
    return substr($name, 0, 40);
  }

  /** Kullanıcı AA/YY veya AAYY girse bile Posnet YYAA (YYMM) döndürür */
  private function exp_to_yymm($exp) {
    $d = preg_replace('/\D+/', '', (string)$exp);
    if (strlen($d) !== 4) return null;

    $a = substr($d, 0, 2);
    $b = substr($d, 2, 2);

    $mm = (int)$a;
    $yy = (int)$b;

    // Çoğu kullanıcı MMYY girer: 0728 -> YYMM = 2807
    if ($mm >= 1 && $mm <= 12) {
      return sprintf('%02d%02d', $yy, $mm);
    }

    // Bazıları YYMM girer: 2807
    $yy2 = (int)$a;
    $mm2 = (int)$b;
    if ($mm2 >= 1 && $mm2 <= 12) {
      return sprintf('%02d%02d', $yy2, $mm2);
    }

    return null;
  }

  private function hashString($s) {
    return base64_encode(hash('sha256', (string)$s, true));
  }

  private function make_mac($xid, $amount, $currency, $merchantNo) {
    $firstHash = $this->hashString($this->enc_key . ';' . $this->terminal_id);
    return $this->hashString($xid . ';' . $amount . ';' . $currency . ';' . $merchantNo . ';' . $firstHash);
  }

  private function map_posnet_currency($wc_currency) {
    $wc_currency = strtoupper((string)$wc_currency);
    if ($wc_currency === 'TRY' || $wc_currency === 'TL') return 'TL'; // Doküman: TL/US/EU :contentReference[oaicite:6]{index=6}
    if ($wc_currency === 'USD') return 'US';
    if ($wc_currency === 'EUR') return 'EU';
    return 'TL';
  }
}
