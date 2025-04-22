<?php
session_start();
define('DATA_DIR', __DIR__ . '/data');
define('TRANSACTIONS_FILE', DATA_DIR . '/transactions.txt');
define('HISTORY_FILE', DATA_DIR . '/payment_history.txt');
define('LOG_FILE', DATA_DIR . '/app_log.txt');
define('BACKUP_LOG_FILE', DATA_DIR . '/transaction_backup.txt');
if (!is_dir(DATA_DIR)) {
    if (!mkdir(DATA_DIR, 0777, true)) {
        die("Falha ao criar o diretório de dados: " . DATA_DIR);
    }
}
require_once 'validation_functions.php';
$accessToken = 'acesstoken';
$publicKey = 'publickey';
$notificationUrl = 'webhook';
$webhookSecret = 'webhooksecret';
$statementDescriptor = "SUAEMPRESA";
$itemId = "PROD001";
$itemTitle = "Livro Exemplo SDK V2";
$itemDescription = "Livro sobre integração Mercado Pago V2";
$itemCategory = "books";
$itemQuantity = 1;
$formError = null;
$apiError = null;
$paymentResponse = null;
$currentTransaction = null;
$showQrSection = false;
$showCardResultSection = false;
$paymentStatus = 'new';
$paymentMethod = $_POST['payment_method'] ?? ($_GET['method'] ?? 'not_selected');
$viewUserHistoryId = filter_input(INPUT_GET, 'view_user_history_id', FILTER_SANITIZE_STRING);
$userPaymentHistory = [];
$transactionAmount = 01.00;
$userId = $_POST['user_id'] ?? ($_SESSION['post_data']['user_id'] ?? '');
$payerName = $_POST['name'] ?? ($_SESSION['post_data']['name'] ?? '');
$payerEmail = $_POST['email'] ?? ($_SESSION['post_data']['email'] ?? '');
$payerDocType = $_POST['doc_type'] ?? ($_SESSION['post_data']['doc_type'] ?? '');
$payerDocNumber = $_POST['doc_number'] ?? ($_SESSION['post_data']['doc_number'] ?? '');
$cardholderName = $_POST['cardholderName'] ?? ($_SESSION['post_data']['cardholderName'] ?? '');
$identificationType = $_POST['identificationType'] ?? ($_SESSION['post_data']['identificationType'] ?? '');
$identificationNumber = $_POST['identificationNumber'] ?? ($_SESSION['post_data']['identificationNumber'] ?? '');
$installments = $_POST['installments'] ?? ($_SESSION['post_data']['installments'] ?? '');
$issuer = $_POST['issuer'] ?? ($_SESSION['post_data']['issuer'] ?? '');
unset($_SESSION['post_data']);
if (isset($_GET['action']) && $_GET['action'] === 'check_status' && isset($_GET['ref'])) {
    header('Content-Type: application/json');
    $refToCheck = filter_input(INPUT_GET, 'ref', FILTER_SANITIZE_STRING);
    $transaction = get_transaction($refToCheck);
    if ($transaction) {
        echo json_encode(['status' => $transaction['status'], 'details' => $transaction['status_details'] ?? 'N/A']);
    } else {
        echo json_encode(['status' => 'not_found', 'details' => 'Referência não encontrada']);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['result_ref'])) {
        $ref = filter_input(INPUT_GET, 'result_ref', FILTER_SANITIZE_STRING);
        log_message("GET request received for result_ref: $ref");
        if (isset($_SESSION['last_transaction']) && $_SESSION['last_transaction']['external_reference'] === $ref) {
            $currentTransaction = $_SESSION['last_transaction'];
            unset($_SESSION['last_transaction']);
            log_message("Transaction $ref found in session.");
        } else {
            $currentTransaction = get_transaction($ref);
            if ($currentTransaction) {
                log_message("Transaction $ref found in file.");
            } else {
                log_message("Transaction $ref NOT found in session or file.");
                $apiError = "Transação não encontrada ($ref).";
            }
        }
        if ($currentTransaction) {
            $paymentStatus = $currentTransaction['status'];
            $paymentMethod = $currentTransaction['payment_method_type'];
            if ($paymentMethod === 'pix' && in_array($paymentStatus, ['pending', 'in_process'])) {
                $showQrSection = true;
            } elseif ($paymentMethod === 'credit_card') {
                $showCardResultSection = true;
            }
        }
    } elseif ($viewUserHistoryId) {
        log_message("GET request received for user history view: User ID = $viewUserHistoryId");
        $userPaymentHistory = get_payment_history(50, $viewUserHistoryId);
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['post_data'] = $_POST;
    $paymentMethod = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_SANITIZE_STRING);
    $payerName = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $validationErrors = [];
    if (empty($userId)) $validationErrors[] = 'ID do Usuário não fornecido.';
    if (empty($payerName)) $validationErrors[] = 'Nome do Pagador não fornecido.';
    if (empty($paymentMethod) || !in_array($paymentMethod, ['pix', 'credit_card'])) {
        $validationErrors[] = 'Método de pagamento inválido.';
    }
    $payload = null;
    $apiUrl = 'https://api.mercadopago.com/v1/payments';
    $externalReference = 'TX-' . strtoupper(substr(md5($userId . time()), 0, 6)) . '-' . time();
    $idempotencyKey = uniqid($paymentMethod . '-req-', true);
    $nameParts = explode(' ', trim($payerName), 2);
    $payerFirstName = $nameParts[0] ?? 'Pagador';
    $payerLastName = $nameParts[1] ?? 'Teste';
    $itemDetails = [
        'id' => $itemId,
        'title' => $itemTitle,
        'description' => $itemDescription,
        'category_id' => $itemCategory,
        'quantity' => $itemQuantity,
        'unit_price' => $transactionAmount
    ];
    if ($paymentMethod === 'pix') {
        $payerEmail = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $rawDoc = filter_input(INPUT_POST, 'doc_number', FILTER_SANITIZE_STRING);
        $payerDocNumber = preg_replace('/[^0-9]/', '', $rawDoc);
        $payerDocType = filter_input(INPUT_POST, 'doc_type', FILTER_SANITIZE_STRING) ?: 'CPF';
        $pixValidationErrors = validateInput($userId, $payerName, $payerEmail, $payerDocNumber);
        $validationErrors = array_merge($validationErrors, $pixValidationErrors);
        if (empty($validationErrors)) {
            log_message("Attempting to cancel previous pending PIX for User ID: $userId before creating new one.");
            cancel_pending_pix_for_user($userId, $accessToken);
            $expirationTime = date('Y-m-d\TH:i:s.vP', time() + 900);
            $payload = [
                'transaction_amount' => $transactionAmount,
                'description' => $itemTitle,
                'payment_method_id' => 'pix',
                'payer' => [
                    'email' => $payerEmail,
                    'first_name' => $payerFirstName,
                    'last_name' => $payerLastName,
                    'identification' => [
                        'type' => $payerDocType,
                        'number' => $payerDocNumber
                    ]
                ],
                'external_reference' => $externalReference,
                'notification_url' => $notificationUrl,
                'date_of_expiration' => $expirationTime,
                'statement_descriptor' => $statementDescriptor,
                'additional_info' => [
                    'items' => [$itemDetails]
                ]
            ];
            log_message("PIX Payload prepared for $externalReference with expiration: $expirationTime");
        }
    } elseif ($paymentMethod === 'credit_card') {
        $token = filter_input(INPUT_POST, 'token', FILTER_SANITIZE_STRING);
        $installments = filter_input(INPUT_POST, 'installments', FILTER_VALIDATE_INT);
        $paymentMethodId = filter_input(INPUT_POST, 'paymentMethodId', FILTER_SANITIZE_STRING);
        $issuerId = filter_input(INPUT_POST, 'issuer', FILTER_SANITIZE_STRING);
        $payerEmail = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $payerDocType = filter_input(INPUT_POST, 'identificationType', FILTER_SANITIZE_STRING);
        $payerDocNumber = preg_replace('/[^0-9]/', '', filter_input(INPUT_POST, 'identificationNumber', FILTER_SANITIZE_STRING));
        $cardholderName = filter_input(INPUT_POST, 'cardholderName', FILTER_SANITIZE_STRING);
        if (empty($token)) $validationErrors[] = 'Token do cartão não recebido.';
        if (empty($installments) || $installments < 1) $validationErrors[] = 'Número de parcelas inválido.';
        if (empty($paymentMethodId)) $validationErrors[] = 'ID do método de pagamento (bandeira) não recebido.';
        if (!filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) $validationErrors[] = 'Email do pagador inválido.';
        if (empty($payerDocType)) $validationErrors[] = 'Tipo de documento do pagador não recebido.';
        if (empty($payerDocNumber)) $validationErrors[] = 'Número do documento do pagador não recebido.';
        if (empty($cardholderName)) $validationErrors[] = 'Nome do titular do cartão não recebido.';
        if (empty($validationErrors)) {
            $payload = [
                'transaction_amount' => $transactionAmount,
                'token' => $token,
                'description' => $itemTitle,
                'installments' => $installments,
                'payment_method_id' => $paymentMethodId,
                'issuer_id' => $issuerId,
                'payer' => [
                    'email' => $payerEmail,
                    'first_name' => $payerFirstName,
                    'last_name' => $payerLastName,
                    'identification' => [
                        'type' => $payerDocType,
                        'number' => $payerDocNumber
                    ]
                ],
                'external_reference' => $externalReference,
                'notification_url' => $notificationUrl,
                'statement_descriptor' => $statementDescriptor,
                'additional_info' => [
                    'items' => [$itemDetails]
                ]
            ];
            log_message("Card Payload prepared for $externalReference");
        }
    }
    if (empty($validationErrors) && $payload) {
        log_raw_transaction('creation_payload', $payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Idempotency-Key: ' . $idempotencyKey
        ]);
        $response = curl_exec($ch);
        $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        $redirectUrl = null;
        $userFriendlyApiError = null;
        if ($curlError) {
            log_message("Curl Error for $externalReference: $curlError");
            $userFriendlyApiError = "Erro de comunicação ao processar o pagamento. Tente novamente.";
        } else {
            $paymentResponse = json_decode($response, true);
            log_raw_transaction('api_response_create', $paymentResponse);
            if ($httpStatusCode >= 200 && $httpStatusCode < 300 && $paymentResponse && isset($paymentResponse['id'])) {
                log_message("Payment API call successful for $externalReference (HTTP $httpStatusCode). Payment ID: {$paymentResponse['id']}");
                $transactionData = [
                    'external_reference' => $externalReference,
                    'payment_id' => $paymentResponse['id'],
                    'status' => $paymentResponse['status'],
                    'status_details' => $paymentResponse['status_detail'] ?? 'N/A',
                    'payment_method_type' => $paymentMethod,
                    'date_created' => $paymentResponse['date_created'] ?? date('Y-m-d H:i:s'),
                    'date_last_updated' => $paymentResponse['date_last_updated'] ?? date('Y-m-d H:i:s'),
                    'transaction_amount' => $transactionAmount,
                    'user_id' => $userId,
                    'payer' => $payload['payer'],
                    'cardholder_name' => $cardholderName ?? null,
                    'payment_details_initial' => $paymentResponse,
                    'payment_details_api' => null,
                    'transaction_amount_refunded' => $paymentResponse['transaction_amount_refunded'] ?? 0,
                ];
                if (save_transaction($transactionData)) {
                    log_message("Transaction $externalReference saved locally with status: {$transactionData['status']}");
                    $finalStatuses = ['approved', 'rejected', 'cancelled', 'refunded', 'charged_back'];
                    if (in_array($transactionData['status'], $finalStatuses)) {
                        if(add_to_history($transactionData)) {
                            log_message("Initial final status '{$transactionData['status']}' for Ref: $externalReference added to history.");
                        } else {
                            log_message("ERROR: Failed to add initial final status '{$transactionData['status']}' to history for Ref: $externalReference.");
                        }
                    }
                } else {
                    log_message("ERROR: Failed to save transaction $externalReference locally after successful API call.");
                }
                $_SESSION['last_transaction'] = $transactionData;
                $redirectUrl = "index.php?result_ref=" . urlencode($externalReference);
            } else {
                log_message("API Error for $externalReference (HTTP $httpStatusCode): " . $response);
                $userFriendlyApiError = map_api_error($httpStatusCode, $paymentResponse);
                if ($paymentMethod === 'credit_card' && isset($paymentResponse['cause'][0]['code']) && $paymentResponse['cause'][0]['code'] == 3034) {
                    $userFriendlyApiError = 'Número do cartão inválido. Verifique os dados e tente novamente.';
                }
            }
        }
        if ($redirectUrl) {
            unset($_SESSION['post_data']);
            header("Location: " . $redirectUrl);
            exit;
        } else {
            $apiError = $userFriendlyApiError ?: "Ocorreu um erro desconhecido ao processar o pagamento.";
        }
    } elseif (!empty($validationErrors)) {
        $formError = implode('<br>', $validationErrors);
        log_message("Validation errors before API call for User: $userId. Errors: " . implode('; ', $validationErrors));
    }
}
$adminPaymentHistory = [];
if (!$viewUserHistoryId) {
    $filterUserId = filter_input(INPUT_GET, 'filter_user_id', FILTER_SANITIZE_STRING);
    $adminPaymentHistory = get_payment_history(50, $filterUserId ?: null);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Exemplo Integração - Pagamento Cartão e PIX</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">
    <style>
        body { font-family: sans-serif; line-height: 1.6; margin: 0; background-color: #f4f4f4; }
        main { padding: 20px; }
        .container { max-width: 800px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .container__cart, .container__payment, .container__result { margin-bottom: 30px; }
        h1, h2, h3 { color: #333; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .form-control.h-40 { height: 40px; padding: .375rem .75rem; }
        button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; }
        button:hover { background-color: #0056b3; }
        button:disabled { background-color: #ccc; cursor: not-allowed; }
        .error { color: #a94442; background-color: #f2dede; border: 1px solid #ebccd1; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .success { color: #3c763d; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .info { color: #31708f; background-color: #d9edf7; border: 1px solid #bce8f1; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .warning { color: #8a6d3b; background-color: #fcf8e3; border: 1px solid #faebcc; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .hidden { display: none !important; }
        #loading-message, #pix-loading-message, #card-init-loading { text-align: center; color: #666; }
        #validation-error-messages, #pix-validation-error-messages { color: #a94442; margin-top: 10px; }
        .summary, .payment-details, .products { margin-bottom: 20px; }
        .summary-item, .item, .total { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .price { font-weight: bold; }
        .block-heading { text-align: center; margin-bottom: 20px; }
        footer { text-align: center; margin-top: 30px; padding: 20px; background-color: #e9ecef; }
        .qr-section, .card-result-section, #user-history-section { margin-top: 20px; padding: 20px; border: 1px solid #eee; border-radius: 4px; background-color: #f9f9f9; }
        .qr-code img { display: block; margin: 15px auto; max-width: 250px; height: auto; }
        .pix-code { background-color: #eee; padding: 10px; border-radius: 4px; word-wrap: break-word; font-family: monospace; margin-top: 5px; }
        .timer { font-weight: bold; color: #333; }
        .status-approved { color: green; font-weight: bold; }
        .status-pending, .status-in_process { color: orange; font-weight: bold; }
        .status-rejected, .status-cancelled, .status-error { color: red; font-weight: bold; }
        .status-refunded, .status-charged_back { color: purple; font-weight: bold; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.9em; }
        .history-table th, .history-table td { border: 1px solid #ddd; padding: 8px; text-align: left; word-break: break-word; }
        .history-table th { background-color: #f2f2f2; }
        .history-table tr:nth-child(even) { background-color: #f9f9f9; }
        .go-back-link { color: #007bff; text-decoration: none; display: inline-block; margin-top: 15px; }
        .go-back-link:hover { text-decoration: underline; }
        .go-back-link svg { vertical-align: middle; margin-right: 3px; }
        .loading-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(255, 255, 255, 0.8); z-index: 10; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-radius: 8px; }
        .loading-overlay.hidden { display: none !important; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #007bff; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin-bottom: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        #card-payment-form .container__payment, #pix-payment-form .container__payment { position: relative; min-height: 300px; }
        #card-payment-form.form-initializing #card-init-loading { display: flex !important; }
        #card-payment-form.form-initializing .form-payment { opacity: 0.5; }
        #card-payment-form.form-loading #loading-message, #pix-payment-form.form-loading #pix-loading-message { display: flex !important; }
        #card-payment-form.form-loading .form-payment, #pix-payment-form.form-loading .form-payment { opacity: 0.5; }
    </style>
    <script src="https://sdk.mercadopago.com/js/v2"></script>
</head>
<body>
<main>
    <div class="container">
        <?php if ($formError): ?>
            <div class="error"><?php echo $formError; ?></div>
        <?php endif; ?>
        <?php if ($apiError && !$currentTransaction && !$viewUserHistoryId): ?>
            <div class="error"><?php echo $apiError; ?></div>
        <?php endif; ?>
    </div>
    <section id="initial-step" class="container <?php echo ($showQrSection || $showCardResultSection || $viewUserHistoryId || $formError || ($apiError && !$currentTransaction)) ? 'hidden' : ''; ?>">
        <h1>Exemplo de Pagamento</h1>
        <div class="container__cart">
            <h3>Carrinho</h3>
            <div class="summary">
                <div class="item">
                    <span><?php echo htmlspecialchars($itemTitle); ?></span>
                    <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                </div>
                <hr>
                <div class="total">
                    <span>Total</span>
                    <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                </div>
            </div>
        </div>
        <hr>
        <h3>Informações do Pagador</h3>
        <form id="user-info-form" method="POST" action="index.php">
            <div class="form-group">
                <label for="user_id">ID do Usuário (Seu sistema):</label>
                <input type="text" id="user_id" name="user_id" class="form-control" required value="<?php echo htmlspecialchars($userId); ?>">
            </div>
            <div class="form-group">
                <label for="name">Nome Completo:</label>
                <input type="text" id="name" name="name" class="form-control" required value="<?php echo htmlspecialchars($payerName); ?>">
            </div>
            <div class="form-group">
                <label for="payment_method_select">Escolha o Método de Pagamento:</label>
                <select id="payment_method_select" name="payment_method" class="form-control" required>
                    <option value="" disabled <?php echo ($paymentMethod === 'not_selected') ? 'selected' : ''; ?>>Selecione...</option>
                    <option value="pix" <?php echo ($paymentMethod === 'pix') ? 'selected' : ''; ?>>PIX</option>
                    <option value="credit_card" <?php echo ($paymentMethod === 'credit_card') ? 'selected' : ''; ?>>Cartão de Crédito</option>
                </select>
            </div>
            <button type="submit" id="continue-button" class="btn btn-primary">Continuar para Pagamento</button>
        </form>
        <hr>
        <h3>Ver Meu Histórico</h3>
         <form method="GET" action="index.php#user-history-section" class="form-inline mb-3">
             <div class="form-group mr-2">
                 <label for="view_user_history_id_input" class="mr-2">Digite seu ID de Usuário:</label>
                 <input type="text" name="view_user_history_id" id="view_user_history_id_input" class="form-control form-control-sm" value="<?php echo htmlspecialchars($viewUserHistoryId ?? ''); ?>" placeholder="Seu ID" required>
             </div>
             <button type="submit" class="btn btn-info btn-sm">Ver Histórico</button>
         </form>
    </section>
    <section id="pix-payment-form" class="container <?php echo ($paymentMethod === 'pix' && !$showQrSection && !$showCardResultSection && !$viewUserHistoryId && ($formError || ($apiError && !$currentTransaction))) ? '' : 'hidden'; ?>">
         <div class="container__payment">
             <div id="pix-loading-message" class="loading-overlay hidden">
                 <div class="spinner"></div>
                 <p>Gerando PIX...</p>
             </div>
            <div class="block-heading">
                <h2>Pagamento com PIX</h2>
                <p>Confirme seus dados para gerar o código PIX.</p>
            </div>
            <div class="form-payment">
                <div class="products">
                     <div class="item">
                         <span><?php echo htmlspecialchars($itemTitle); ?></span>
                         <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                     </div>
                     <hr>
                     <div class="total">
                         <span>Total</span>
                         <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                     </div>
                </div>
                <hr>
                <form id="form-pix-checkout" method="POST" action="index.php">
                    <input type="hidden" name="payment_method" value="pix">
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($userId); ?>">
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($payerName); ?>">
                    <div class="form-group">
                        <label for="pix-email">E-mail:</label>
                        <input type="email" id="pix-email" name="email" class="form-control" required value="<?php echo htmlspecialchars($payerEmail); ?>">
                    </div>
                    <div class="form-group">
                        <label for="pix-doc-type">Tipo de Documento:</label>
                        <select id="pix-doc-type" name="doc_type" class="form-control">
                            <option value="CPF" <?php echo ($payerDocType === 'CPF') ? 'selected' : ''; ?>>CPF</option>
                            <option value="CNPJ" <?php echo ($payerDocType === 'CNPJ') ? 'selected' : ''; ?>>CNPJ</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pix-doc-number">Número do Documento:</label>
                        <input type="text" id="pix-doc-number" name="doc_number" class="form-control" required value="<?php echo htmlspecialchars($payerDocNumber); ?>">
                    </div>
                    <div id="pix-validation-error-messages" class="error hidden" style="margin-top: 15px;"></div>
                    <button type="submit" id="pix-submit-button" class="btn btn-success btn-block">Gerar PIX</button>
                    <br>
                    <a href="#" id="pix-go-back" class="go-back-link" style="display: block; text-align: center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 10 10" class="chevron-left"><path fill="#009EE3" fill-rule="nonzero" d="M7.05 1.4L6.2.552 1.756 4.997l4.449 4.448.849-.848-3.6-3.6z"></path></svg>
                        Voltar
                    </a>
                </form>
            </div>
        </div>
    </section>
    <section id="card-payment-form" class="payment-form dark <?php echo ($paymentMethod === 'credit_card' && !$showQrSection && !$showCardResultSection && !$viewUserHistoryId && ($formError || ($apiError && !$currentTransaction))) ? '' : 'hidden'; ?>">
        <div class="container container__payment">
             <div id="card-init-loading" class="loading-overlay hidden">
                 <div class="spinner"></div>
                 <p>Inicializando formulário do cartão...</p>
             </div>
             <div id="loading-message" class="loading-overlay hidden">
                 <div class="spinner"></div>
                 <p>Processando pagamento...</p>
             </div>
            <div class="block-heading">
                <h2>Pagamento com Cartão (Core Methods)</h2>
                <p>Preencha os dados do seu cartão.</p>
            </div>
            <div class="form-payment">
                 <div class="products">
                     <div class="item">
                         <span><?php echo htmlspecialchars($itemTitle); ?></span>
                         <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                     </div>
                     <hr>
                     <div class="total">
                         <span>Total</span>
                         <span class="price">R$ <?php echo number_format($transactionAmount, 2, ',', '.'); ?></span>
                     </div>
                 </div>
                 <hr>
                <div class="payment-details">
                    <form id="form-checkout" method="POST" action="index.php">
                        <input type="hidden" name="payment_method" value="credit_card">
                        <input type="hidden" name="user_id" id="card-user-id" value="<?php echo htmlspecialchars($userId); ?>">
                        <input type="hidden" name="name" id="card-name" value="<?php echo htmlspecialchars($payerName); ?>">
                        <input type="hidden" name="token" id="token">
                        <input type="hidden" name="paymentMethodId" id="paymentMethodId">
                        <input type="hidden" id="transactionAmount" value="<?php echo $transactionAmount; ?>">
                        <input type="hidden" id="mercado-pago-public-key" value="<?php echo $publicKey; ?>">
                        <div class="form-group">
                            <label for="form-checkout__cardholderName">Nome do Titular:</label>
                            <input type="text" id="form-checkout__cardholderName" name="cardholderName" class="form-control h-40" required value="<?php echo htmlspecialchars($cardholderName); ?>">
                        </div>
                        <div class="form-group">
                            <label for="form-checkout__email">E-mail:</label>
                            <input type="email" id="form-checkout__email" name="email" class="form-control h-40" required value="<?php echo htmlspecialchars($payerEmail); ?>">
                        </div>
                        <div class="form-group">
                            <label>Número do Cartão:</label>
                            <div id="form-checkout__cardNumber" class="form-control h-40"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="form-group">
                                    <label>Vencimento:</label>
                                    <div id="form-checkout__expirationDate" class="form-control h-40"></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-group">
                                    <label>CVV:</label>
                                    <div id="form-checkout__securityCode" class="form-control h-40"></div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="form-checkout__issuer">Banco Emissor:</label>
                            <select id="form-checkout__issuer" name="issuer" class="form-control h-40" disabled>
                                <option value="" disabled selected>Banco emissor</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="form-checkout__installments">Parcelas:</label>
                            <select id="form-checkout__installments" name="installments" class="form-control h-40" required disabled>
                                <option value="" disabled selected>Parcelas</option>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label for="form-checkout__identificationType">Tipo Doc.:</label>
                                    <select id="form-checkout__identificationType" name="identificationType" class="form-control h-40" required disabled>
                                        <option value="" disabled selected>Tipo</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-8">
                                <div class="form-group">
                                    <label for="form-checkout__identificationNumber">Núm. Doc.:</label>
                                    <input type="text" id="form-checkout__identificationNumber" name="identificationNumber" class="form-control h-40" required value="<?php echo htmlspecialchars($identificationNumber); ?>">
                                </div>
                            </div>
                        </div>
                        <div id="validation-error-messages" class="error hidden" style="margin-top: 15px;"></div>
                        <div class="form-group">
                            <button type="submit" id="form-checkout__submit" class="btn btn-primary btn-block">Pagar com Cartão</button>
                        </div>
                         <br>
                         <a href="#" id="card-go-back" class="go-back-link" style="display: block; text-align: center;">
                             <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 10 10" class="chevron-left"><path fill="#009EE3" fill-rule="nonzero" d="M7.05 1.4L6.2.552 1.756 4.997l4.449 4.448.849-.848-3.6-3.6z"></path></svg>
                             Voltar
                         </a>
                    </form>
                </div>
            </div>
        </div>
    </section>
    <?php if ($showQrSection && $currentTransaction && $currentTransaction['payment_method_type'] === 'pix'): ?>
    <section id="qr-section" class="container qr-section">
        <h2>Pague com PIX</h2>
        <?php
            $qrCodeBase64 = $currentTransaction['payment_details_initial']['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null;
            $qrCode = $currentTransaction['payment_details_initial']['point_of_interaction']['transaction_data']['qr_code'] ?? null;
            $expiryDate = $currentTransaction['payment_details_initial']['date_of_expiration'] ?? null;
            $ref = $currentTransaction['external_reference'];
        ?>
        <div id="pix-active-elements">
            <?php if ($qrCodeBase64): ?>
                <p>Escaneie o código QR abaixo com o app do seu banco:</p>
                <div class="qr-code">
                    <img src="data:image/png;base64,<?php echo $qrCodeBase64; ?>" alt="PIX QR Code">
                </div>
            <?php else: ?>
                <p class="warning">Não foi possível exibir o QR Code.</p>
            <?php endif; ?>
            <?php if ($qrCode): ?>
                <p>Ou copie o código PIX:</p>
                <div class="pix-code" id="pix-code-text"><?php echo htmlspecialchars($qrCode); ?></div>
                <button onclick="copyToClipboard()" class="btn btn-secondary btn-sm mt-2">Copiar Código</button>
            <?php else: ?>
                <p class="warning">Código PIX Copia e Cola não disponível.</p>
            <?php endif; ?>
            <?php if ($expiryDate): ?>
                <p class="mt-3">Este código expira em: <span id="countdown" class="timer">Calculando...</span></p>
                <input type="hidden" id="expiry-date" value="<?php echo htmlspecialchars($expiryDate); ?>">
            <?php endif; ?>
            <p class="mt-3">Status atual: <strong id="pix-status-display" class="status-<?php echo htmlspecialchars(strtolower($paymentStatus)); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $paymentStatus))); ?></strong> (<span id="pix-status-detail"><?php echo htmlspecialchars($currentTransaction['status_details'] ?? 'N/A'); ?></span>)</p>
            <p><small>Aguardando confirmação do pagamento...</small></p>
        </div>
        <div id="pix-final-message" class="hidden"></div>
        <input type="hidden" id="external-reference" value="<?php echo htmlspecialchars($ref); ?>">
        <a href="index.php" class="go-back-link">Voltar ao Início</a>
    </section>
    <?php endif; ?>
    <?php if ($showCardResultSection && $currentTransaction && $currentTransaction['payment_method_type'] === 'credit_card'): ?>
    <section id="card-result-section" class="container card-result-section">
        <h2>Resultado do Pagamento com Cartão</h2>
        <?php
            $status = $currentTransaction['status'];
            $statusDetail = $currentTransaction['status_details'] ?? 'N/A';
            $paymentId = $currentTransaction['payment_id'];
            $ref = $currentTransaction['external_reference'];
            $message = '';
            $messageClass = '';
            switch ($status) {
                case 'approved': $message = "Pagamento Aprovado!"; $messageClass = 'success'; break;
                case 'in_process': $message = "Pagamento em processamento. Você será notificado sobre o resultado."; $messageClass = 'info'; break;
                case 'rejected': $message = "Pagamento Rejeitado."; $messageClass = 'error'; break;
                case 'pending': $message = "Pagamento Pendente. Aguardando ação adicional ou confirmação."; $messageClass = 'warning'; break;
                case 'cancelled': $message = "Pagamento Cancelado."; $messageClass = 'error'; break;
                default: $message = "Status do pagamento: " . htmlspecialchars(ucfirst(str_replace('_', ' ', $status))); $messageClass = 'info';
            }
        ?>
        <div class="<?php echo $messageClass; ?>">
            <strong><?php echo $message; ?></strong>
        </div>
        <p><strong>ID da Transação (Referência Externa):</strong> <?php echo htmlspecialchars($ref); ?></p>
        <p><strong>ID do Pagamento (Mercado Pago):</strong> <?php echo htmlspecialchars($paymentId); ?></p>
        <p><strong>Status Detalhado:</strong> <?php echo htmlspecialchars($statusDetail); ?></p>
        <p><strong>Valor:</strong> R$ <?php echo number_format($currentTransaction['transaction_amount'], 2, ',', '.'); ?></p>
        <p><strong>Data:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($currentTransaction['date_last_updated']))); ?></p>
        <a href="index.php" class="go-back-link">Voltar ao Início</a>
    </section>
    <?php endif; ?>
    <?php if ($viewUserHistoryId): ?>
    <section id="user-history-section" class="container">
        <h2>Meu Histórico de Pagamentos (Usuário: <?php echo htmlspecialchars($viewUserHistoryId); ?>)</h2>
        <?php if (!empty($userPaymentHistory)): ?>
            <div style="overflow-x:auto;">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Ref. Externa</th>
                            <th>ID Pagamento</th>
                            <th>Email Pagador</th>
                            <th>Método</th>
                            <th>Status</th>
                            <th>Detalhe Status</th>
                            <th>Valor (R$)</th>
                            <th>Cartão (Final)</th>
                            <th>Parcelas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($userPaymentHistory as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['timestamp'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['external_reference'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['payment_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['payer_email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper($entry['payment_method_type'] ?? 'N/A')); ?> (<?php echo htmlspecialchars($entry['payment_method_id'] ?? 'N/A'); ?>)</td>
                                <td><span class="status-<?php echo htmlspecialchars(strtolower($entry['status'] ?? 'unknown')); ?>"><?php echo htmlspecialchars($entry['status'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($entry['status_details'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($entry['transaction_amount'] ?? 0, 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($entry['card_last_four'] !== 'N/A' ? '**** ' . $entry['card_last_four'] : 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['installments'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Nenhum histórico de pagamento encontrado para este usuário.</p>
        <?php endif; ?>
         <a href="index.php" class="go-back-link">
             <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 10 10" class="chevron-left"><path fill="#009EE3" fill-rule="nonzero" d="M7.05 1.4L6.2.552 1.756 4.997l4.449 4.448.849-.848-3.6-3.6z"></path></svg>
             Voltar ao Início
         </a>
    </section>
    <?php endif; ?>
    <div id="admin-section" class="admin-section container <?php echo $viewUserHistoryId ? 'hidden' : ''; ?>">
        <hr>
        <h2>Visão Geral do Administrador</h2>
        <form method="GET" action="index.php#admin-section" class="form-inline mb-3">
             <div class="form-group mr-2">
                 <label for="filter_user_id" class="mr-2">Filtrar por ID do Usuário:</label>
                 <input type="text" name="filter_user_id" id="filter_user_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filterUserId ?? ''); ?>" placeholder="Digite o ID do usuário">
             </div>
             <button type="submit" class="btn btn-secondary btn-sm mr-1">Filtrar</button>
             <?php if ($filterUserId): ?>
                 <a href="index.php#admin-section" class="btn btn-outline-secondary btn-sm">Limpar Filtro</a>
             <?php endif; ?>
        </form>
        <?php if ($filterUserId): ?>
            <h4>Histórico para Usuário: <?php echo htmlspecialchars($filterUserId); ?> (Últimas 50)</h4>
        <?php else: ?>
            <h4>Últimas 50 Transações Globais</h4>
        <?php endif; ?>
        <?php if (!empty($adminPaymentHistory)): ?>
            <div style="overflow-x:auto;">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Ref. Externa</th>
                            <th>User ID</th>
                            <th>Pagador</th>
                            <th>Email Pagador</th>
                            <th>Método</th>
                            <th>Status</th>
                            <th>Detalhe Status</th>
                            <th>Valor (R$)</th>
                            <th>ID Pagamento</th>
                            <th>Documento</th>
                            <th>Cartão (Final)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adminPaymentHistory as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['timestamp'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['external_reference'] ?? 'N/A'); ?></td>
                                <td><a href="index.php?view_user_history_id=<?php echo urlencode($entry['user_id'] ?? ''); ?>#user-history-section"><?php echo htmlspecialchars($entry['user_id'] ?? 'N/A'); ?></a></td>
                                <td><?php echo htmlspecialchars($entry['payer_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['payer_email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars(strtoupper($entry['payment_method_type'] ?? 'N/A')); ?> (<?php echo htmlspecialchars($entry['payment_method_id'] ?? 'N/A'); ?>)</td>
                                <td><span class="status-<?php echo htmlspecialchars(strtolower($entry['status'] ?? 'unknown')); ?>"><?php echo htmlspecialchars($entry['status'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($entry['status_details'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($entry['transaction_amount'] ?? 0, 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($entry['payment_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['payer_document'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($entry['card_last_four'] !== 'N/A' ? '**** ' . $entry['card_last_four'] : 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Nenhum histórico de pagamento encontrado<?php echo $filterUserId ? ' para este usuário' : ''; ?>.</p>
        <?php endif; ?>
    </div>
</main>
<footer>
    <div class="footer_text">
        <p>Exemplo de Integração Mercado Pago SDK V2 - PHP</p>
    </div>
</footer>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
<script>
    const countdownElement = document.getElementById('countdown');
    const qrSection = document.getElementById('qr-section');
    const pixActiveElements = document.getElementById('pix-active-elements');
    const pixFinalMessage = document.getElementById('pix-final-message');
    const pixStatusDisplay = document.getElementById('pix-status-display');
    const pixStatusDetail = document.getElementById('pix-status-detail');
    let statusCheckInterval;
    let countdownInterval;
    function updateCountdown(expiryDateStr) {
        if (!expiryDateStr || !countdownElement) return;
        const expiryDate = new Date(expiryDateStr).getTime();
        if (countdownInterval) clearInterval(countdownInterval);
        function runTimer() {
            const now = new Date().getTime();
            const distance = expiryDate - now;
            if (distance < 0) {
                clearInterval(countdownInterval);
                countdownInterval = null;
                countdownElement.innerHTML = "Expirado";
                countdownElement.style.color = "red";
                const currentStatusText = pixStatusDisplay ? pixStatusDisplay.textContent.toLowerCase() : '';
                const isPending = currentStatusText.includes('pending') || currentStatusText.includes('in process');
                if (isPending) {
                    if(pixActiveElements) pixActiveElements.classList.add('hidden');
                    if(pixFinalMessage) {
                        pixFinalMessage.innerHTML = '<p class="error"><strong>PIX Expirado.</strong> Gere um novo código para pagar.</p>';
                        pixFinalMessage.classList.remove('hidden');
                    }
                    stopStatusCheck();
                    console.log("PIX expired while pending. Countdown and status check stopped.");
                } else {
                    console.log("PIX expired, but status is already final. Countdown stopped.");
                }
                return;
            }
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            countdownElement.innerHTML = minutes + "m " + seconds + "s ";
            countdownElement.style.color = (minutes < 5 && distance > 0) ? "#dc3545" : "#333";
        }
        runTimer();
        countdownInterval = setInterval(runTimer, 1000);
        console.log("Countdown started. Interval ID:", countdownInterval);
    }
    function copyToClipboard() {
        const pixCodeElement = document.getElementById('pix-code-text');
        if (!pixCodeElement) return;
        const pixCode = pixCodeElement.innerText;
        navigator.clipboard.writeText(pixCode).then(() => {
            alert('Código PIX copiado!');
        }, (err) => {
            alert('Falha ao copiar o código: ', err);
            try {
                const textArea = document.createElement("textarea");
                textArea.value = pixCode; document.body.appendChild(textArea);
                textArea.focus(); textArea.select(); document.execCommand('copy');
                document.body.removeChild(textArea); alert('Código PIX copiado! (fallback)');
            } catch (e) { console.error('Fallback copy failed:', e); }
        });
    }
    function checkPaymentStatus(ref) {
        console.log("Checking status for ref:", ref);
        fetch(`index.php?action=check_status&ref=${encodeURIComponent(ref)}`)
            .then(response => response.json())
            .then(data => {
                console.log("Status check response:", data);
                if (data && data.status && pixStatusDisplay && pixStatusDetail) {
                    const currentStatus = data.status;
                    const currentDetail = data.details || 'N/A';
                    pixStatusDisplay.textContent = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1).replace('_', ' ');
                    pixStatusDisplay.className = `status-${currentStatus.toLowerCase()}`;
                    pixStatusDetail.textContent = currentDetail;
                    const finalStatuses = ['approved', 'cancelled', 'rejected', 'refunded', 'charged_back', 'error'];
                    if (finalStatuses.includes(currentStatus)) {
                        stopStatusCheck();
                        if(pixActiveElements) pixActiveElements.classList.add('hidden');
                        if(pixFinalMessage) {
                            let message = ''; let msgClass = '';
                            switch(currentStatus) {
                                case 'approved': message = 'Pagamento Aprovado!'; msgClass = 'success'; break;
                                case 'cancelled': message = 'Pagamento Cancelado.'; msgClass = 'error'; break;
                                case 'rejected': message = 'Pagamento Rejeitado.'; msgClass = 'error'; break;
                                default: message = `Status Final: ${pixStatusDisplay.textContent}`; msgClass = 'info';
                            }
                            pixFinalMessage.innerHTML = `<p class="${msgClass}"><strong>${message}</strong></p>`;
                            pixFinalMessage.classList.remove('hidden');
                        }
                        if (countdownInterval) clearInterval(countdownInterval);
                    }
                } else if (data && data.status === 'not_found') {
                     console.error("Transaction not found during status check.");
                     stopStatusCheck();
                }
            })
            .catch(error => { console.error('Error checking payment status:', error); });
    }
    function startStatusCheck(ref) {
        stopStatusCheck();
        checkPaymentStatus(ref);
        statusCheckInterval = setInterval(() => checkPaymentStatus(ref), 10000);
        console.log("Status check started. Interval ID:", statusCheckInterval);
    }
    function stopStatusCheck() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
            statusCheckInterval = null;
            console.log("Status check stopped.");
        }
    }
    document.addEventListener('DOMContentLoaded', () => {
        const expiryDateInput = document.getElementById('expiry-date');
        const externalRefInput = document.getElementById('external-reference');
        const cardForm = document.getElementById('card-payment-form');
        if (qrSection && expiryDateInput && externalRefInput) {
            const expiryDate = expiryDateInput.value;
            const externalRef = externalRefInput.value;
            updateCountdown(expiryDate);
            if (pixStatusDisplay && (pixStatusDisplay.textContent.toLowerCase().includes('pending') || pixStatusDisplay.textContent.toLowerCase().includes('in process'))) {
                 startStatusCheck(externalRef);
            }
        }
        const pixGoBack = document.getElementById('pix-go-back');
        const cardGoBack = document.getElementById('card-go-back');
        const initialStep = document.getElementById('initial-step');
        const pixForm = document.getElementById('pix-payment-form');
        function goBack(event) {
             event.preventDefault();
             if(pixForm) pixForm.classList.add('hidden');
             if(cardForm) cardForm.classList.add('hidden');
             if(initialStep) initialStep.classList.remove('hidden');
             clearError();
             const paymentMethodSelect = document.getElementById('payment_method_select');
             if (paymentMethodSelect) paymentMethodSelect.value = '';
        }
        if(pixGoBack) pixGoBack.addEventListener('click', goBack);
        if(cardGoBack) cardGoBack.addEventListener('click', goBack);
        const userInfoForm = document.getElementById('user-info-form');
        const paymentMethodSelect = document.getElementById('payment_method_select');
        if (userInfoForm && paymentMethodSelect) {
            userInfoForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const selectedMethod = paymentMethodSelect.value;
                const userIdInput = document.getElementById('user_id');
                const nameInput = document.getElementById('name');
                if (!userIdInput.value || !nameInput.value || !selectedMethod) {
                    alert('Por favor, preencha o ID do usuário, nome e selecione um método de pagamento.');
                    return;
                }
                if(initialStep) initialStep.classList.add('hidden');
                if (selectedMethod === 'pix') {
                    if(pixForm) {
                        pixForm.querySelector('input[name="user_id"]').value = userIdInput.value;
                        pixForm.querySelector('input[name="name"]').value = nameInput.value;
                        pixForm.classList.remove('hidden');
                    }
                } else if (selectedMethod === 'credit_card') {
                    if(cardForm) {
                        cardForm.querySelector('input[name="user_id"]').value = userIdInput.value;
                        cardForm.querySelector('input[name="name"]').value = nameInput.value;
                        cardForm.classList.remove('hidden');
                        initializeCardFormCoreMethods();
                    }
                }
            });
        }
        if (cardForm && !cardForm.classList.contains('hidden')) {
             initializeCardFormCoreMethods();
        }
        const pixCheckoutForm = document.getElementById('form-pix-checkout');
        if (pixCheckoutForm) {
            pixCheckoutForm.addEventListener('submit', function() {
                const email = document.getElementById('pix-email');
                const docNum = document.getElementById('pix-doc-number');
                if (email && email.checkValidity() && docNum && docNum.value) {
                    setLoading(true, 'pix');
                } else {
                    console.log("PIX form client validation failed before submit.");
                }
            });
        }
    });
</script>
<script>
    (function () {
        const publicKeyElement = document.getElementById('mercado-pago-public-key');
        if (!publicKeyElement || !publicKeyElement.value) {
            console.error("Chave pública do Mercado Pago não encontrada no HTML.");
            return;
        }
        const publicKey = publicKeyElement.value;
        const mp = new MercadoPago(publicKey);
        console.log("Instância MercadoPago (mp) criada.");
        let cardNumberElement, expirationDateElement, securityCodeElement;
        function logError(message, errorObject = null) {
            console.error("ERRO:", message, errorObject || '');
            const errorDiv = document.getElementById('validation-error-messages');
            if (errorDiv) {
                errorDiv.textContent = message + (errorObject ? ` Detalhes no console.` : '');
                errorDiv.classList.remove('hidden');
            } else {
                console.warn("Div de erro #validation-error-messages não encontrado, usando alert.");
                alert("Erro no formulário do cartão: " + message);
            }
        }
        function clearError() {
            const errorDivCard = document.getElementById('validation-error-messages');
            if (errorDivCard) { errorDivCard.textContent = ''; errorDivCard.classList.add('hidden'); }
             const errorDivPix = document.getElementById('pix-validation-error-messages');
             if (errorDivPix) { errorDivPix.textContent = ''; errorDivPix.classList.add('hidden'); }
        }
        function setLoading(isLoading, section = 'card') {
            let loadingMsg, submitBtn, formSection;
            if (section === 'card') {
                loadingMsg = document.getElementById('loading-message');
                submitBtn = document.getElementById('form-checkout__submit');
                formSection = document.getElementById('card-payment-form');
            } else if (section === 'pix') {
                loadingMsg = document.getElementById('pix-loading-message');
                submitBtn = document.getElementById('pix-submit-button');
                formSection = document.getElementById('pix-payment-form');
            } else if (section === 'card-init') {
                loadingMsg = document.getElementById('card-init-loading');
                formSection = document.getElementById('card-payment-form');
            }
            if (loadingMsg) loadingMsg.classList.toggle('hidden', !isLoading);
            if (submitBtn && section !== 'card-init') submitBtn.disabled = isLoading;
            if (formSection) {
                 formSection.classList.toggle('form-loading', isLoading && (section === 'card' || section === 'pix'));
                 formSection.classList.toggle('form-initializing', isLoading && section === 'card-init');
            }
        }
        function clearSelectsAndSetPlaceholders() {
            const issuerSelect = document.getElementById('form-checkout__issuer');
            const installmentsSelect = document.getElementById('form-checkout__installments');
            if (issuerSelect) { issuerSelect.innerHTML = '<option value="" disabled selected>Banco emissor</option>'; issuerSelect.disabled = true; }
            if (installmentsSelect) { installmentsSelect.innerHTML = '<option value="" disabled selected>Parcelas</option>'; installmentsSelect.disabled = true; }
             console.log("Selects de Issuer e Installments limpos e desabilitados.");
        }
        async function loadAndPopulateIdentificationTypes() {
            try {
                console.log("Buscando tipos de documento...");
                const identificationTypes = await mp.getIdentificationTypes();
                console.log("Tipos de documento recebidos:", identificationTypes);
                const identificationTypeSelect = document.getElementById('form-checkout__identificationType');
                if (identificationTypeSelect && identificationTypes) {
                    identificationTypeSelect.innerHTML = '<option value="" disabled selected>Tipo</option>';
                    identificationTypes.forEach(type => {
                        const option = document.createElement('option');
                        option.value = type.id; option.textContent = type.name;
                        identificationTypeSelect.appendChild(option);
                    });
                    identificationTypeSelect.disabled = false;
                    console.log("Select de Identification Types populado e habilitado.");
                } else { console.error("Elemento select #form-checkout__identificationType não encontrado ou API não retornou tipos."); }
            } catch (e) { logError('Erro ao buscar tipos de documento.', e); }
        }
        async function updateIssuers(paymentMethod, bin) {
            const issuerSelect = document.getElementById('form-checkout__issuer');
            if (!issuerSelect) return console.error("updateIssuers: Select #form-checkout__issuer não encontrado.");
            try {
                console.log(`Buscando emissores para PM: ${paymentMethod.id}, BIN: ${bin}`);
                issuerSelect.innerHTML = '<option value="" disabled selected>Banco emissor</option>';
                if (paymentMethod.issuer && paymentMethod.issuer.id) {
                     console.log("Issuer info encontrada diretamente no Payment Method:", paymentMethod.issuer);
                     const option = document.createElement('option');
                     option.value = paymentMethod.issuer.id;
                     option.textContent = paymentMethod.issuer.name || `Issuer ID: ${paymentMethod.issuer.id}`;
                     option.selected = true; issuerSelect.appendChild(option); issuerSelect.disabled = false;
                } else {
                    console.log("Issuer não encontrado no PM, tentando via getInstallments...");
                    const amount = document.getElementById('transactionAmount').value;
                    const installmentsData = await mp.getInstallments({ amount, bin, paymentTypeId: 'credit_card' });
                    if (installmentsData && installmentsData.length > 0 && installmentsData[0].payer_costs) {
                        console.log("getInstallments retornou dados, mas nome do issuer não disponível aqui. Campo permanecerá desabilitado.");
                        issuerSelect.disabled = true;
                    } else { console.warn("Nenhuma informação de issuer/parcelas encontrada via getInstallments."); issuerSelect.disabled = true; }
                }
            } catch (e) {
                logError('Erro ao buscar/atualizar emissores.', e);
                issuerSelect.innerHTML = '<option value="" disabled selected>Erro ao buscar</option>';
                issuerSelect.disabled = true;
            }
        }
        async function updateInstallments(bin, paymentMethodId) {
            const installmentsSelect = document.getElementById('form-checkout__installments');
            const amountInput = document.getElementById('transactionAmount');
            if (!installmentsSelect || !amountInput) return console.error("updateInstallments: Elementos não encontrados.");
            try {
                const amount = amountInput.value;
                console.log(`Buscando parcelas para BIN: ${bin}, PM Id: ${paymentMethodId}, Amount: ${amount}`);
                const installmentsData = await mp.getInstallments({ amount: amount, bin: bin, paymentTypeId: 'credit_card' });
                console.log("Dados de parcelas recebidos:", installmentsData);
                installmentsSelect.innerHTML = '<option value="" disabled selected>Parcelas</option>';
                if (installmentsData && installmentsData.length > 0 && installmentsData[0].payer_costs) {
                    installmentsData[0].payer_costs.forEach(payerCost => {
                        const option = document.createElement('option');
                        option.value = payerCost.installments; option.textContent = payerCost.recommended_message;
                        installmentsSelect.appendChild(option);
                    });
                    installmentsSelect.disabled = false;
                    console.log("Select de Installments populado e habilitado.");
                } else {
                    console.warn("Nenhuma opção de parcelamento encontrada.");
                    installmentsSelect.innerHTML = '<option value="" disabled selected>Não disponível</option>';
                    installmentsSelect.disabled = true;
                }
            } catch (e) {
                logError('Erro ao buscar parcelas.', e);
                installmentsSelect.innerHTML = '<option value="" disabled selected>Erro ao buscar</option>';
                installmentsSelect.disabled = true;
            }
        }
        async function createCardToken(event) {
            if (!mp) return console.error("createCardToken: Instância MP não disponível.") || event.preventDefault();
            event.preventDefault(); console.log("Submit interceptado. Criando token...");
            clearError(); setLoading(true, 'card');
            const cardholderNameInput = document.getElementById('form-checkout__cardholderName');
            const emailInput = document.getElementById('form-checkout__email');
            const identificationTypeElement = document.getElementById('form-checkout__identificationType');
            const identificationNumberInput = document.getElementById('form-checkout__identificationNumber');
            const tokenElement = document.getElementById('token');
            const cardForm = document.getElementById('form-checkout');
            try {
                if (!cardholderNameInput || !cardholderNameInput.value) throw new Error("Nome do titular é obrigatório.");
                if (!emailInput || !emailInput.value || !emailInput.checkValidity()) throw new Error("E-mail inválido.");
                if (!identificationTypeElement || !identificationTypeElement.value) throw new Error("Tipo de documento é obrigatório.");
                if (!identificationNumberInput || !identificationNumberInput.value) throw new Error("Número do documento é obrigatório.");
                if (!tokenElement) throw new Error("Elemento token não encontrado.");
                if (!cardForm) throw new Error("Elemento form não encontrado.");
                if (!cardNumberElement || !expirationDateElement || !securityCodeElement) throw new Error("Campos de cartão não inicializados.");
                console.log("Chamando mp.fields.createCardToken...");
                const token = await mp.fields.createCardToken({
                    cardholderName: cardholderNameInput.value,
                    identificationType: identificationTypeElement.value,
                    identificationNumber: identificationNumberInput.value,
                });
                console.log("Token recebido:", token);
                if (token && token.id) {
                    console.log("Token ID:", token.id);
                    tokenElement.value = token.id;
                    cardForm.submit();
                } else {
                    let errorMsg = "A API não retornou um ID de token válido.";
                    if (token && token.error) { errorMsg = token.error.message || JSON.stringify(token.error); console.error("Erro detalhado da API de token:", token.error); }
                    throw new Error(errorMsg);
                }
            } catch (e) { logError('Erro ao criar token do cartão.', e); setLoading(false, 'card'); }
        }
        window.initializeCardFormCoreMethods = async function() {
            if (!mp) return console.error("initializeCardFormCoreMethods: Instância MP não disponível.");
            console.log(">>> initializeCardFormCoreMethods INICIADA <<<");
            clearError(); setLoading(true, 'card-init');
            const cardNumberDiv = document.getElementById('form-checkout__cardNumber');
            const expirationDateDiv = document.getElementById('form-checkout__expirationDate');
            const securityCodeDiv = document.getElementById('form-checkout__securityCode');
            const cardForm = document.getElementById('form-checkout');
            if (!cardNumberDiv || !expirationDateDiv || !securityCodeDiv) { logError("DIVs para montar campos PCI não encontradas."); setLoading(false, 'card-init'); return console.log("<<< initializeCardFormCoreMethods TERMINADA (ERRO DIVs) <<<"); }
             if (!cardForm) { logError("Formulário #form-checkout não encontrado na inicialização."); setLoading(false, 'card-init'); return console.log("<<< initializeCardFormCoreMethods TERMINADA (ERRO FORM) <<<"); }
            console.log("Elementos do formulário OK para inicialização.");
            if (cardNumberElement) try { cardNumberElement.unmount(); console.log("cardNumberElement desmontado."); } catch(e){ console.warn("Erro ao desmontar cardNumberElement:", e); }
            if (expirationDateElement) try { expirationDateElement.unmount(); console.log("expirationDateElement desmontado."); } catch(e){ console.warn("Erro ao desmontar expirationDateElement:", e); }
            if (securityCodeElement) try { securityCodeElement.unmount(); console.log("securityCodeElement desmontado."); } catch(e){ console.warn("Erro ao desmontar securityCodeElement:", e); }
            cardNumberElement = expirationDateElement = securityCodeElement = null;
            console.log("Variáveis dos campos PCI resetadas.");
            try {
                console.log("--- ANTES de mp.fields.create('cardNumber').mount() ---");
                cardNumberElement = mp.fields.create('cardNumber', { placeholder: "Número do cartão" }).mount('form-checkout__cardNumber');
                console.log("--- DEPOIS de mp.fields.create('cardNumber').mount() ---");
                console.log("--- ANTES de mp.fields.create('expirationDate').mount() ---");
                expirationDateElement = mp.fields.create('expirationDate', { placeholder: "MM/AA", mode: 'short' }).mount('form-checkout__expirationDate');
                console.log("--- DEPOIS de mp.fields.create('expirationDate').mount() ---");
                console.log("--- ANTES de mp.fields.create('securityCode').mount() ---");
                securityCodeElement = mp.fields.create('securityCode', { placeholder: "Cód. segurança" }).mount('form-checkout__securityCode');
                console.log("--- DEPOIS de mp.fields.create('securityCode').mount() ---");
                console.log("Campos PCI montados com sucesso via JS.");
                console.log("Chamando loadAndPopulateIdentificationTypes() para formulário de cartão...");
                await loadAndPopulateIdentificationTypes();
                if (cardNumberElement) {
                    cardNumberElement.on('binChange', async (data) => {
                        const { bin } = data;
                        console.log("Evento 'binChange' detectado. BIN:", bin);
                        clearError(); clearSelectsAndSetPlaceholders();
                        if (!bin || bin.length < 6) { console.log("BIN inválido ou incompleto, limpando selects."); return; }
                        try {
                            console.log("Buscando payment method para o BIN:", bin);
                            const paymentMethods = await mp.getPaymentMethods({ bin });
                            console.log("Payment methods recebidos:", paymentMethods);
                            if (paymentMethods && paymentMethods.results && paymentMethods.results.length > 0) {
                                const paymentMethod = paymentMethods.results[0];
                                console.log("Payment Method encontrado:", paymentMethod);
                                document.getElementById('paymentMethodId').value = paymentMethod.id;
                                await updateIssuers(paymentMethod, bin);
                                await updateInstallments(bin, paymentMethod.id);
                            } else {
                                console.warn("Nenhum payment method encontrado para o BIN:", bin);
                                clearSelectsAndSetPlaceholders();
                            }
                        } catch (e) { logError('Erro ao processar BIN.', e); clearSelectsAndSetPlaceholders(); }
                    });
                    console.log("Listener 'binChange' adicionado.");
                } else { logError("Falha ao adicionar listener 'binChange': cardNumberElement NULO após montagem."); }
                cardForm.removeEventListener('submit', createCardToken);
                cardForm.addEventListener('submit', createCardToken);
                console.log("Listener 'submit' adicionado ao formulário de cartão.");
                setLoading(false, 'card-init');
                console.log("<<< initializeCardFormCoreMethods TERMINADA (SUCESSO) <<<");
            } catch (e) {
                logError("Erro CRÍTICO ao montar campos PCI ou adicionar listeners.", e);
                setLoading(false, 'card-init');
                console.log("<<< initializeCardFormCoreMethods TERMINADA (ERRO MOUNT/LISTENERS) <<<");
                return;
            }
        }
    })();
</script>
</body>
</html>
