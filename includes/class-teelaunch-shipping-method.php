<?php

require_once plugin_dir_path(__FILE__) . 'class-teelaunch-shipping-api.php';

class Teelaunch_Shipping_Method {

    const WOO_TRUE = 'yes';
    const WOO_FALSE = 'no';
    const DEFAULT_ENABLED = self::WOO_TRUE;
    const DEFAULT_OVERRIDE = self::WOO_TRUE;
    const VERSION = '1.0';
    private $shipping_enabled;
    private $shipping_override;
    private $teelaunchApiClient;
    private $isTeelaunchPackage;
    private $teelaunch_shipping_cost; // Variable to store Teelaunch shipping cost

    public function __construct()
    {
        $this->id = 'teelaunch_shipping';
        $this->method_title = 'Teelaunch Shipping';
        $this->method_description = 'Calculate live shipping rates based on actual Teelaunch shipping costs.';
        $this->title = 'Teelaunch Shipping';
        $this->teelaunchApiClient = new Teelaunch_Shipping_API(self::VERSION);
        $this->shipping_enabled = self::DEFAULT_ENABLED;
        $this->shipping_override = self::DEFAULT_OVERRIDE;
        $this->init();
    }

    function init()
    {
        add_action('woocommerce_load_shipping_methods', [$this, 'load_shipping_methods']);
        add_filter('woocommerce_shipping_methods', [$this, 'add_teelaunch_shipping_method']);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'calculate_shipping_rates']);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_custom_field_to_order_meta'], 10, 4);
        add_action('woocommerce_cart_calculate_fees', [$this, 'calculate_totals']);
        add_action('wp_footer', [$this, 'trigger_shipping_calculation_js']);
    }

    public function add_teelaunch_shipping_method($methods)
    {
        return self::WOO_TRUE === $this->shipping_override && true === $this->isTeelaunchPackage ? [] : $methods;
    }

    public function load_shipping_methods($package)
    {
        $this->isTeelaunchPackage = false;

        if (!$package) {
            WC()->shipping()->register_shipping_method($this);
            return;
        }

        if (self::WOO_FALSE === $this->shipping_enabled) {
            return;
        }

        if (isset($package['managed_by_teelaunch']) && true === $package['managed_by_teelaunch']) {
            if (self::WOO_TRUE === $this->shipping_override) {
                WC()->shipping()->unregister_shipping_methods();
            }
            $this->isTeelaunchPackage = true;
            WC()->shipping()->register_shipping_method($this);

            $this->calculate_shipping_rates();
        }
    }

    public function calculate_shipping_rates($packages = [])
    {
        $settings = get_option('woocommerce_teelaunch_shipping_settings', array());
        $shipping_enabled = isset($settings['enabled']) ? $settings['enabled'] : self::DEFAULT_ENABLED;
        $shipping_override = isset($settings['override_defaults']) ? $settings['override_defaults'] : self::DEFAULT_OVERRIDE;

        if ($shipping_enabled === self::WOO_TRUE && $shipping_override === self::WOO_TRUE) {
            $requestParameters = [
                'items' => [],
                'address' => [],
            ];

            foreach ($packages as &$package) {
                foreach ($package['contents'] as $variation_key => &$variation) {
                    $custom_field = isset($variation['teelaunch_custom_field']) ? $variation['teelaunch_custom_field'] : '';
                    if ($variation && $variation['data']) {
                        $productVariation = $variation['data'];
                        $sku = $productVariation->get_sku();
                        $quantity = $variation['quantity'];
                        $price = $productVariation->get_price();
                        $metadata = $productVariation->get_meta_data();
                        $requestParameters['items'][] = [
                            'sku' => $sku,
                            'quantity' => $quantity,
                            'price' => $price,
                            'metadata' => $metadata,
                        ];
                    }
                }
                $requestParameters['address'] = [
                    'country' => $package['destination']['country'],
                    'state' => $package['destination']['state'],
                    'zip' => isset($package['destination']['postcode']) ? $package['destination']['postcode'] : null,
                ];
            }
            if (!count($requestParameters['address'])) {
                return $packages;
            }

            $teelaunchShippingRates = $this->teelaunchApiClient->get_shipping_rates($requestParameters);

            if (isset($teelaunchShippingRates['totalShippingPrice'])) {
                $this->teelaunch_shipping_cost = $teelaunchShippingRates['totalShippingPrice'];
            }

            return $packages;
        } else {
            return $packages;
        }
    }

    public function trigger_shipping_calculation_js()
    {
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                function updateAddressFields(country) {
                    var countryField = document.querySelectorAll('#billing_country, #shipping_country');
                    var stateField = document.querySelectorAll('#billing_state, #shipping_state');
                    var postcodeField = document.querySelectorAll('#billing_postcode, #shipping_postcode');
                    countryField.forEach(function (element) {
                        if ([...element.options].some(option => option.value === country)) {
                            element.value = country;
                        } else {
                            var newOption = document.createElement('option');
                            newOption.value = country;
                            newOption.text = country;
                            element.appendChild(newOption);
                            element.value = country;
                        }
                    });
                    stateField.forEach(function (element) {
                        element.value = '';
                    });
                    postcodeField.forEach(function (element) {
                        element.value = '';
                    });
                    var event = new Event('change', { bubbles: true });
                    countryField.forEach(function (element) {
                        element.dispatchEvent(event);
                    });
                }
                var countrySelectors = document.querySelectorAll('select#billing_country, select#shipping_country');
                countrySelectors.forEach(function (selector) {
                    selector.addEventListener('change', function () {
                        var country = this.value;
                        updateAddressFields(country);
                        document.body.dispatchEvent(new Event('update_checkout'));
                    });
                });
            });
        </script>
        <?php
    }
    
 
    public function calculate_totals() {
        if ($this->teelaunch_shipping_cost) {
            WC()->cart->add_fee(__('Teelaunch Shipping', 'teelaunch_shipping'), $this->teelaunch_shipping_cost);
        }
    }

    public function add_custom_field_to_order_meta($item, $cart_item_key, $values, $order) {
        $custom_field = isset(WC()->cart->cart_contents[$cart_item_key]['teelaunch_custom_field']) ? WC()->cart->cart_contents[$cart_item_key]['teelaunch_custom_field'] : '';
        if (!empty($custom_field)) {
            $item->add_meta_data('text', $custom_field);
        }
    }

    public function admin_options() {
        //This method is intentionally left empty to prevent errors
    }

    public function has_settings() {
        return true;
    }

    public function supports($feature)
    {
        switch ($feature) {
            case 'shipping-zones':
                return true;
            case 'instance-settings':
                return true;
            default:
                return false;
        }
    }

}