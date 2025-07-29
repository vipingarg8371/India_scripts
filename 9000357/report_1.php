<?php
set_time_limit(600); // 10 minutes

// Configuration
$apiToken = '9000149-9000357-VR7354HLHLF5QMSCJXPE8OVRBJRBRBY8TD28SLQ3JX0TCNYCIHIHING6JNWXPDJL';
$inventoryId = '437';
$timezone = new DateTimeZone('Asia/Kolkata');
define('BASELINKER_URL', 'https://api.baselinker.com/connector.php');

// Fetch data from Baselinker API
function callBaselinker($method, $params, $apiToken) {
    $payload = [
        'token' => $apiToken,
        'method' => $method,
        'parameters' => json_encode($params)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BASELINKER_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Step 1: Prepare date range for orders (last 14 days)
$endDate = new DateTime('yesterday 23:59:59', $timezone);
$startDate = clone $endDate;
$startDate->modify('-14 days')->setTime(0, 0, 0);
$startTimestamp = $startDate->getTimestamp();

// Step 2: Fetch orders with pagination
$allOrders = [];
$lastTimestamp = $startTimestamp;
do {
    $params = [
        'date_confirmed_from' => $lastTimestamp
        
    ];
    
    $response = callBaselinker('getOrders', $params, $apiToken);
    
    if ($response['status'] !== 'SUCCESS') {
        die("Error fetching orders: " . print_r($response, true));
    }
    
    $orders = $response['orders'];
    $allOrders = array_merge($allOrders, $orders);
    
    if (count($orders) < 100) break;
    
    // Get last order's timestamp + 1 second
    $lastOrder = end($orders);
    $lastTimestamp = $lastOrder['date_confirmed'] + 1;
} while (true);

// Step 3: Process orders and collect product data
$productDataMap = [];
$dailySales = [];
$auctionIdMap = []; // Maps product_id to auction_id (Seller SKU)

foreach ($allOrders as $order) {
    $orderDate = (new DateTime())->setTimestamp($order['date_add'])->setTimezone($timezone);
    $daysDiff = $orderDate->diff($startDate)->days;
    
    // Skip orders outside the 14-day window
    if ($daysDiff < 0 || $daysDiff > 13) continue;
    
    foreach ($order['products'] as $item) {
        $productId = $item['product_id'] ?? '';
        $auctionId = $item['auction_id'] ?? '';
        $quantity = $item['quantity'] ?? 0;
        
        if (empty($productId)) continue;
        
        // Initialize product data if not exists
        if (!isset($productDataMap[$productId])) {
            $productDataMap[$productId] = [
                'product_id' => $productId,
                'auction_id' => $auctionId,
                'sales' => array_fill(0, 14, 0)
            ];
        }
        
        // Update auction ID if not set
        if (empty($productDataMap[$productId]['auction_id']) && !empty($auctionId)) {
            $productDataMap[$productId]['auction_id'] = $auctionId;
        }
        
        // Add quantity to the corresponding day
        if ($daysDiff >= 0 && $daysDiff < 14) {
            $productDataMap[$productId]['sales'][$daysDiff] += $quantity;
        }
        
        // Map product ID to auction ID
        $auctionIdMap[$productId] = $auctionId;
    }
}

// Step 4: Get product details in chunks
$productsData = [];
$productIds = array_keys($productDataMap);
foreach (array_chunk($productIds, 100) as $chunk) {
    $params = ['inventory_id' => $inventoryId, 'products' => $chunk];
    $response = callBaselinker('getInventoryProductsData', $params, $apiToken);
    
    if ($response['status'] !== 'SUCCESS') {
        die("Error fetching product details: " . print_r($response, true));
    }
    
    foreach ($response['products'] as $productId => $product) {
        // Merge product details with our collected data
        $productsData[$productId] = array_merge(
            $productDataMap[$productId],
            $product
        );
    }
}

// Step 5: Prepare CSV data
$productRows = [];
$headerRow = [
    'Product Title', 'Seller SKU Id', 'Flipkart Serial Number', 'Listing ID',
    'MRP on the panel', 'Actual MRP', 'Sale Value', 'PO amt', 'Create PO today units',
    'Status based on current stock', 'DOH total', 'ATP DOH', 'Ordered Stock',
    'Stock as of Morning / ATP', 'DRR', 'Avg.', 'Median',
    'D-1', 'D-2', 'D-3', 'D-4', 'D-5', 'D-6', 'D-7',
    'D-8', 'D-9', 'D-10', 'D-11', 'D-12', 'D-13', 'D-14'
];

// Process each product and collect data
foreach ($productsData as $productId => $product) {
    $sales = $product['sales'] ?? array_fill(0, 14, 0);
    
    // Get product details
    $name = $product['text_fields']['name'] ?? '';
    $actualMRP = reset($product['prices']) ?: 0;
    $stock = reset($product['stock']) ?: 0;
    
    // Get Flipkart Serial Number and Listing ID
    $flipkartSerial = $product['text_fields']['extra_field_763'] ?? '';
    $listingId = $product['text_fields']['extra_field_764'] ?? '';
    
    // Use auction_id from orders as Seller SKU
    $auctionId = $product['auction_id'] ?? '';
    
    // Calculate metrics
    $saleValue = $actualMRP * 0.88;
    $avg = array_sum($sales) / 14;
    
    $sorted = $sales;
    sort($sorted);
    $median = ($sorted[6] + $sorted[7]) / 2; // Median for 14 days
    
    $drr = ($avg + $median) / 2;
    $atpDoh = $drr > 0 ? ceil($stock / $drr) : 999;
    
    // NEW: Calculate DOH total and Create PO today units
    $orderedStock = 0; // Set Ordered Stock to 0 for all products
    $dohTotal = ($drr > 0) ? ceil(($stock + $orderedStock) / $drr) : 999;
    
    // Create PO today units calculation
    if ($dohTotal < 7) {
        $createPO = $drr * (7 - $dohTotal);
        // Round to nearest whole number
        $createPO = round($createPO);
    } else {
        $createPO = 'False';
    }
    
    // Determine status based on ATP DOH
    $status = 'Code red';
    if ($atpDoh > 21) $status = 'High DOH';
    elseif ($atpDoh > 14) $status = 'Healthy DOH';
    elseif ($atpDoh > 7) $status = 'Good';
    elseif ($atpDoh > 4) $status = 'Critical raise PO\'s';
    
    // Output sales in chronological order (D-1 = first day, D-14 = last day)
    $dailyColumns = $sales;
    
    // Create row data with new calculations
    $row = [
        $name,                          // Product Title
        $auctionId,                     // Seller SKU Id
        $flipkartSerial,                // Flipkart Serial Number
        $listingId,                     // Listing ID
        '',                             // MRP on the panel
        $actualMRP,                     // Actual MRP
        $saleValue,                     // Sale Value
        '',                             // PO amt
        $createPO,                      // Create PO today units
        $status,                        // Status
        $dohTotal,                      // DOH total
        $atpDoh,                        // ATP DOH
        $orderedStock,                  // Ordered Stock
        $stock,                         // Stock as of Morning / ATP
        $drr,                           // DRR
        $avg,                           // Avg.
        $median                         // Median
    ];
    
    // Add daily sales columns in chronological order
    $row = array_merge($row, $dailyColumns);
    
    $productRows[] = $row;
}

// Step 6: Calculate GMV and Units for each day
$gmvRow = array_fill(0, count($headerRow), '');
$unitsRow = array_fill(0, count($headerRow), '');

// Set labels for GMV and Units rows
$gmvRow[16] = 'GMV';     // Column Q (index 16) is 'DRR', Q+1 = R (index 17) is D-1
$unitsRow[16] = 'Units'; // Same as above

// Columns 17 to 30 (indexes) = D-1 to D-14
for ($dayIndex = 0; $dayIndex < 14; $dayIndex++) {
    $columnIndex = 17 + $dayIndex; // Starting from column R (index 17 = D-1)

    $dailyUnits = 0;
    $dailyGMV = 0;

    foreach ($productRows as $row) {
        $unitsSold = (int)$row[$columnIndex];
        $saleValue = (float)$row[6]; // Column G: Sale Value

        $dailyUnits += $unitsSold;
        $dailyGMV += $saleValue * $unitsSold;
    }

    $unitsRow[$columnIndex] = $dailyUnits;
    $gmvRow[$columnIndex] = round($dailyGMV / 100000, 2); // round to 2 decimals if needed
}


// Step 7: Generate CSV report
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="product_report.csv"');
$output = fopen('php://output', 'w');

// Write GMV and Units rows first
fputcsv($output, $gmvRow, ',', '"', '\\');
fputcsv($output, $unitsRow, ',', '"', '\\');

// Then write header row
fputcsv($output, $headerRow, ',', '"', '\\');

// Finally write all product rows
foreach ($productRows as $row) {
    fputcsv($output, $row, ',', '"', '\\');
}

fclose($output);
exit;