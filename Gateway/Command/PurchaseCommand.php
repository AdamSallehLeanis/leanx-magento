<?php

namespace LeanX\Payments\Gateway\Command;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use LeanX\Payments\Model\Api\GatewayClient;

class PurchaseCommand implements CommandInterface
{
    protected $orderRepository;
    protected $gatewayClient;
    protected $scopeConfig;
    protected $storeManager;

    const CONFIG_BILL_INVOICE_ID = 'payment/leanx/bill_invoice_id';
    const CONFIG_COLLECTION_UUID = 'payment/leanx/collection_uuid';

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        GatewayClient $gatewayClient,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {

        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('PurchaseCommand constructed.');

        $this->orderRepository = $orderRepository;
        $this->gatewayClient = $gatewayClient;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function execute(array $commandSubject)
    {

        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('PurchaseCommand executed.');

        // Validate the payment data object
        if (!isset($commandSubject['payment']) || !$commandSubject['payment'] instanceof \Magento\Payment\Gateway\Data\PaymentDataObjectInterface) {
            $logger->err('Payment data object should be provided.');
            throw new \Magento\Framework\Exception\LocalizedException(__('Payment data object should be provided.'));
        }

        $paymentDO = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($commandSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();

        $logger->info('Payment and order retrieved successfully.');

        // Get base URL of the store
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();

        // Collect required data
        $entityId = $order->getId();
        $orderId = $order->getOrderIncrementId();

        $logger->info("Processing order for entity_id: ' . $entityId . ' or increment_id: " . $orderId);

        $collectionUuid = $this->scopeConfig->getValue(
            self::CONFIG_COLLECTION_UUID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $billInvoiceId = $this->scopeConfig->getValue(
            self::CONFIG_BILL_INVOICE_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ) . '-' . $orderId;
        $redirectUrl = $baseUrl . 'leanx/payment/success' . '?order_id=' . $orderId . '&invoice_no=' . $billInvoiceId;
        $callbackUrl = $baseUrl . '/leanx/payment/callback';
        $amount = $order->getGrandTotalAmount();
        $billingAddress = $order->getBillingAddress();
        $customerName = $billingAddress->getFirstname() . ' ' . $billingAddress->getLastname();
        $email = $billingAddress->getEmail();
        $phoneNumber = $billingAddress->getTelephone();
        
        // Construct the payload for the API
        $payload = [
            'collection_uuid' => $collectionUuid,
            'amount' => $amount,
            'redirect_url' => $redirectUrl,
            'callback_url' => $callbackUrl,
            'full_name' => $customerName,
            'email' => $email,
            'phone_number' => $phoneNumber,
            'client_data' => $orderId,
        ];

        $logger->info('Sending payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

        // Make API call to payment gateway
        $response = $this->gatewayClient->authorizePayment($payload);
        
        $logger->info('API Response: ' . json_encode($response, JSON_PRETTY_PRINT));

        if (!isset($response['response_code'])) {
            $logger->err('API response does not contain response_code. Full response: ' . json_encode($response, JSON_PRETTY_PRINT));
            throw new \Magento\Framework\Exception\LocalizedException(__('Failed to initiate payment: Invalid API response.'));
        }
        
        if ($response['response_code'] === 2000) {
            if (!isset($response['data']['redirect_url'])) {
                $logger->err('API response does not contain redirect_url. Full response: ' . json_encode($response, JSON_PRETTY_PRINT));
                throw new \Magento\Framework\Exception\LocalizedException(__('Failed to initiate payment: Missing redirect URL.'));
            }
            
            // Success - Redirect User
            $logger->info('Payment initiated successfully. Redirecting to: ' . $response['data']['redirect_url']);
            $redirect_Url = $response['data']['redirect_url'];

            $payment->setAdditionalInformation('leanx_redirect_url', $redirect_Url);

            return ['redirect_url' => $redirect_Url];
        } else {
            // API returned an error response code
            $errorMessage = $response['message'] ?? 'Unknown error';
            $logger->err('Payment failed. Response: ' . json_encode($response, JSON_PRETTY_PRINT));
            throw new \Magento\Framework\Exception\LocalizedException(__('Failed to initiate payment: %1', $errorMessage));
        }
    }
}