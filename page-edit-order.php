<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$order_id = (int) $_GET['id'];
$order = wc_get_order($order_id);

if(!$order || $order->get_customer_id() !== get_current_user_id()) {
    wp_redirect(get_permalink(get_option('woocommerce_myaccount_page_id')));
}

if(isset($_POST['new-products']) && $_POST['new-products']) {
    foreach ($_POST['new-products'] as $pid) {
        $order->add_product(wc_get_product($pid), 1);
    }

    $shipping = recalc_order_shipping($order);

    foreach ($order->get_items('fee') as $fee) {
        if ($fee->get_name() == 'Surcharge') {
            $fee->set_amount(round($order->get_subtotal() * 0.05, 2));
            $fee->set_total(round($order->get_subtotal() * 0.05, 2));
            $fee->save();
        }
    }

    if ($shipping) {
        $ship = $order->get_shipping_methods();
        foreach ($ship as $s) {
            if ($s->get_name() == $shipping['label']) {
                $s->set_total($shipping['cost']);
                $s->save();
            }
        }
    }

    $order->calculate_totals();

    wp_redirect(get_permalink().'?id='.$order_id);
}

$checkout = WC_Checkout::instance();
$order_data = $order->get_data();

$bill_address = $order_data['billing']['first_name'] . $order_data['billing']['last_name'] . $order_data['billing']['address_1'] . $order_data['billing']['address_2'] . $order_data['billing']['city'] . $order_data['billing']['state'] . $order_data['billing']['postcode'];
$ship_address = $order_data['shipping']['first_name'] . $order_data['shipping']['last_name'] . $order_data['shipping']['address_1'] . $order_data['shipping']['address_2'] . $order_data['shipping']['city'] . $order_data['shipping']['state'] . $order_data['shipping']['postcode'];
$order_items = $order->get_items('line_item');
$currency_symbol = get_woocommerce_currency_symbol();

wp_enqueue_style("select2", get_template_directory_uri() . '/assets/css/select2.min.css');
wp_enqueue_script("select2", get_template_directory_uri() . '/assets/js/select2.min.js', array('jquery'));
wp_localize_script('select2', 'CustomAjax', array('ajaxurl' => admin_url('admin-ajax.php'), 'security' => wp_create_nonce('search-products')));

get_header('shop');
?>

