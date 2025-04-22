<?php
// filepath: webhook.php

define('DATA_DIR', __DIR__ . '/data');
define('TRANSACTIONS_FILE', DATA_DIR . '/transactions.txt');
define('HISTORY_FILE', DATA_DIR . '/payment_history.txt');
define('LOG_FILE', DATA_DIR . '/app_log.txt');
define('BACKUP_LOG_FILE', DATA_DIR . '/transaction_backup.txt');

// --- Include Functions ---
require_once 'validation_functions.php';

// --- Configuration ---
$accessToken = 'accesstoken'; // SUBSTITUA PELO SEU ACCESS TOKEN
$webhookSecret = 'webhooksecret'; // SUBSTITUA PELO SEU WEBHOOKSECRET

function is_valid_signature($secret, $payload, $signatureHeader) {
    log_message("--- Webhook Signature Validation SKIPPED (SECURITY DISABLED) ---");
    // Always return true to accept any request
    return true;

    /* --- ORIGINAL VALIDATION LOGIC (DISABLED) ---
    log_message("--- Webhook Signature Validation Start ---");
    log_message("Secret (first 5 chars): " . substr($secret, 0, 5));
    log_message("Payload Length: " . strlen($payload));
    log_message("Signature Header Received: " . $signatureHeader);

    if (empty($signatureHeader)) {
        log_message("Webhook Signature Validation FAILED: Header 'X-Signature' missing.");
        return false;
    }

    // Extract timestamp and hash
    $parts = explode(',', $signatureHeader);
    $ts = null;
    $hash_v1 = null;
    foreach ($parts as $part) {
        list($key, $value) = explode('=', trim($part), 2);
        if ($key === 'ts') {
            $ts = $value;
        } elseif ($key === 'v1') {
            $hash_v1 = $value;
        }
    }

    if (!$ts || !$hash_v1) {
        log_message("Webhook Signature Validation FAILED: Could not extract 'ts' or 'v1' from header.");
        return false;
    }
    log_message("Extracted ts: " . $ts);
    log_message("Extracted hash (v1): " . $hash_v1);

    // Optional: Check timestamp tolerance (e.g., within 5 minutes)
    $tolerance = 300; // 5 minutes in seconds
    if (abs(time() - ($ts / 1000)) > $tolerance) { // MP timestamp is in milliseconds
        log_message("Webhook Signature Validation FAILED: Timestamp outside tolerance. Current time: " . time() . ", Header ts (sec): " . ($ts / 1000));
        // return false; // Be cautious enabling this, clock skew can be an issue
    }

    // Construct the manifest string
    // IMPORTANT: Ensure this matches EXACTLY what Mercado Pago uses.
    // It might depend on the specific notification type.
    // Common format: id:{data.id};request-timestamp:{ts};
    // Need to extract data.id from the payload JSON first
    $payloadData = json_decode($payload, true);
    $dataId = $payloadData['data']['id'] ?? null;
    if (!$dataId) {
         log_message("Webhook Signature Validation FAILED: Could not extract 'data.id' from payload.");
         return false; // Cannot construct manifest without ID
    }
    log_message("Extracted data.id from payload: " . $dataId);

    $manifest = "id:{$dataId};request-timestamp:{$ts};";
    log_message("Constructed Manifest String: " . $manifest);



    $expectedSignature = hash_hmac('sha256', $manifest, $secret);
    log_message("Calculated Signature (HMAC SHA256): " . $expectedSignature);


    if (hash_equals($expectedSignature, $hash_v1)) {
        log_message("Webhook Signature Validation SUCCESSFUL.");
        log_message("--- Webhook Signature Validation End (Success) ---");
        return true;
    } else {
        log_message("Webhook Signature Validation FAILED: Calculated signature does not match header signature.");
        log_message("--- Webhook Signature Validation End (Failure) ---");
        return false;
    }

}





$requestBody = file_get_contents('php://input');
$headers = getallheaders();
$signatureHeader = $headers['X-Signature'] ?? $headers['x-signature'] ?? null;
$requestIdHeader = $headers['X-Request-Id'] ?? $headers['x-request-id'] ?? 'N/A';

log_message("Webhook received. Request ID: {$requestIdHeader}. Body length: " . strlen($requestBody));


if (!is_valid_signature($webhookSecret, $requestBody, $signatureHeader)) {

    log_message("Webhook processing stopped due to INVALID signature (THIS SHOULD NOT HAPPEN). Request ID: {$requestIdHeader}");
    http_response_code(401); // Unauthorized
    echo "Invalid signature.";
    exit;
} else {

     log_message("Webhook signature validation was skipped (SECURITY DISABLED). Processing continues...");
}



$notification = json_decode($requestBody, true);
log_raw_transaction('webhook_notification', $notification); // Log raw notification


if (!$notification || !isset($notification['type']) || !isset($notification['action']) || !isset($notification['data']['id'])) {
    log_message("Webhook received invalid JSON or missing required fields (type, action, data.id). Request ID: {$requestIdHeader}. Body: " . $requestBody);
    http_response_code(400); // Bad Request
    echo "Invalid payload structure.";
    exit;
}

$notificationType = $notification['type'];
$notificationAction = $notification['action'];
$dataId = $notification['data']['id']; // This is usually the Payment ID for payment notifications

log_message("Webhook processing: Type='{$notificationType}', Action='{$notificationAction}', DataID='{$dataId}'. Request ID: {$requestIdHeader}");


$notification = json_decode($requestBody, true);
log_raw_transaction('webhook_notification', $notification); // Log raw notification


$notificationType = null;
$notificationAction = null; // Action pode não estar presente no formato resource/topic
$dataId = null;

if (isset($notification['type']) && isset($notification['data']['id'])) {

    $notificationType = $notification['type'];
    $notificationAction = $notification['action'] ?? null; // Action pode ser nulo
    $dataId = $notification['data']['id'];
    log_message("Webhook format detected: type/action/data.id");

} elseif (isset($notification['topic']) && isset($notification['resource'])) {

    $notificationType = $notification['topic']; // e.g., 'payment', 'merchant_order'

    $resourceParts = explode('/', $notification['resource']);
    $dataId = end($resourceParts); // Pega a última parte da URL/resource string
    log_message("Webhook format detected: topic/resource");

} else {
    log_message("Webhook received invalid JSON or unrecognized structure. Request ID: {$requestIdHeader}. Body: " . $requestBody);
    http_response_code(400); // Bad Request
    echo "Invalid payload structure.";
    exit;
}



if (empty($dataId) || $notificationType !== 'payment') {
     log_message("Webhook ignored: Invalid DataID or unhandled Type='{$notificationType}'. Request ID: {$requestIdHeader}");
     http_response_code(200); 
     echo "Notification ignored (invalid data or type).";
     exit;
}


log_message("Webhook processing: Type='{$notificationType}', Action='{$notificationAction}', DataID='{$dataId}'. Request ID: {$requestIdHeader}");


if ($notificationType === 'payment') {
    $paymentId = $dataId;
    log_message("Processing 'payment' notification for Payment ID: $paymentId. Action: {$notificationAction}. Request ID: {$requestIdHeader}");


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.mercadopago.com/v1/payments/" . $paymentId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json'
    ]);


    $response = curl_exec($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        log_message("Error querying payment status via API for ID $paymentId: $curlError. Request ID: {$requestIdHeader}");
        http_response_code(500); // Internal Server Error (couldn't reach MP API)
        echo "Error fetching payment details from API.";
        exit;
    }


    if ($httpStatusCode === 404) {
        log_message("API returned 404 Not Found when querying payment ID $paymentId. Request ID: {$requestIdHeader}");
        http_response_code(200); // Respond 200 OK to MP, but log the issue. Don't retry 404s.
        echo "Payment ID not found via API, webhook ignored.";
        exit;
    } elseif ($httpStatusCode !== 200) {
        log_message("API returned status $httpStatusCode when querying payment ID $paymentId. Response: $response. Request ID: {$requestIdHeader}");
        http_response_code($httpStatusCode >= 500 ? 503 : 500);
        echo "Error fetching payment details from API (Status: $httpStatusCode).";
        exit;
    }


    $paymentDetails = json_decode($response, true);
    log_raw_transaction('webhook_api_details', $paymentDetails); // Log details obtained from API

    if (!$paymentDetails || !isset($paymentDetails['external_reference']) || !isset($paymentDetails['status'])) {
         log_message("Invalid or incomplete payment details received from API for ID $paymentId. Response: $response. Request ID: {$requestIdHeader}");
         http_response_code(500); // Internal Server Error (unexpected API response)
         echo "Invalid payment data structure from API.";
         exit;
    }

    $externalReference = $paymentDetails['external_reference'];
    $newStatus = $paymentDetails['status'];
    $statusDetail = $paymentDetails['status_detail'] ?? 'N/A';

    log_message("Payment ID $paymentId (Ref: $externalReference) status confirmed by API: '$newStatus' (Detail: '$statusDetail'). Request ID: {$requestIdHeader}");
    $transaction = get_transaction($externalReference);

    if ($transaction) {

        if ($transaction['status'] !== $newStatus) {
            log_message("Status change detected for Ref: $externalReference. Old: '{$transaction['status']}', New: '$newStatus'. Request ID: {$requestIdHeader}");


            $transaction['status'] = $newStatus;
            $transaction['status_details'] = $statusDetail;
            $transaction['payment_details_api'] = $paymentDetails; // Store the full details from API query
            $transaction['date_last_updated'] = date('Y-m-d H:i:s');
            $transaction['transaction_amount_refunded'] = $paymentDetails['transaction_amount_refunded'] ?? ($transaction['transaction_amount_refunded'] ?? 0);



            if (save_transaction($transaction)) {
                log_message("Transaction $externalReference updated successfully to status: $newStatus. Request ID: {$requestIdHeader}");
                 $finalStatuses = ['approved', 'cancelled', 'rejected', 'refunded', 'charged_back'];
                 if (in_array($newStatus, $finalStatuses)) {
                      if(add_to_history($transaction)) {
                          log_message("Final status '$newStatus' for Ref: $externalReference added to history via webhook. Request ID: {$requestIdHeader}");
                      } else {
                          log_message("ERROR: Failed to add final status '$newStatus' to history via webhook for Ref: $externalReference. Request ID: {$requestIdHeader}");
                      }
                 }
            } else {
                 log_message("ERROR: Failed to save updated transaction $externalReference (Status: $newStatus). Request ID: {$requestIdHeader}");
                 http_response_code(500);
                 echo "Error saving transaction update.";
                 exit;
            }

        } else {
            log_message("Transaction $externalReference already has status: '$newStatus'. No update needed via webhook. Request ID: {$requestIdHeader}");
        }
    } else {
        log_message("WARN: Webhook received for unknown external reference: '$externalReference' (Payment ID: $paymentId). Transaction not found locally. Request ID: {$requestIdHeader}");
    }

    http_response_code(200);
    echo "Webhook received and processed successfully.";
    log_message("Webhook for payment $paymentId processed successfully. Responded 200 OK. Request ID: {$requestIdHeader}");

} else {
    log_message("Received unhandled notification type: '{$notificationType}'. Action: '{$notificationAction}'. DataID: '{$dataId}'. Request ID: {$requestIdHeader}");
    http_response_code(200);
    echo "Notification type '{$notificationType}' received but not processed by this handler.";
}

exit; 
?>
