<?php
// Configuration
define('API_TOKEN', '9000147-9000334-9OIVCWMEFIN2YMIEPPIH4QSGAISJA0AZSB8U8THGUTMSQIQ1S9QJ9S7EZ9GY76AX');
define('API_URL', 'https://api.baselinker.com/connector.php');
define('INVENTORY_ID', 410);
define('BHOPAL_WAREHOUSE', 9000528);
define('LUCKNOW_WAREHOUSE', 9000484);
define('BHOPAL_STATUS', 3303);
define('LUCKNOW_STATUS', 4077);
define('SPLIT_STATUS', 4631);

// Custom field codes
define('PARENT_ORDERS_FIELD', 3178);  // For storing child order IDs in parent
define('PARENT_ORDER_FIELD', 3179);   // For storing parent order ID in children

// Helper function to call Baselinker API
function callBaselinker(string $method, array $parameters): array {
    $data = [
        'token' => API_TOKEN,
        'method' => $method,
        'parameters' => json_encode($parameters)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?: [];
}

// Function to update custom fields of an order
function updateOrderFields(int $orderId, array $fields): void {
    $updateParams = [
        'order_id' => $orderId,
        'custom_extra_fields' => $fields
    ];
    $response = callBaselinker('setOrderFields', $updateParams);
    
    if ($response['status'] !== 'SUCCESS') {
        throw new Exception("Failed to update fields for order $orderId");
    }
}

try {
    // Step 1: Get order_id from URL
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) {
        throw new Exception("Missing order_id in URL");
    }

    // Step 2: Get order details
    $orderResponse = callBaselinker('getOrders', ['order_id' => $orderId,'get_unconfirmed_orders'=>true]);
    if ($orderResponse['status'] !== 'SUCCESS' || empty($orderResponse['orders'][0])) {
        throw new Exception("Order not found or API error");
    }

    $originalOrder = $orderResponse['orders'][0];
    $products = $originalOrder['products'] ?? [];

    if (empty($products)) {
        throw new Exception("No products found in order");
    }

    // Step 3: Extract product IDs
    $productIds = array_column($products, 'product_id');

    // Step 4: Get product tags
    $inventoryResponse = callBaselinker('getInventoryProductsData', [
        'inventory_id' => INVENTORY_ID,
        'products' => $productIds
    ]);

    if ($inventoryResponse['status'] !== 'SUCCESS' || empty($inventoryResponse['products'])) {
        throw new Exception("Failed to get product data");
    }

    $inventoryProducts = $inventoryResponse['products'];
    $tags = [];

    // Step 5: Collect tags for each product
    foreach ($products as $product) {
        $pid = $product['product_id'];
        $productData = $inventoryProducts[$pid] ?? null;
        
        if (!$productData || empty($productData['tags'][0])) {
            throw new Exception("Missing tag for product $pid");
        }
        
        $tags[] = $productData['tags'][0];
    }

    // Step 6: Check if all tags are the same
    $uniqueTags = array_unique($tags);
    $tagCount = count($uniqueTags);

    // Step 7-8: Single warehouse case
    if ($tagCount === 1) {
        $status = ($uniqueTags[0] === 'Bhopal') ? BHOPAL_STATUS : LUCKNOW_STATUS;
        $statusResponse = callBaselinker('setOrderStatus', [
            'order_id' => $orderId,
            'status_id' => $status
        ]);
        
        if ($statusResponse['status'] === 'SUCCESS') {
            echo "Order status updated to $status";
        } else {
            throw new Exception("Failed to update order status");
        }
    }
    // Step 9-12: Split order case
    else {
        $childOrderIds = []; // Store IDs of created child orders
        
        // Group products by tag
        $bhopalProducts = [];
        $lucknowProducts = [];

        foreach ($products as $product) {
            $pid = $product['product_id'];
            $tag = $inventoryProducts[$pid]['tags'][0];
            
            // Update warehouse for the product
            $product['warehouse_id'] = ($tag === 'Bhopal') ? BHOPAL_WAREHOUSE : LUCKNOW_WAREHOUSE;
            
            if ($tag === 'Bhopal') {
                $bhopalProducts[] = $product;
            } else {
                $lucknowProducts[] = $product;
            }
        }

        // Create new orders for each tag
        $newOrderBase = [
            'phone'                => $originalOrder['phone'],
            'email'                => $originalOrder['email'],
            'invoice_fullname'     => $originalOrder['invoice_fullname'],
            'invoice_company'      => $originalOrder['invoice_company'],
            'invoice_address'      => $originalOrder['invoice_address'],
            'invoice_city'         => $originalOrder['invoice_city'],
            'invoice_state'        => $originalOrder['invoice_state'],
            'invoice_postcode'     => $originalOrder['invoice_postcode'],
            'invoice_country_code' => $originalOrder['invoice_country_code'],
            'delivery_fullname'    => $originalOrder['delivery_fullname'],
            'delivery_address'     => $originalOrder['delivery_address'],
            'delivery_city'        => $originalOrder['delivery_city'],
            'delivery_state'       => $originalOrder['delivery_state'],
            'delivery_postcode'    => $originalOrder['delivery_postcode'],
            'delivery_country_code' => $originalOrder['delivery_country_code'],
            'delivery_method'      => $originalOrder['delivery_method'],
            'delivery_price'       => $originalOrder['delivery_price'],
            'payment_method'       => $originalOrder['payment_method'],
            'currency'             => $originalOrder['currency'],
            'user_comments'        => $originalOrder['user_comments'],
        ];

        // Create Bhopal order
        if (!empty($bhopalProducts)) {
            $bhopalOrder = $newOrderBase;
            $bhopalOrder['products'] = array_map(function($p) {
                return [
                    'product_id'   => $p['product_id'],
                    'variant_id'   => $p['variant_id'],
                    'quantity'     => $p['quantity'],
                    'price_brutto' => $p['price_brutto'],
                    'name'         => $p['name'],
                    'sku'          => $p['sku'],
                    'warehouse_id' => BHOPAL_WAREHOUSE,
                    'tax_rate'     => $p['tax_rate']
                ];
            }, $bhopalProducts);
            $bhopalOrder['order_status_id'] = BHOPAL_STATUS;
            
            $result = callBaselinker('addOrder', $bhopalOrder);
            if ($result['status'] !== 'SUCCESS') {
                throw new Exception("Failed to create Bhopal order");
            }
            
            // Store child order ID and update its parent field
            $childOrderId = $result['order_id'];
            $childOrderIds[] = $childOrderId;
            updateOrderFields($childOrderId, [PARENT_ORDER_FIELD => $orderId]);
        }

        // Create Lucknow order
        if (!empty($lucknowProducts)) {
            $lucknowOrder = $newOrderBase;
            $lucknowOrder['products'] = array_map(function($p) {
                return [
                    'product_id'   => $p['product_id'],
                    'variant_id'   => $p['variant_id'],
                    'quantity'     => $p['quantity'],
                    'price_brutto' => $p['price_brutto'],
                    'name'         => $p['name'],
                    'sku'          => $p['sku'],
                    'warehouse_id' => LUCKNOW_WAREHOUSE,
                    'tax_rate'     => $p['tax_rate']
                ];
            }, $lucknowProducts);
            $lucknowOrder['order_status_id'] = LUCKNOW_STATUS;
            
            $result = callBaselinker('addOrder', $lucknowOrder);
            if ($result['status'] !== 'SUCCESS') {
                throw new Exception("Failed to create Lucknow order");
            }
            
            // Store child order ID and update its parent field
            $childOrderId = $result['order_id'];
            $childOrderIds[] = $childOrderId;
            updateOrderFields($childOrderId, [PARENT_ORDER_FIELD => $orderId]);
        }

        // Update original order status
        $statusResponse = callBaselinker('setOrderStatus', [
            'order_id' => $orderId,
            'status_id' => SPLIT_STATUS
        ]);
        
        if ($statusResponse['status'] === 'SUCCESS') {
            // Update parent order with child IDs
            updateOrderFields($orderId, [PARENT_ORDERS_FIELD => implode(',', $childOrderIds)]);
            echo "Order split successfully. Parent order marked as split.";
        } else {
            throw new Exception("Failed to update parent order status");
        }
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>