<form id="edit-form" name="editorder" method="post" class="checkout woocommerce-checkout" action="" enctype="multipart/form-data">
    <section class="woocommerce-order-details">
        <h1 class="entry-title"><?php _e( 'Edit Order', 'woocommerce' ); ?></h1>

        <div class="col2-set" id="customer_details">
            <div class="col-1">
                <h3><?php _e( 'Billing details', 'woocommerce' ); ?></h3>

                <div class="woocommerce-billing-fields__field-wrapper">
                    <?php
                    $fields = $checkout->get_checkout_fields( 'billing' );

                    foreach ( $fields as $key => $field ) {
                        if ( isset( $field['country_field'], $fields[ $field['country_field'] ] ) ) {
                            $field['country'] = $checkout->get_value( $field['country_field'] );
                        }
                        woocommerce_form_field( $key, $field, $order_data['billing'][str_replace(array('billing_'), '', $key)] );
                    }
                    ?>
                </div>
            </div>

            <div class="col-2">
                <div class="woocommerce-shipping-fields">
                    <h3 id="ship-to-different-address">
                        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                            <input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked( $bill_address == $ship_address ? 0 : 1, 1 ); ?> type="checkbox" name="ship_to_different_address" value="1" /> <span><?php _e( 'Ship to a different address?', 'woocommerce' ); ?></span>
                        </label>
                    </h3>

                    <div class="shipping_address" style="<?php echo $bill_address == $ship_address ? 'display: none' : '' ?>">
                        <div class="woocommerce-shipping-fields__field-wrapper">
                            <?php
                            $fields = $checkout->get_checkout_fields( 'shipping' );

                            foreach ( $fields as $key => $field ) {
                                if ( isset( $field['country_field'], $fields[ $field['country_field'] ] ) ) {
                                    $field['country'] = $checkout->get_value( $field['country_field'] );
                                }
                                woocommerce_form_field( $key, $field, $order_data['shipping'][str_replace(array('shipping_'), '', $key)] );
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="woocommerce-additional-fields">
                    <h3><?php _e( 'Additional information', 'woocommerce' ); ?></h3>
                    <div class="woocommerce-additional-fields__field-wrapper">
                        <?php foreach ( $checkout->get_checkout_fields( 'order' ) as $key => $field ) : ?>
                            <?php woocommerce_form_field( $key, $field, $order->get_customer_note() ); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="order_review" class="woocommerce-checkout-review-order" style="position: relative;">
            <div class="blockUI blockOverlay"></div>
            <table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
                <thead>
                <tr>
                    <th <?php echo count($order_items) > 1 ? 'colspan="2"' : '' ?> class="woocommerce-table__product-name product-name"><?php _e( 'Product', 'woocommerce' ); ?></th>
                    <th class="woocommerce-table__product-table product-quantity"><?php _e( 'Quantity', 'woocommerce' ); ?></th>
                    <th class="woocommerce-table__product-table product-total"><?php _e( 'Total', 'woocommerce' ); ?></th>
                </tr>
                </thead>

                <tbody>
                <?php
                foreach ( $order_items as $item_id => $item ) {
                    $item_data = $item->get_data();
                    $product = wc_get_product($item_data['product_id']);
                    $attributes = $product->get_type() == 'variable' ? $product->get_attributes() : array();
                    $available_variations = $product->get_type() == 'variable' ? $product->get_available_variations() : array();
                    ?>
                    <tr class="woocommerce-table__line-item order_item" data-variations="<?php echo htmlspecialchars(wp_json_encode($available_variations)) ?>">
                        <?php if(count($order_items) > 1) { ?>
                        <td style="padding: 1em 0; text-align: center"><a href="javascript:void(0)" class="remove">x</a></td>
                        <?php } ?>
                        <td class="woocommerce-table__product-name product-name">
                            <input type="hidden" name="product[<?php echo $item->get_id() ?>][id]" value="<?php echo $item_data['product_id'] ?>" />
                            <input type="hidden" name="product[<?php echo $item->get_id() ?>][variation]" value="<?php echo $item_data['variation_id'] ?>" class="variation_id" />
                            <?php
                            $is_visible        = $product && $product->is_visible();
                            $product_permalink = apply_filters( 'woocommerce_order_item_permalink', $is_visible ? $product->get_permalink( $item ) : '', $item, $order );
                            echo apply_filters( 'woocommerce_order_item_name', $product_permalink ? sprintf( '<a href="%s">%s</a>', $product_permalink, $item->get_name() ) : $item->get_name(), $item, $is_visible );
                            ?>
                            <br />

                            <?php foreach ( $attributes as $name => $attribute ) : ?>
                                <p>
                                    <label for="<?php echo esc_attr( sanitize_title( $name ) ); ?>"><?php echo wc_attribute_label( $name ); ?></label><br/>
                                    <?php
                                    wc_dropdown_variation_attribute_options( array(
                                        'attribute' => $name,
                                        'product'   => $product,
                                        'selected'  => $item->get_meta($name),
                                        'class'     => 'edit_order_options_dropdowns ' . $name,
                                        'name'      => 'product['. $item->get_id() .'][attributes][attribute_'.$name.']',
                                        'id'      => $name .'_'. $item->get_id()
                                    ) );
                                    ?>
                                </p>
                            <?php endforeach; ?>
                        </td>

                        <td>
                            <input type="number" class="input-text qty text" name="product[<?php echo $item->get_id() ?>][qty]" value="<?php echo $item->get_quantity() ?>" step="1" min="0" style="width: 60px" />
                        </td>

                        <td class="woocommerce-table__product-total product-total">
                            <?php echo $order->get_formatted_line_subtotal( $item ); ?>
                        </td>
                    </tr>
                    <?php } ?>
                    <tr>
                        <td colspan="4"><a href="#TB_inline?height=200&inlineId=add-product-modal" class="thickbox"><i class="fa fa-plus"></i> Add Another Product</a></td>
                    </tr>
                </tbody>
                <tfoot>
                <?php
                foreach ( $order->get_order_item_totals() as $key => $total ) {
                    ?>
                    <tr class="<?php echo $key ?>">
                        <th <?php echo count($order_items) > 1 ? 'colspan="2"' : '' ?> scope="row"><?php echo $total['label']; ?></th>
                        <td colspan="2" style="text-align: right"><?php echo $total['value']; ?></td>
                    </tr>
                    <?php
                }
                ?>
                </tfoot>
            </table>

            <div id="payment">
                <div class="form-row place-order">
                    <button type="button" class="button alt" id="update_order">Update Quote</button>
                </div>
            </div>
        </div>
    </section>
</form>

<?php add_thickbox(); ?>
<div id="add-product-modal" style="display:none;">
    <form id="add-product" method="POST" class="form-horizontal">
        <h4 style="margin: 30px 0">Add Products</h4>

        <p style="margin: 20px 0 30px">
            <select name="new-products[]" multiple data-placeholder="Search for a product&hellip;" style="width: 100%" required></select>
        </p>

        <p style="text-align: right">
            <button type="submit" name="submit" class="button small added_to_cart">Submit</button>
        </p>
    </form>
</div>

<style>
    body.modal-open .select2-container--open { z-index: 999999 !important; }
    #add-product .select2-search__field { width: 100% !important; }
    .select2-results .description { display: block; color: #999; padding-top: 4px; font-size: 13px; font-style: italic; }
    .select2-container .select2-selection--multiple .select2-selection__choice .description { display: none; }
    .edit_order_options_dropdowns { max-width: 200px; }
    .product-name .ptitle { display: flex }
    .product-name .ptitle .remove { margin-right: 10px; margin-left: -15px; }
    .blockOverlay { display: none; z-index: 1000; border: medium none; margin: 0px; padding: 0px; width: 100%; height: 100%; top: 0px; left: 0px; background: rgb(255, 255, 255) none repeat scroll 0% 0%; opacity: 0.6; cursor: default; position: absolute; }
</style>

<script>
jQuery(function () {
    jQuery('#ship-to-different-address-checkbox').click(function () {
        if(jQuery(this).is(':checked')) {
            jQuery('.shipping_address').slideDown();
        } else {
            jQuery('.shipping_address').slideUp();
        }

        calculate_shipping();
    });

    jQuery('.edit_order_options_dropdowns, .order_item .qty').change(function(){
        var tr = jQuery(this).parents('tr');
        var variatons = jQuery.parseJSON(tr.attr('data-variations'));
        var qty = parseFloat(tr.find('.qty').val());
        qty = Number.isInteger(qty) && qty > 0 ? qty : 1;

        jQuery.each(variatons, function(i1, v1) {
            var selected = 1;

            jQuery.each(v1.attributes, function(i2, v2) {
                if(v2 !== '') {
                    i2 = i2.replace('attribute_', '');

                    if(tr.find('.' + i2).val() !== v2) {
                        selected = 0;
                    }
                }
            });

            if(selected) {
                var price = (v1.display_price * qty).toFixed(2);
                tr.find('.product-total').html('<span class="price"><span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol ?></span>' + price + '</span></span>');
            }
        });

        calculate_totals(1);
    });

    jQuery("#billing_address_1, #billing_address_2, #billing_city, #billing_state, #billing_postcode, #shipping_address_1, #shipping_address_2, #shipping_city, #shipping_state, #shipping_postcode").change(function(){
        calculate_shipping();
    });

    jQuery('.order_item .remove').click(function () {
        if(jQuery('.order_item').length > 1) {
            if(confirm('Are you sure you want to delete this item?')) {
                jQuery(this).parents('tr.order_item').remove();
                calculate_totals(1);
            }
        }
    });

    jQuery('#update_order').click(function(){
        var valid = true;
        var errs = [];

        jQuery('.woocommerce-NoticeGroup').remove();

        jQuery('.validate-required').each(function(){
            if(jQuery(this).is(":visible")) {
                var text = jQuery(this).find('.input-text');
                var state = jQuery(this).find('.state_select');

                if(text.length && jQuery.trim(text.val()) == '') {
                    var field = text.attr('name').replace('_1', '').replace(/\_/g, ' ');
                    field = field.toLowerCase().replace(/\b[a-z]/g, function(letter) {
                        return letter.toUpperCase();
                    });

                    errs.push('<strong>' + field + '</strong> is a required field.');
                    jQuery(this).addClass('woocommerce-invalid');
                    valid = false;
                }
                else if(state.length && jQuery.trim(state.val()) == '') {
                    var field = state.attr('name').replace('_1', '').replace(/\_/g, ' ');
                    field = field.toLowerCase().replace(/\b[a-z]/g, function(letter) {
                        return letter.toUpperCase();
                    });

                    errs.push('<strong>' + field + '</strong> is a required field.');
                    jQuery(this).addClass('woocommerce-invalid');
                    valid = false;
                } else {
                    jQuery(this).removeClass('woocommerce-invalid');
                }
            }
        });

        var qty_valid = true;
        jQuery('.order_item .qty').each(function(){
            var v = parseFloat(jQuery(this).val());

            if(Number.isInteger(v) && v > 0) {
                jQuery(this).removeClass('woocommerce-invalid');
            } else {
                qty_valid = valid = false;
                jQuery(this).addClass('woocommerce-invalid');
            }
        });

        if(!qty_valid) {
            errs.push('<strong>Product Quantity</strong> is invalid.');
        }

        if(valid) {
            jQuery('.blockOverlay').show();

            jQuery.ajax({
                url: "<?php echo get_site_url() ?>/?edit-order-save=1&id=<?php echo $order_id ?>",
                data: jQuery('#edit-form').serialize(),
                type: 'POST',
                dataType: 'JSON',
                success: function(result){
                    window.location.href = '<?php echo get_permalink(get_option('woocommerce_myaccount_page_id')); ?>/orders/';
                }
            });
        } else {
            var h = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>' + errs.join('</li><li>') + '</li></ul></div>';
            jQuery(h).insertBefore('#customer_details');

            jQuery('html, body').animate({
                scrollTop: jQuery("#edit-form").offset().top
            }, 500);
        }
    });

    jQuery('#add-product select').select2({
        minimumInputLength: 3,
        escapeMarkup: function( m ) {
            return m;
        },
        ajax: {
            url: CustomAjax.ajaxurl,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    term: params.term,
                    security: CustomAjax.security,
                    action: 'edit_order_search_product'
                };
            },
            processResults: function( data ) {
                var terms = [];
                if (data) {
                    jQuery.each(data, function(id, text) {
                        terms.push({id: id, text: text});
                    });
                }
                return {
                    results: terms
                };
            },
            cache: true
        }
    });
});

