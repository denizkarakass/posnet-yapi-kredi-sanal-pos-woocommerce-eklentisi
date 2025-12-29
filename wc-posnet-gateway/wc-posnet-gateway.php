<?php
/**
 * Plugin Name: WooCommerce Posnet Gateway - Custom
 * Description: Yapı Kredi Posnet 3D Secure / OOS redirect entegrasyonu (test).
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {
  if (!class_exists('WC_Payment_Gateway')) return;

  require_once __DIR__ . '/includes/class-wc-gateway-posnet.php';

  add_filter('woocommerce_payment_gateways', function ($methods) {
    $methods[] = 'WC_Gateway_Posnet_Custom';
    return $methods;
  });
});
