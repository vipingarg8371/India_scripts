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
    $parentOrderId = (int)($_GET['parent_order_id'] ?? 0);
    $courierCode = ($_GET['courier_code']);
    $packageNumber = ($_GET['package_number']);
    if (!$parentOrderId) {
        throw new Exception("Missing order_id in URL");
    }
if (!$courierCode) {
        throw new Exception("Missing courier_code in URL");
    }
    if (!$packageNumber) {
        throw new Exception("Missing package_number in URL");
    }
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