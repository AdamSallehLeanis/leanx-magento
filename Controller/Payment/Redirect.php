<?php

namespace LeanX\Payments\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use LeanX\Payments\Gateway\Command\PurchaseCommand;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Redirect extends Action
{
    protected $logger;
    protected $resultJsonFactory;
    protected $checkoutSession;
    protected $orderRepository;
    protected $transactionBuilder;
    protected $transactionRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        OrderRepositoryInterface $orderRepository,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionRepository = $transactionRepository;
    }

    public function execute()
    {
        $this->logger = new Logger('leanx_payment');
        $logFilePath = BP . '/var/log/leanx_payment_method.log';
        $this->logger->pushHandler(new StreamHandler($logFilePath, Logger::DEBUG));
        $this->logger->info('Redirect execution called.');

        $resultJson = $this->resultJsonFactory->create();

        // Get the last placed order
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order || !$order->getId()) {
            return $resultJson->setData(['error' => true, 'message' => 'No order found.']);
        }

        // Generate auth transaction
        $authTxnId = 'LeanX_auth_' . uniqid();
        $this->createAuthorizeTransaction($order, $authTxnId);

        // Update order status
        $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $order->addStatusHistoryComment(
            __('Order status changed to Pending Payment: Awaiting payment via LeanX Payment Gateway.')
        );
        $this->orderRepository->save($order);

        // Retrieve stored redirect URL
        $redirectUrl = $order->getPayment()->getAdditionalInformation('leanx_redirect_url');
        if (!$redirectUrl) {
            return $resultJson->setData(['error' => true, 'message' => 'Redirect URL not found.']);
        }
        
        return $resultJson->setData(['redirect_url' => $redirectUrl, 'abc' => $this->getRequest()->getParam('client_data')]);
    }

    public function createAuthorizeTransaction($order, $txnId)
    {
        $payment = $order->getPayment();

        // ✅ Attach gateway response metadata
        $payment->setTransactionId($txnId);
        $payment->setIsTransactionClosed(0);
        $payment->addTransactionCommentsToOrder($txnId, __('Payment Authorized via LeanX Payment Gateway.'));

        // ✅ Build Transaction Object
        $transaction = $this->transactionBuilder
            ->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($txnId)
            ->setFailSafe(true)
            ->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => 
                    [
                        'gateway_txn_id' => $txnId,
                        'amount' => $order->getGrandTotal(),
                        'currency' => $order->getOrderCurrencyCode(),
                        'payment_method' => 'LeanX Payment Gateway',
                        'customer_email' => $order->getCustomerEmail(),
                    ]
                ]
            )
            ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH);

        // ✅ Save Transaction Explicitly
        try {
            $this->transactionRepository->save($transaction);
            $this->logger->info('Transaction successfully saved.', ['txn_id' => $txnId]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save transaction: ' . $e->getMessage());
        }
    }
}