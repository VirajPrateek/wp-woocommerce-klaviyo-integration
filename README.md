# wp-woocommerce-klaviyo-integration
Hooking woocommerce events in WP to send to Klaviyo for remarketing

## Summary of Events

| Event Name             | When Triggered                                                                       | How Sent                                           | Hook                                                                   | Trigger Context             |
|------------------------|------------------------------------------------------------------------------------|----------------------------------------------------|-------------------------------------------------------------------------|-----------------------------|
| Placed Order           | After order creation, on thank you page load, only if not previously fired (flag check) | Front-end via `_learnq.push(['track', ...])`       | `wp_footer` (with `woocommerce_checkout_order_processed` for flag setup) | Front-End (Thank You Page)  |
| Ordered Product        | After order creation, on thank you page load, per item, only if not previously fired (flag check) | Front-end via `_learnq.push(['track', ...])`       | `wp_footer` (with `woocommerce_checkout_order_processed` for flag setup) | Front-End (Thank You Page)  |
| Processing Order       | When order status changes to "processing"                                            | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_status_processing`                                   | Back-End (Admin Action)     |
| Fulfilled Order        | When order status changes to "completed"                                             | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_status_completed`                                    | Back-End (Admin Action)     |
| Cancelled Order        | When order status changes to "cancelled"                                             | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_status_cancelled`                                    | Back-End (Admin Action)     |
| Refunded Order         | When a refund is processed                                                           | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_refunded`                                            | Back-End (Admin Action)     |
| Preparing for Shipment | When order status changes to "preparing-for-shipment"                               | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_status_preparing-for-shipment`                       | Back-End (Admin Action)     |
| Customer is Claiming   | When order status changes to "customer-is-claiming"                                 | Server-side via `wp_remote_post` to Klaviyo API   | `woocommerce_order_status_customer-is-claiming`                         | Back-End (Admin Action)     |


## Detailed Explanation
### Event Triggering Mechanism
Front-End Events (Placed Order, Ordered Product):
When: Triggered after an order is created, and the user lands on the thank you page (e.g., /thank-you-direct/?order-received=XXXX).
How: Sent using the Klaviyo JavaScript SDK (_learnq.push) in wp_footer.

### Hook Coordination:
woocommerce_checkout_order_processed: Sets a flag (_klaviyo_events_fired as "no") in order meta when the order is processed.
wp_footer: Checks the flag. If not "yes," fires the events and updates the flag to "yes" to prevent duplicates (e.g., on page refresh).
Condition: Relies on is_order_received_page() and a valid order-received query parameter, with a safety check using the order object (wc_get_order).
Customer Identification: Uses _learnq.push(['identify', { '$email': email }]) before tracking to associate events with the customer.

Back-End Events (Processing Order, Fulfilled Order, etc.):
When: Triggered when an admin changes the order status or processes a refund in the WooCommerce admin panel.
How: Sent using the Klaviyo server-side API (https://a.klaviyo.com/api/track) via wp_remote_post.
Hook: Specific to each status change (e.g., woocommerce_order_status_processing).
Condition: Verifies the order exists (wc_get_order) before sending.


### Dependencies
Klaviyo Keys: 
Requires KLAVIYO_PUBLIC_API_KEY for front-end events and
KLAVIYO_PRIVATE_API_KEY for back-end events.

### References
https://woocommerce.github.io/code-reference/hooks/hooks.html 
https://developers.klaviyo.com/en/docs/introduction_to_the_klaviyo_object#how-to-load-the-klaviyo-object 
https://developers.klaviyo.com/en/docs/javascript_api 

