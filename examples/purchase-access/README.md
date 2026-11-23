# Purchase-based access

This sample shows how Dastresi-Ban turns completed WooCommerce orders into an access decision. See the [validity service](src/Features/WooCommerce/WooCommerceAccessValidityService.php) and [purchase index](src/Features/WooCommerce/WooCommercePurchaseIndex.php).

| Test order or product setting | Result |
| --- | --- |
| Completed order within the 30-day access window | Active access |
| Completed order beyond that window | Expired access |
| Product configured with no expiry and purchased | Unlimited access |
| Customer has no qualifying order | No purchase |
| A variation has no duration of its own | Inherits the parent product duration |

## Run the integration fixture

Use disposable single-site WordPress with WooCommerce active and <code>WP_ENVIRONMENT_TYPE</code> set to <code>local</code>. Run from this directory with <code>CODEXAWP_TEST_WP_LOAD</code> set to that installation's absolute <code>wp-load.php</code> path.

    CODEXAWP_TEST_WP_LOAD=/path/to/wp-load.php php tests/check.php

The test creates synthetic buyers, products, and completed orders. Six checks passed with WordPress 7.1.3, WooCommerce 11.1.2, and PHP 8.2.12.

Pass `--paging` to create 1,001 additional synthetic purchases and compare the paged results with an unbounded order query. The command prints query and peak-memory measurements from the test environment. The fixture checks that paging preserves the oldest qualifying purchase and does not duplicate or miss order IDs.

## Source

Selected classes from Dastresi-Ban 2.0.0 by CodexaWP, shared for portfolio review. The fixture is adapted from the product's real WooCommerce integration test.
