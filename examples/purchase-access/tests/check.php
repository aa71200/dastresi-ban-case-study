<?php
declare(strict_types=1);

use MA_CO_AccessControlPro\Features\WooCommerce\WooCommerceAccessValidityService as Validity;

require __DIR__ . '/bootstrap.php';
portfolio_require_woocommerce();
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
