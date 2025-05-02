# WP WooCommerce Klaviyo Integration

**Brief:** Connects WooCommerce events to Klaviyo for powerful remarketing automation.

**Key Features:** Sends the following WooCommerce events to Klaviyo via server-side API:

| Event Name                | Triggered When                                                                 | Primary Hook                      | Fallback Hook                     |
| :------------------------ | :----------------------------------------------------------------------------- | :-------------------------------- | :-------------------------------- |
| Placed Order              | After order creation                                                           | `woocommerce_checkout_order_processed` | `woocommerce_order_status_processing` |
| Ordered Product           | After order creation (per item)                                                | `woocommerce_checkout_order_processed` | `woocommerce_order_status_processing` |
| On Hold Order             | Order status changes to "on-hold"                                              | `woocommerce_order_status_on-hold`    |                                   |
| Processing Order          | Order status changes to "processing"                                           | `woocommerce_order_status_processing` |                                   |
| Fulfilled Order           | Order status changes to "completed"                                            | `woocommerce_order_status_completed`  |                                   |
| Cancelled Order           | Order status changes to "cancelled"                                            | `woocommerce_order_status_cancelled`  |                                   |
| Refunded Order            | A refund is processed                                                          | `woocommerce_order_refunded`        |                                   |
| Preparing for Shipment    | Order status changes to "preparing-for-shipment"                               | `woocommerce_order_status_preparing-for-shipment` |                                   |
| Customer is Claiming      | Order status changes to "customer-is-claiming"                                 | `woocommerce_order_status_customer-is-claiming` |                                   |

**How it Works:** Hooks into WooCommerce order lifecycle events and uses `wp_remote_post` to send data to the Klaviyo Track API.

**Dependencies:**

* WooCommerce
* Klaviyo Private API Key (`KLAVIYO_PRIVATE_API_KEY`)

**Note:** This integration is server-side only and does not require the Klaviyo Public API Key.

**References:**

* [WooCommerce Hooks Documentation](link to woocommerce hooks doc)
* [Klaviyo Server-Side API Documentation](https://a.klaviyo.com/api/track)
