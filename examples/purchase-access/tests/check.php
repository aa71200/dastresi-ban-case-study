<?php
declare(strict_types=1);

use MA_CO_AccessControlPro\Features\WooCommerce\WooCommerceAccessValidityService as Validity;
use MA_CO_AccessControlPro\Features\WooCommerce\WooCommercePurchaseIndex as PurchaseIndex;

require __DIR__ . '/bootstrap.php';
portfolio_require_woocommerce();
if (in_array('--legacy-orders', $argv, true)) {
    add_filter('pre_option_woocommerce_custom_orders_table_enabled', static fn(): string => 'no');
}
$src = dirname(__DIR__) . '/src';
require "$src/Utilities/TimeHelper.php";
require "$src/Utilities/PersianDate.php";
require "$src/Features/WooCommerce/WooCommercePurchaseIndex.php";
require "$src/Features/WooCommerce/WooCommerceAccessValidityService.php";

function fixture_product(WC_Product $product, ?int $days): int {
    $product->set_name('Portfolio download');
    $product->set_status('publish');
    $product->set_regular_price('10.00');
    $id = $product->save();
    if ($days !== null) {
        update_post_meta($id, Validity::PRODUCT_DURATION_META_KEY, $days);
    }
    return $id;
}

function fixture_order(int $buyer, int $item, int $daysAgo): void {
    $order = wc_create_order(['customer_id' => $buyer]);
    if (is_wp_error($order)) {
        throw new RuntimeException($order->get_error_message());
    }
    $order->add_product(wc_get_product($item), 1);
    $paid = time() - $daysAgo * DAY_IN_SECONDS;
    $order->set_date_created($paid);
    $order->set_date_paid($paid);
    $order->set_status('completed');
    $order->save();
}

$limited = fixture_product(new WC_Product_Simple(), 30);
$permanent = fixture_product(new WC_Product_Simple(), 0);
$active = portfolio_user();
$expired = portfolio_user();
$empty = portfolio_user();
fixture_order($active, $limited, 2);
fixture_order($expired, $limited, 45);
fixture_order($active, $permanent, 200);
foreach ([
    [$active, $limited, 'active'],
    [$expired, $limited, 'expired'],
    [$empty, $limited, 'no_purchase'],
    [$active, $permanent, 'unlimited'],
] as [$buyer, $item, $expected]) {
    $status = Validity::get_product_statuses($buyer, [$item], ['mode' => 'product'])[0];
    portfolio_check($status['status'] === $expected, "Completed WooCommerce orders: $expected");
}

$parent = fixture_product(new WC_Product_Variable(), 30);
$variation = new WC_Product_Variation();
$variation->set_parent_id($parent);
$variationId = fixture_product($variation, null);
fixture_order($active, $variationId, 2);
portfolio_check(Validity::get_product_duration_days($variationId) === 30, 'A variation inherits the parent duration');
$variant = Validity::get_product_statuses($active, [$variationId], ['mode' => 'product'])[0];
portfolio_check($variant['status'] === 'active', 'A completed variation order grants active access');

if (in_array('--paging', $argv, true)) {
    $buyer = portfolio_user();
    $noise = fixture_product(new WC_Product_Simple(), 10);
    fixture_order($buyer, $permanent, 365);
    for ($i = 0; $i < 1000; ++$i) {
        fixture_order($buyer, $noise, $i % 320);
    }
    if (function_exists('wp_cache_flush_runtime')) {
        wp_cache_flush_runtime();
    }
    global $wpdb;
    $starting_queries = $wpdb->num_queries;
    memory_reset_peak_usage();
    $unpaged = wc_get_orders(['customer_id' => $buyer, 'status' => Validity::get_paid_order_statuses(), 'limit' => -1, 'return' => 'objects']);
    $reference_index = [];
    (new ReflectionMethod(PurchaseIndex::class, 'index_page'))->invokeArgs(null, [$unpaged, &$reference_index]);
    $reference_ids = array_column(array_merge($reference_index[$noise], $reference_index[$permanent]), 'order_id');
    $unpaged_peak = memory_get_peak_usage();
    $unpaged_queries = $wpdb->num_queries - $starting_queries;
    unset($unpaged, $reference_index);
    if (function_exists('wp_cache_flush_runtime')) {
        wp_cache_flush_runtime();
    }
    $starting_queries = $wpdb->num_queries;
    memory_reset_peak_usage();
    $paid = PurchaseIndex::find($buyer, $noise);
    $old = PurchaseIndex::find($buyer, $permanent);
    $paged_peak = memory_get_peak_usage();
    $paged_queries = $wpdb->num_queries - $starting_queries;
    $actual_ids = array_column(array_merge($paid, $old), 'order_id');
    sort($reference_ids);
    sort($actual_ids);
    portfolio_check(1001 === count($actual_ids) && $reference_ids === $actual_ids, 'Paged lookup preserves every purchase without duplicates');
    portfolio_check('unlimited' === Validity::get_product_statuses($buyer, [$permanent], ['mode' => 'product'])[0]['status'], 'An older purchase still grants unlimited access across page boundaries');
    printf("Unpaged order query: %d bytes peak and %d queries\n", $unpaged_peak, $unpaged_queries);
    printf("Paged lookup and index: %d bytes peak and %d queries\n", $paged_peak, $paged_queries);
    portfolio_check($paged_peak < $unpaged_peak, 'Paged lookup uses less peak memory in this fixture');
}