var recalculate = false;
var cs_timer;
function calculate_shipping() {
    jQuery('.shipping td').html('<a href="javascript:void(0)" onclick="get_shipping_charges()"><i class="fa fa-truck"></i> Calculate shipping</a>')
    recalculate = true;

    window.clearTimeout(cs_timer);
    cs_timer = window.setTimeout(function(){
        get_shipping_charges();
    }, 3000);
}

var shipping = 0;
function get_shipping_charges() {
    jQuery('.blockOverlay').show();

    jQuery.ajax({
        url: "<?php echo get_site_url() ?>/?edit-order-shipping=1",
        data: jQuery('#edit-form').serialize(),
        type: 'POST',
        dataType: 'JSON',
        success: function(result){
            if(result) {
                jQuery('.shipping td').html('<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol ?></span>' + result.cost + '</span>&nbsp;<small class="shipped_via">via ' + result.label + '</small>');
                shipping = result.cost;
            } else {
                jQuery('.shipping td').html('<p>There are no shipping methods available. Please ensure that your address has been entered correctly, or contact us if you need any help.</p><a href="javascript:void(0)" onclick="get_shipping_charges()"><i class="fa fa-truck"></i> Calculate shipping</a>');
                shipping = 0;
            }
            jQuery('.blockOverlay').hide();
            recalculate = false;
            calculate_totals(0);
        }
    });
}

