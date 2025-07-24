<?php
// Configuration
define('API_TOKEN', '9000147-9000334-9OIVCWMEFIN2YMIEPPIH4QSGAISJA0AZSB8U8THGUTMSQIQ1S9QJ9S7EZ9GY76AX'); // Replace with your token
define('API_URL', 'https://api.baselinker.com/connector.php');

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

try {
    // Step 1: Get child order_id from URL
    $childOrderId = (int)($_GET['order_id'] ?? 0);
    if (!$childOrderId) {
        throw new Exception("Missing order_id in URL");
    }

    // Step 2: Get child order details
    $orderResponse = callBaselinker('getOrders', ['order_id' => $childOrderId,'include_custom_extra_fields'=>true,
'get_unconfirmed_orders'=>true]);
    if ($orderResponse['status'] !== 'SUCCESS' || empty($orderResponse['orders'][0])) {
        throw new Exception("Child order not found or API error");
    }

    $childOrder = $orderResponse['orders'][0];
    
    // Step 3: Extract parent order ID from custom field
    $parentOrderId = $childOrder['custom_extra_fields'][3179] ?? 0;
    if (!$parentOrderId) {
        throw new Exception("Parent order ID not found in custom field 3179");
    }
    
    // Step 4: Get package details
    $courierCode = $childOrder['delivery_package_module'] ?? 'other';
    $packageNumber = $childOrder['delivery_package_nr'] ?? '';
    
    
    
    if (empty($packageNumber)) {
        throw new Exception("Package number not found in child order");
    }

    // Step 5: Create package for parent order
    $createPackageResponse = callBaselinker('createPackageManual', [
        'order_id' => $parentOrderId,
        'courier_code' => $courierCode,
        'package_number' => $packageNumber
    ]);
    
    if ($createPackageResponse['status'] === 'SUCCESS') {
        echo "Package created successfully for parent order $parentOrderId";
    } else {
        $error = $createPackageResponse['error_message'] ?? json_encode($createPackageResponse);
        throw new Exception("Failed to create package: $error");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>