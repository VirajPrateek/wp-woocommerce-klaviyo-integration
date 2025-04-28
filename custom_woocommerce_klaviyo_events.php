/*
Plugin Name: Custom WooCommerce Klaviyo Events
Description: Tracks WooCommerce order events and sends them to Klaviyo with reliable triggering.
Version: 2.7
Author: Datavinci (Prateek)
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Klaviyo API Keys 
define('KLAVIYO_PUBLIC_API_KEY', <KLAVIYO_PUBLIC_API_KEY> ); // EMOTIV
define('KLAVIYO_PRIVATE_API_KEY',<KLAVIYO_PRIVATE_API_KEY >); // Prateek’s for test

// Add Klaviyo tracking snippet to <head>
add_action('wp_head', 'custom_klaviyo_tracking_snippet');
function custom_klaviyo_tracking_snippet() {
    ?>
    <script type="text/javascript">
        var _learnq = _learnq || [];
        _learnq.push(['account', '<?php echo esc_js(KLAVIYO_PUBLIC_API_KEY); ?>']);
        (function () {
            var b = document.createElement('script'); b.type = 'text/javascript'; b.async = true;
            b.src = ('https:' == document.location.protocol ? 'https://' : 'http://') + 'a.klaviyo.com/media/js/analytics/analytics.js';
            var a = document.getElementsByTagName('script')[0]; a.parentNode.insertBefore(b, a);
        })();
    </script>
    <?php
}

// Debug checkout initiation
add_action('woocommerce_before_checkout_process', 'custom_woocommerce_before_checkout_debug');
function custom_woocommerce_before_checkout_debug() {
    ?>
    <script>
        console.log('Hook woocommerce_before_checkout_process: Fired - Is AJAX: <?php echo esc_js(wp_doing_ajax() ? 'Yes' : 'No'); ?>');
    </script>
    <?php
}

// Debug order processing and set flag
add_action('woocommerce_checkout_order_processed', 'custom_woocommerce_order_processed_debug', 10, 3);
function custom_woocommerce_order_processed_debug($order_id, $posted_data, $order) {
    ?>
    <script>
        console.log('Hook woocommerce_checkout_order_processed: Fired for Order ID <?php echo esc_js($order_id); ?>, Is AJAX: <?php echo esc_js(wp_doing_ajax() ? 'Yes' : 'No'); ?>');
    </script>
    <?php
    try {
        if (!$order || !is_a($order, 'WC_Order')) {
            ?>
            <script>
                console.log('Order Processed: Invalid or missing order for Order ID <?php echo esc_js($order_id); ?>');
            </script>
            <?php
            return;
        }
        ?>
        <script>
            console.log('Order Processed: Valid order found for Order ID <?php echo esc_js($order_id); ?>, Status: <?php echo esc_js($order->get_status()); ?>');
        </script>
        <?php
        // Set flag to indicate events haven't been fired
        $order->update_meta_data('_klaviyo_events_fired', 'no');
        $order->save();
    } catch (Exception $e) {
        ?>
        <script>
            console.log('Order Processed Error: <?php echo esc_js($e->getMessage()); ?> for Order ID <?php echo esc_js($order_id); ?>');
        </script>
        <?php
    }
}

// Front-End: Placed Order and Ordered Product (on thank you page with flag check)
add_action('wp_footer', 'custom_woocommerce_klaviyo_front_end_events', 999);
function custom_woocommerce_klaviyo_front_end_events() {
    $is_order_received = is_order_received_page();
    $order_id = absint(get_query_var('order-received'));
    if (!$is_order_received || !$order_id) {
        return;
    }

    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            ?>
            <script>
                console.log('wp_footer: Order not found for Order ID <?php echo esc_js($order_id); ?>');
                alert('wp_footer: Order not found for Order ID <?php echo esc_js($order_id); ?>');
            </script>
            <?php
            return;
        }

        // Check if events have already been fired
        $events_fired = $order->get_meta('_klaviyo_events_fired', true);
        if ($events_fired === 'yes') {
            ?>
            <script>
                console.log('wp_footer: Klaviyo events already fired for Order ID <?php echo esc_js($order_id); ?>');
            </script>
            <?php
            return;
        }

        ?>
        <script>
            console.log('wp_footer: Valid order found for Order ID <?php echo esc_js($order_id); ?>, Status: <?php echo esc_js($order->get_status()); ?>');
        </script>
        <?php

        // Build order data
        $products = array();
        $categories = array();
        $item_count = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->exists()) {
                $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
                $categories = array_merge($categories, $product_cats);
                $products[] = array(
                    'name' => $item->get_name(),
                    'id' => $product->get_id(),
                    'sku' => $product->get_sku() ?: $product->get_id(),
                    'price' => $order->get_line_subtotal($item, false, false),
                    'quantity' => $item->get_quantity(),
                    'categories' => $product_cats,
                );
                $item_count += $item->get_quantity();
            }
        }

        $categories = array_unique($categories);
        $email = $order->get_billing_email() ?: 'no-email';

        // Identify the customer
        ?>
        <script>
            window._learnq = window._learnq || [];
            window._learnq.push(['identify', { '$email': '<?php echo esc_js($email); ?>' }]);
            console.log('wp_footer: Identified customer with email: <?php echo esc_js($email); ?>');
        </script>
        <?php

        // Placed Order Event
        $placed_order_data = array(
            '$event_id' => 'placed_' . $order->get_order_number(),
            '$value' => $order->get_total(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'Email' => $email,
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'Shipping' => $order->get_shipping_total(),
            'Discount' => $order->get_discount_total(),
            'Status' => $order->get_status(),
        );

        ?>
        <script>
            var placedOrderData = <?php echo json_encode($placed_order_data); ?>;
            window._learnq.push(['track', 'Placed Order', placedOrderData]);
            console.log('wp_footer: Klaviyo Placed Order Event for Order ID <?php echo esc_js($order_id); ?>', placedOrderData);
            alert('wp_footer: Klaviyo Placed Order Event Fired for Order ID <?php echo esc_js($order_id); ?>: ' + JSON.stringify(placedOrderData, null, 2));
        </script>
        <?php

        // Ordered Product Events (one per item)
        foreach ($products as $product) {
            $ordered_product_data = array(
                '$event_id' => 'ordered_product_' . $order->get_order_number() . '_' . $product['id'],
                'ProductName' => $product['name'],
                'ProductID' => $product['id'],
                'SKU' => $product['sku'],
                'Value' => $product['price'],
                'Quantity' => $product['quantity'],
                'Categories' => $product['categories'],
                'OrderID' => $order->get_order_number(),
                'Email' => $email,
            );

            ?>
            <script>
                var orderedProductData = <?php echo json_encode($ordered_product_data); ?>;
                window._learnq.push(['track', 'Ordered Product', orderedProductData]);
                console.log('wp_footer: Klaviyo Ordered Product Event for Order ID <?php echo esc_js($order_id); ?>, Product ID <?php echo esc_js($product['id']); ?>', orderedProductData);
                alert('wp_footer: Klaviyo Ordered Product Event Fired for Order ID <?php echo esc_js($order_id); ?>, Product ID <?php echo esc_js($product['id']); ?>: ' + JSON.stringify(orderedProductData, null, 2));
            </script>
            <?php
        }

        // Update the flag to prevent duplicate firing
        $order->update_meta_data('_klaviyo_events_fired', 'yes');
        $order->save();

        ?>
        <script>
            console.log('wp_footer: Klaviyo events flag updated for Order ID <?php echo esc_js($order_id); ?>');
        </script>
        <?php
    } catch (Exception $e) {
        ?>
        <script>
            console.log('wp_footer Error: <?php echo esc_js($e->getMessage()); ?> for Order ID <?php echo esc_js($order_id); ?>');
            alert('wp_footer Error: <?php echo esc_js($e->getMessage()); ?> for Order ID <?php echo esc_js($order_id); ?>');
        </script>
        <?php
    }
}

// Back-End: Processing Order (status changed to "processing")
add_action('woocommerce_order_status_processing', 'custom_woocommerce_klaviyo_processing_order', 10, 1);
function custom_woocommerce_klaviyo_processing_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $processing_order_data = array(
        'event' => 'Processing Order',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'processing_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'Shipping' => $order->get_shipping_total(),
            'Discount' => $order->get_discount_total(),
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($processing_order_data);
}

// Back-End: Fulfilled Order (status changed to "completed")
add_action('woocommerce_order_status_completed', 'custom_woocommerce_klaviyo_fulfilled_order', 10, 1);
function custom_woocommerce_klaviyo_fulfilled_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $fulfilled_order_data = array(
        'event' => 'Fulfilled Order',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'fulfilled_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'Shipping' => $order->get_shipping_total(),
            'Discount' => $order->get_discount_total(),
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($fulfilled_order_data);
}

// Back-End: Cancelled Order (status changed to "cancelled")
add_action('woocommerce_order_status_cancelled', 'custom_woocommerce_klaviyo_cancelled_order', 10, 1);
function custom_woocommerce_klaviyo_cancelled_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $cancelled_order_data = array(
        'event' => 'Cancelled Order',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'cancelled_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($cancelled_order_data);
}

// Back-End: Refunded Order (refund processed)
add_action('woocommerce_order_refunded', 'custom_woocommerce_klaviyo_refunded_order', 10, 2);
function custom_woocommerce_klaviyo_refunded_order($order_id, $refund_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $refund = wc_get_order($refund_id);
    if (!$refund) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $refunded_order_data = array(
        'event' => 'Refunded Order',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'refunded_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'RefundAmount' => $refund->get_amount(),
            'RefundReason' => $refund->get_reason() ?: 'No reason provided',
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($refunded_order_data);
}

// Back-End: Custom Status - Preparing for Shipment
add_action('woocommerce_order_status_preparing-for-shipment', 'custom_woocommerce_klaviyo_preparing_for_shipment', 10, 1);
function custom_woocommerce_klaviyo_preparing_for_shipment($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $preparing_order_data = array(
        'event' => 'Preparing for Shipment',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'preparing_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'Shipping' => $order->get_shipping_total(),
            'Discount' => $order->get_discount_total(),
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($preparing_order_data);
}

// Back-End: Custom Status - Customer is Claiming
add_action('woocommerce_order_status_customer-is-claiming', 'custom_woocommerce_klaviyo_customer_is_claiming', 10, 1);
function custom_woocommerce_klaviyo_customer_is_claiming($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $products = array();
    $categories = array();
    $item_count = 0;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->exists()) {
            $product_cats = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
            $categories = array_merge($categories, $product_cats);
            $products[] = array(
                'name' => $item->get_name(),
                'id' => $product->get_id(),
                'sku' => $product->get_sku() ?: $product->get_id(),
                'price' => $order->get_line_subtotal($item, false, false),
                'quantity' => $item->get_quantity(),
            );
            $item_count += $item->get_quantity();
        }
    }

    $categories = array_unique($categories);

    $claiming_order_data = array(
        'event' => 'Customer is Claiming',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'claiming_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
        ),
        'time' => time(),
    );

    custom_klaviyo_send_server_side_event($claiming_order_data);
}

// Helper: Send server-side event to Klaviyo
function custom_klaviyo_send_server_side_event($event_data) {
    if (!KLAVIYO_PRIVATE_API_KEY) return;

    $url = 'https://a.klaviyo.com/api/track';
    $args = array(
        'body' => array(
            'token' => KLAVIYO_PRIVATE_API_KEY,
            'event' => $event_data['event'],
            'customer_properties' => $event_data['customer_properties'],
            'properties' => $event_data['properties'],
            'time' => $event_data['time'],
        ),
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'method' => 'POST',
        'timeout' => 15,
    );

    $args['body'] = json_encode($args['body']);
    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        error_log('Klaviyo Server-Side Event Error: ' . $response->get_error_message());
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Klaviyo Server-Side Event Failed: Status ' . $status_code . ', Response: ' . wp_remote_retrieve_body($response));
        }
    }
}
