/*
Description: Tracks WooCommerce order events and sends them to Klaviyo via server-side requests, with asynchronous processing for checkout events.
Version: 2.9.4
Author: Datavinci Prateek
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

define('KLAVIYO_PRIVATE_API_KEY', '<KLAVIYO_PRIVATE_KEY>'); // Generate from Klaviyo platform

// Server-Side: Placed Order and Ordered Product (on order creation) - Schedule asynchronously
add_action('woocommerce_checkout_order_processed', 'custom_woocommerce_klaviyo_server_side_events', 10, 2);
function custom_woocommerce_klaviyo_server_side_events($order_id, $posted_data) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_klaviyo_events_fired', true) === 'yes') {
        return;
    }

    // Schedule the event sending as an asynchronous task using Action Scheduler
    as_schedule_single_action(
        time(), // Run as soon as possible
        'klaviyo_send_placed_order_events', // Custom action hook
        array('order_id' => $order_id), // Pass order ID as argument
        'klaviyo_events' // Group for Action Scheduler
    );

    // Mark as scheduled (will be updated to 'yes' after the task runs)
    $order->update_meta_data('_klaviyo_events_fired', 'scheduled');
    $order->save();
}

// Fallback: Ensure Placed Order and Ordered Product fire if initial hook fails - Schedule asynchronously
add_action('woocommerce_order_status_processing', 'custom_woocommerce_klaviyo_fallback_placed_order_events', 9, 1);
function custom_woocommerce_klaviyo_fallback_placed_order_events($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_klaviyo_events_fired', true) === 'yes') {
        return;
    }

    // Schedule the event sending as an asynchronous task using Action Scheduler
    as_schedule_single_action(
        time(),
        'klaviyo_send_placed_order_events',
        array('order_id' => $order_id),
        'klaviyo_events'
    );

    // Mark as scheduled
    $order->update_meta_data('_klaviyo_events_fired', 'scheduled');
    $order->save();
}

// Action Scheduler Callback: Process the Placed Order and Ordered Product events
add_action('klaviyo_send_placed_order_events', 'klaviyo_process_placed_order_events', 10, 1);
function klaviyo_process_placed_order_events($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_klaviyo_events_fired', true) === 'yes') {
        return;
    }

    // Send the events
    send_placed_and_ordered_product_events($order);

    // Mark as fired
    $order->update_meta_data('_klaviyo_events_fired', 'yes');
    $order->save();
}

// Helper: Send Placed Order and Ordered Product events
function send_placed_and_ordered_product_events($order) {
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

    // Placed Order Event
    $placed_order_data = array(
        'event' => 'Placed Order',
        'customer_properties' => array(
            '$email' => $email,
        ),
        'properties' => array(
            '$event_id' => 'placed_' . $order->get_order_number(),
            'OrderID' => $order->get_order_number(),
            'Value' => $order->get_total(),
            'Currency' => $order->get_currency(),
            'ItemCount' => $item_count,
            'Categories' => $categories,
            'Items' => $products,
            'Shipping' => $order->get_shipping_total(),
            'Discount' => $order->get_discount_total(),
            'Status' => $order->get_status(),
        ),
        'time' => time(),
    );
    custom_klaviyo_send_server_side_event($placed_order_data);

    // Ordered Product Events
    foreach ($products as $product) {
        $ordered_product_data = array(
            'event' => 'Ordered Product',
            'customer_properties' => array(
                '$email' => $email,
            ),
            'properties' => array(
                '$event_id' => 'ordered_product_' . $order->get_order_number() . '_' . $product['id'],
                'ProductName' => $product['name'],
                'ProductID' => $product['id'],
                'SKU' => $product['sku'],
                'Value' => $product['price'],
                'Quantity' => $product['quantity'],
                'Categories' => $product['categories'],
                'OrderID' => $order->get_order_number(),
            ),
            'time' => time(),
        );
        custom_klaviyo_send_server_side_event($ordered_product_data);
    }
}

// Server-Side: On Hold (status changed to "on-hold")
add_action('woocommerce_order_status_on-hold', 'custom_woocommerce_klaviyo_on_hold_order', 10, 1);
function custom_woocommerce_klaviyo_on_hold_order($order_id) {
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

    $on_hold_order_data = array(
        'event' => 'On Hold Order',
        'customer_properties' => array(
            '$email' => $order->get_billing_email() ?: 'no-email',
        ),
        'properties' => array(
            '$event_id' => 'on_hold_' . $order->get_order_number(),
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

    custom_klaviyo_send_server_side_event($on_hold_order_data);
}

// Server-Side: Processing Order (status changed to "processing")
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

// Server-Side: Fulfilled Order (status changed to "completed")
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

// Server-Side: Cancelled Order (status changed to "cancelled")
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

// Server-Side: Refunded Order (refund processed)
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

// Server-Side: Custom Status - Preparing for Shipment
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

// Server-Side: Custom Status - Customer is Claiming
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
