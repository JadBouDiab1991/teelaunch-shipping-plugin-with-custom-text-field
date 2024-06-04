<?php

class Teelaunch_Text_Field {

  public function __construct() {
      add_action('woocommerce_before_add_to_cart_button', array($this, 'display_text_field'));
      add_action('woocommerce_add_to_cart', array($this, 'save_text_field_data'), 10, 6);
  }

  public function display_text_field() {
      echo '<input type="text" name="teelaunch_custom_field" id="teelaunch_custom_field" placeholder="Enter your custom text" style="height: 40px;"></br></br>';
  }

  public function save_text_field_data($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
      if (isset($_POST['teelaunch_custom_field'])) {
          $custom_field = sanitize_text_field($_POST['teelaunch_custom_field']);
          WC()->cart->cart_contents[$cart_item_key]['teelaunch_custom_field'] = $custom_field;
      }
  }
}


