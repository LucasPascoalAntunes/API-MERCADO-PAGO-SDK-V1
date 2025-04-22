<?php
function log_message(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}
function log_raw_transaction(string $type, $data): void {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] --- {$type} ---" . PHP_EOL;
    $logEntry .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $logEntry .= "--- END {$type} ---" . PHP_EOL . PHP_EOL;
    $logDir = dirname(BACKUP_LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    file_put_contents(BACKUP_LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}
function cancel_pending_pix_for_user(string $userId, string $accessToken): void {
    log_message("Checking for pending PIX to cancel for User ID: $userId");
    if (!file_exists(TRANSACTIONS_FILE)) {
        log_message("No transactions file found, nothing to cancel for User ID: $userId");
        return;
    }
    $lines = file(TRANSACTIONS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        log_message("ERROR: Failed to read transactions file for cancellation check: " . TRANSACTIONS_FILE);
        return;
    }
    $updatedTransactions = [];
    $foundPending = false;
    $allTransactions = [];
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if ($entry && isset($entry['external_reference'])) {
            $allTransactions[$entry['external_reference']] = $entry;
        }
    }
    foreach ($allTransactions as $ref => $transaction) {
        if (isset($transaction['user_id']) && $transaction['user_id'] === $userId &&
            isset($transaction['payment_method_type']) && $transaction['payment_method_type'] === 'pix' &&
            isset($transaction['status']) && in_array($transaction['status'], ['pending', 'in_process']) &&
            isset($transaction['payment_id']))
        {
            $foundPending = true;
            $paymentId = $transaction['payment_id'];
            log_message("Found pending PIX for User ID: $userId. Ref: $ref, Payment ID: $paymentId. Attempting cancellation.");
            $apiUrl = "https://api.mercadopago.com/v1/payments/{$paymentId}";
            $payload = json_encode(['status' => 'cancelled']);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Idempotency-Key: cancel-' . $paymentId . '-' . time()
            ]);
            $response = curl_exec($ch);
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($curlError) {
                log_message("ERROR: Curl Error cancelling Payment ID {$paymentId} (Ref: $ref): $curlError");
                $updatedTransactions[$ref] = $transaction;
            } else {
                $responseBody = json_decode($response, true);
                log_raw_transaction('api_response_cancel', $responseBody);
                if ($httpStatusCode >= 200 && $httpStatusCode < 300 && isset($responseBody['status']) && $responseBody['status'] === 'cancelled') {
                    log_message("SUCCESS: Cancelled Payment ID {$paymentId} (Ref: $ref) via API. Status: {$responseBody['status']}");
                    $transaction['status'] = 'cancelled';
                    $transaction['status_details'] = 'cancelled_by_new_payment';
                    $transaction['date_last_updated'] = date('Y-m-d H:i:s');
                    $transaction['payment_details_api'] = $responseBody;
                    if (add_to_history($transaction)) {
                        log_message("Added cancelled transaction Ref: $ref to history.");
                    } else {
                        log_message("ERROR: Failed to add cancelled transaction Ref: $ref to history.");
                    }
                    $updatedTransactions[$ref] = $transaction;
                } else {
                    log_message("WARN: Failed to cancel Payment ID {$paymentId} (Ref: $ref) via API. HTTP: $httpStatusCode. Response: " . $response);
                    $updatedTransactions[$ref] = $transaction;
                }
            }
        } else {
            $updatedTransactions[$ref] = $transaction;
        }
    }
    if ($foundPending) {
        log_message("Rewriting transactions file after cancellation check for User ID: $userId");
        $fileContent = '';
        foreach ($updatedTransactions as $entry) {
            $fileContent .= json_encode($entry) . PHP_EOL;
        }
        if (file_put_contents(TRANSACTIONS_FILE, $fileContent, LOCK_EX) === false) {
            log_message("ERROR: Failed to rewrite transactions file after cancellation: " . TRANSACTIONS_FILE);
        }
    } else {
        log_message("No pending PIX transactions found to cancel for User ID: $userId");
    }
}
function validateInput(string $userId, string $payerName, string $payerEmail, string $payerDocNumber): array {
    $errors = [];
    if (empty(trim($userId))) {
        $errors[] = 'ID do Usuário é obrigatório.';
    }
    if (empty(trim($payerName))) {
        $errors[] = 'Nome do Pagador é obrigatório.';
    }
    if (!filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email do Pagador inválido.';
    }
    if (empty($payerDocNumber) || !ctype_digit($payerDocNumber)) {
        $errors[] = 'Número do Documento inválido (deve conter apenas números).';
    }
    return $errors;
}
function map_api_error(int $httpStatusCode, ?array $responseBody): string {
    $message = $responseBody['message'] ?? 'Erro desconhecido';
    $causes = $responseBody['cause'] ?? [];
    $details = [];
    if (!empty($causes)) {
        if (isset($causes[0]) && is_array($causes[0])) {
            foreach ($causes as $cause) {
                $details[] = $cause['description'] ?? $cause['code'] ?? 'Detalhe desconhecido';
            }
        } elseif (isset($causes['description']) || isset($causes['code'])) {
             $details[] = $causes['description'] ?? $causes['code'] ?? 'Detalhe desconhecido';
        }
    }
    $detailString = !empty($details) ? ' (' . implode(', ', $details) . ')' : '';
    switch ($httpStatusCode) {
        case 400:
             $firstCauseCode = $responseBody['cause'][0]['code'] ?? $responseBody['cause']['code'] ?? null;
             if ($firstCauseCode == 3034) {
                 return 'Número do cartão inválido.';
             }
            return "Erro nos dados enviados: {$message}{$detailString}";
        case 401:
        case 403:
            return "Erro de autenticação ou autorização: {$message}{$detailString}";
        case 404:
            return "Recurso não encontrado: {$message}{$detailString}";
        case 429:
            return "Muitas requisições. Tente novamente mais tarde.";
        case 500:
        case 502:
        case 503:
        case 504:
            return "Erro interno do servidor do Mercado Pago. Tente novamente mais tarde.";
        default:
            return "Erro inesperado (HTTP {$httpStatusCode}): {$message}{$detailString}";
    }
}
function save_transaction(array $transactionData): bool {
    if (!isset($transactionData['external_reference'])) {
        log_message("ERROR: Cannot save transaction - external_reference missing.");
        return false;
    }
    $ref = $transactionData['external_reference'];
    $allTransactions = [];
    $dataDir = dirname(TRANSACTIONS_FILE);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    if (file_exists(TRANSACTIONS_FILE)) {
        $lines = file(TRANSACTIONS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            log_message("ERROR: Failed to read transactions file for saving: " . TRANSACTIONS_FILE);
            return false;
        }
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['external_reference'])) {
                $allTransactions[$entry['external_reference']] = $entry;
            } else {
                 log_message("WARN: Failed to decode line or missing reference in transactions file: " . $line);
            }
        }
    }
    $allTransactions[$ref] = $transactionData;
    $fileContent = '';
    foreach ($allTransactions as $entry) {
        if (is_array($entry) && isset($entry['external_reference'])) {
            $fileContent .= json_encode($entry) . PHP_EOL;
        }
    }
    if (file_put_contents(TRANSACTIONS_FILE, $fileContent, LOCK_EX) === false) {
        log_message("ERROR: Failed to write transactions file: " . TRANSACTIONS_FILE);
        return false;
    }
    log_message("Transaction {$ref} saved/updated successfully.");
    return true;
}
function get_transaction(string $externalReference): ?array {
    if (!file_exists(TRANSACTIONS_FILE) || empty($externalReference)) {
        return null;
    }
    $lines = file(TRANSACTIONS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        log_message("ERROR: Failed to read transactions file for getting: " . TRANSACTIONS_FILE);
        return null;
    }
    $lines = array_reverse($lines);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if ($entry && isset($entry['external_reference']) && $entry['external_reference'] === $externalReference) {
            return $entry;
        }
    }
    return null;
}
function get_payment_history(int $limit = 50, ?string $userId = null): array {
    if (!file_exists(HISTORY_FILE)) {
        return [];
    }
    $history = [];
    $lines = file(HISTORY_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        log_message("ERROR: Failed to read history file: " . HISTORY_FILE);
        return [];
    }
    $lines = array_reverse($lines);
    $count = 0;
    foreach ($lines as $line) {
        if ($count >= $limit) {
            break;
        }
        $entry = json_decode($line, true);
        if ($entry) {
            if ($userId === null || (isset($entry['user_id']) && $entry['user_id'] === $userId)) {
                $history[] = $entry;
                $count++;
            }
        } else {
            log_message("WARN: Failed to decode history line: " . $line);
        }
    }
    return $history;
}
function add_to_history(array $transaction): bool {
    $payerFullName = trim(($transaction['payer']['first_name'] ?? '') . ' ' . ($transaction['payer']['last_name'] ?? ''));
    if (empty($payerFullName)) {
         $payerFullName = $transaction['cardholder_name'] ?? 'N/A';
    }
    $docType = $transaction['payer']['identification']['type'] ?? 'N/A';
    $docNum = $transaction['payer']['identification']['number'] ?? 'N/A';
    $payerDocument = ($docType !== 'N/A' || $docNum !== 'N/A') ? "{$docType}: {$docNum}" : 'N/A';
    $paymentDetails = $transaction['payment_details_api'] ?? $transaction['payment_details_initial'] ?? [];
    $cardDetails = $paymentDetails['card'] ?? null;
    $cardBin = $cardDetails['first_six_digits'] ?? 'N/A';
    $cardLastFour = $cardDetails['last_four_digits'] ?? 'N/A';
    $cardExpMonth = $cardDetails['expiration_month'] ?? 'N/A';
    $cardExpYear = $cardDetails['expiration_year'] ?? 'N/A';
    $cardExpiry = ($cardExpMonth !== 'N/A' && $cardExpYear !== 'N/A') ? sprintf('%02d/%d', $cardExpMonth, $cardExpYear) : 'N/A';
    $issuerId = $paymentDetails['issuer_id'] ?? 'N/A';
    $installments = $paymentDetails['installments'] ?? 'N/A';
    $paymentMethodId = $paymentDetails['payment_method_id'] ?? 'N/A';
    $entry = [
        'timestamp' => $transaction['date_last_updated'] ?? $transaction['date_created'] ?? date('Y-m-d H:i:s'),
        'external_reference' => $transaction['external_reference'] ?? 'N/A',
        'payment_id' => $transaction['payment_id'] ?? 'N/A',
        'user_id' => $transaction['user_id'] ?? 'N/A',
        'payer_name' => $payerFullName,
        'payer_email' => $transaction['payer']['email'] ?? 'N/A',
        'payer_document' => $payerDocument,
        'payment_method_type' => $transaction['payment_method_type'] ?? 'N/A',
        'payment_method_id' => $paymentMethodId,
        'issuer_id' => $issuerId,
        'installments' => $installments,
        'card_bin' => $cardBin,
        'card_last_four' => $cardLastFour,
        'card_expiry' => $cardExpiry,
        'status' => $transaction['status'] ?? 'N/A',
        'status_details' => $transaction['status_details'] ?? 'N/A',
        'transaction_amount' => $transaction['transaction_amount'] ?? 0,
        'amount_refunded' => $paymentDetails['transaction_amount_refunded'] ?? ($transaction['transaction_amount_refunded'] ?? 0),
        'error_cause' => ($transaction['status'] === 'rejected' || $transaction['status'] === 'failed')
                         ? json_encode($paymentDetails['cause'] ?? null)
                         : null,
    ];
    $line = json_encode($entry) . PHP_EOL;
    $logDir = dirname(HISTORY_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    if (file_put_contents(HISTORY_FILE, $line, FILE_APPEND | LOCK_EX) === false) {
        log_message("ERROR: Failed to write to history file: " . HISTORY_FILE);
        return false;
    }
    return true;
}
?>