function calculate_totals(t) {
        var subtotal = 0;
        jQuery('tr.order_item').each(function(){
            var tr = jQuery(this);
            var variations = jQuery.parseJSON(tr.attr('data-variations'));
            var qty = parseFloat(tr.find('.qty').val());
            qty = Number.isInteger(qty) && qty > 0 ? qty : 1;
            jQuery.each(variations, function(i1, v1) {
                var selected = 1;

                jQuery.each(v1.attributes, function(i2, v2) {
                    if(v2 !== '') {
                        i2 = i2.replace('attribute_', '');

                        if(tr.find('.' + i2).val() !== v2) {
                            selected = 0;
                        }
                    }
                });

                if(selected) {
                    tr.find('.variation_id').val(v1.variation_id);
                    subtotal += (v1.display_price * qty);
                }
            });
        });

        var fee = (subtotal*0.05).toFixed(2);
        var commercial = 0;
        var lift_gate_service = 0;
        var paint_setup = 0;

        var fee_array = jQuery('*[class^="fee_"]');
        jQuery.each(fee_array, function(index, value) {
            //get value from paint setup HTML tag
            if (jQuery(value).find('th').html() == "Paint Setup:") {
                paint_setup = jQuery(value).find('td').text().substring(1);
            }
            //get value from Handling HTML tag
            if (jQuery(value).find('th').html() == "Handling:") {
                jQuery(value).find('td').html('<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol ?></span>' + fee + '</span>');
            }
            //get value from commercial HTML tag
            if (jQuery(value).find('th').html() == "Commercial or Residential? (If LTL): - Residential") {
                commercial = jQuery(value).find('td').text().substring(1);
            }
            //get value from lift HTML tag
            if (jQuery(value).find('th').html() == "Lift Gate Service? (If LTL): - Yes") {
                lift_gate_service = jQuery(value).find('td').text().substring(1);
            }
        })
        jQuery('.cart_subtotal td').html('<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol ?></span>' + subtotal.toFixed(2) + '</span>');
                
        var total = (parseFloat(subtotal) + parseFloat(paint_setup) + parseFloat(fee) + parseFloat(shipping) + parseFloat(commercial) + parseFloat(lift_gate_service)).toFixed(2);
        jQuery('.order_total td').html('<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol"><?php echo $currency_symbol ?></span>' + total + '</span>');

        if(t == 1) {
            calculate_shipping();
        }
    }
</script>
<?php get_footer('shop') ?>