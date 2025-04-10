<?php

namespace LeanX\Payments\Controller\Payment;

use GuzzleHttp\Client;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Checkout\Model\Session;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Invoice;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Model\Order;
use Magento\Framework\Exception\LocalizedException;
use LeanX\Payments\Model\InvoiceGenerator;

class Success extends Action
{
    protected $client;
    protected $scopeConfig;
    protected $redirectFactory;
    protected $orderFactory;
    protected $orderResource;
    protected $checkoutSession;
    protected $logger;
    protected $transactionBuilder;
    protected $transactionRepository;
    protected $serializer;
    protected $orderRepository;
    protected $invoiceService;
    protected $transaction;
    protected $invoiceGenerator;

    const CONFIG_AUTH_TOKEN = 'payment/leanx/auth_token';
    const CONFIG_BILL_INVOICE_ID = 'payment/leanx/bill_invoice_id';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Context $context,
        RedirectFactory $redirectFactory,
        OrderFactory $orderFactory,
        OrderResource $orderResource,
        Session $checkoutSession,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
        Json $serializer,
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        Transaction $transaction,
        InvoiceGenerator $invoiceGenerator
    ) {
        $this->client = new Client();
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
        $this->redirectFactory = $redirectFactory;
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;
        $this->checkoutSession = $checkoutSession;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->serializer = $serializer;
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceGenerator = $invoiceGenerator;
    }

    public function execute()
    {
        // ✅ Custom Logger: Write to var/log/leanx_payment_method.log
        $this->logger = new Logger('leanx_payment');
        $logFilePath = BP . '/var/log/leanx_payment_method.log';
        $this->logger->pushHandler(new StreamHandler($logFilePath, Logger::DEBUG));

        $request = $this->getRequest();
        $orderId = $request->getParam('order_id');
        $invoiceNo = $request->getParam('invoice_no');
        $this->logger->info('Payment Gateway Status Page Loaded.', ['params' => $request->getParams()]);

        if (!$orderId) {
            $this->logger->error('Payment redirect failed: Missing order ID.');
            $resultRedirect = $this->redirectFactory->create();
            return $resultRedirect->setPath('checkout/cart'); // Redirect back to cart
        }

        if ($orderId && !empty($invoiceNo)) {

            // $leanx_settings = get_option('woocommerce_leanx_settings');
            // $is_sandbox = $leanx_settings['is_sandbox'];
            // // Get the sandbox setting from the admin settings
            // $sandbox_enabled = get_option('woocommerce_leanx_settings')['is_sandbox'] === 'yes';
            
            // $auth_token = $leanx_settings['auth_token'];
            $auth_token = $this->scopeConfig->getValue(
                self::CONFIG_AUTH_TOKEN,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $this->logger->info('Auth Token: ' . ($auth_token ?: 'MISSING'));
            $order = $this->checkoutSession->getLastRealOrder();
            $actual_order_id = $order->getId();
            $this->logger->info('Order_ID: ' . $actual_order_id . ' and orderId: ' . $orderId);

            if (!$order->getId()) {
                $this->logger->error('Payment redirect error: Order not found.', ['order_id' => $orderId]);
                $resultRedirect = $this->redirectFactory->create();
                return $resultRedirect->setPath('checkout/cart');
            }
            // $url = $sandbox_enabled ? 'https://api.leanx.dev': 'https://api.leanx.io';
        
            // Check order id with API
            $apiUrl = 'https://api.leanx.dev/api/v1/public-merchant/public/manual-checking-transaction?invoice_no=' . $invoiceNo; 
            // $api_url = $url . '/api/v1/public-merchant/public/manual-checking-transaction?invoice_no=' . $invoiceNo; 
        
            $max_attempts = 3;
            $attempt = 0;
            $successful = false;

            while ($attempt < $max_attempts && !$successful) {
                $response = $this->client->post($apiUrl, [
                    'method'      => 'POST',
                    'timeout'     => 20,
                    'httpversion' => '1.0',
                    'blocking'    => true,
                    'headers'     => array(
                        'Content-Type' => 'application/json; charset=utf-8',
                        'auth-token' => $auth_token
                    ),
                ]);
                // Get the response body
                $responseBody = $response->getBody()->getContents();
                $response = json_decode($responseBody, true);
        
                $this->logger->info('API Response: ' . json_encode($response, JSON_PRETTY_PRINT));

                if (!isset($response['response_code'])) {
                    $this->logger->error('API response does not contain response_code. Full response: ' . json_encode($response, JSON_PRETTY_PRINT));
                    throw new \Magento\Framework\Exception\LocalizedException(__('Failed to initiate payment: Invalid API response.'));
                }
                
                if ($response['response_code'] === 2000) {
                    $invoiceStatus = $response['data']['transaction_details']['invoice_status'];
            
                    if ($invoiceStatus == 'SUCCESS') {
                        // Generate invoice
                        $invoiceId = $this->invoiceGenerator->generateInvoice($order, $invoiceStatus);

                        if ($invoiceId) {
                            $this->logger->info("✅ Invoice #$invoiceId created successfully for order: " . $order->getIncrementId());
                        } else {
                            $this->logger->error("❌ Invoice creation failed for order: " . $order->getIncrementId());
                        }

                        // Mark as processing
                        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                              ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                        $order->addStatusHistoryComment(
                            __('Order status changed to Processing: Payment successfully received via LeanX Payment Gateway.')
                        );
                        $this->orderResource->save($order);
                        $this->logger->info('Order marked as processing.', ['order_id' => $orderId]);

                        // Redirect to success page
                        $resultRedirect = $this->redirectFactory->create();
                        $successful = true;
                        return $resultRedirect->setPath('checkout/onepage/success');
                    } else if ($invoiceStatus == 'FAILED'){
                        // ✅ Close the authorization transaction after failed payment
                        $this->invoiceGenerator->closeAuthorizationTransaction($order);

                        // Mark as canceled
                        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                              ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
                        $order->addStatusHistoryComment(
                            __('Order status changed to Canceled: Payment failed via LeanX Payment Gateway.')
                        );
                        $this->orderResource->save($order);
                        $this->logger->info('Order marked as canceled.', ['order_id' => $orderId]);
                        
                        // Redirect to failure page
                        $resultRedirect = $this->redirectFactory->create();
                        $successful = true;
                        return $resultRedirect->setPath('checkout/onepage/failure');
                    } else {
                        // Mark as on hold
                        $order->setState(\Magento\Sales\Model\Order::STATE_HOLDED)
                              ->setStatus(\Magento\Sales\Model\Order::STATE_HOLDED);
                        $order->addStatusHistoryComment(
                            __('Order status changed to Holded: Unknown payment status via LeanX Payment Gateway.')
                        );

                        $this->orderResource->save($order);
                        $this->logger->info('Order marked as holded.', ['order_id' => $orderId]);
                        // Redirect to checkout
                        $resultRedirect = $this->redirectFactory->create();
                        $successful = true;
                        return $resultRedirect->setPath('checkout/cart', ['_query' => ['payment' => 'failed']]);
                    }
                } else {
                    $attempt++;
                    sleep(1); // Sleep for 1 second to give some time before retrying. Adjust as needed.
                    $this->logger->error('Failed to get a 200 response from the API after 3 attempts for order ID: ' . $orderId);
                    
                    throw new \Magento\Framework\Exception\LocalizedException(__('Failed to get a successful API Response: %1', $errorMessage));
                }
            }
        }
    }
}