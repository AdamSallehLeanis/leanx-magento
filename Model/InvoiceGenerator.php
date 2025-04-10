<?php

namespace LeanX\Payments\Model;

use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

class InvoiceGenerator
{
    protected $orderRepository;
    protected $invoiceService;
    protected $transaction;
    protected $transactionBuilder;
    protected $transactionRepository;
    protected $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        Transaction $transaction,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->logger = $logger;
    }

    public function generateInvoice($order, $invoiceStatus)
    {
        try {
            if (!$order->canInvoice()) {
                $this->logger->info("❌ Order #{$order->getIncrementId()} cannot be invoiced.");
                return false;
            }

            // 📝 Create Invoice
            $captureTxnId = 'LeanX_capture_' . uniqid();
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setTransactionId($captureTxnId);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $invoice->save();

            // ✅ Attach Invoice to Order Payment
            $payment = $order->getPayment();
            $payment->setTransactionId($captureTxnId);
            $payment->addTransactionCommentsToOrder($captureTxnId, __('Payment Captured via LeanX Payment Gateway.'));
            $payment->setParentTransactionId($authTxnId);
            $payment->setIsTransactionClosed(1);
            $payment->save();

            $this->closeAuthorizationTransaction($order);

            // ✅ Link Invoice & Transaction
            $transaction = $this->transactionBuilder
                ->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($captureTxnId)
                ->setFailSafe(true)
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => 
                        [
                            'txn_id' => $captureTxnId,
                            'invoice_id' => $invoice->getId(),
                            'amount' => $invoice->getGrandTotal(),
                            'currency' => $order->getOrderCurrencyCode(),
                            'payment_method' => 'LeanX Payment Gateway',
                            'customer_email' => $order->getCustomerEmail(),
                            'invoice_status' => $invoiceStatus,
                        ]
                    ]
                )
                ->build(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE);
                
            $transaction->setParentId($authTxnId); // 🔥 Ensures it's linked to the authorization transaction
            $transaction->setIsClosed(true);
            $transaction->save();

            // // ✅ Save Order
            // $this->orderRepository->save($order);

            // 📧 Send Invoice Email
            try {
                $invoice->sendEmail(true);
            } catch (\Exception $e) {
                $this->logger->error("⚠️ Invoice email failed: " . $e->getMessage());
            }

            $this->logger->info("✅ Invoice Created for Order #{$order->getIncrementId()} (Invoice ID: " . $invoice->getId() . ")");
            return $invoice->getId();

        } catch (\Exception $e) {
            $this->logger->error("❌ Invoice Error for Order #{$order->getIncrementId()}: " . $e->getMessage());
            return false;
        }
    }

    public function closeAuthorizationTransaction($order)
    {
        // ✅ Retrieve Order Payment
        $payment = $order->getPayment();
        $authTxnId = $payment->getLastTransId();

        // ✅ Check if an authorization transaction exists
        if (!$authTxnId) {
            $this->logger->error("No Authorization Transaction Found for Order ID: " . $order->getIncrementId());
            return;
        }

        try {
            // 🔹 Fetch the Authorization Transaction
            $authTransaction = $this->transactionRepository->getByTransactionId(
                $authTxnId,
                $payment->getId(),
                $order->getId()
            );

            if ($authTransaction) {
                // ✅ Close Authorization Transaction
                $authTransaction->setIsClosed(1);
                $this->transactionRepository->save($authTransaction);
                $this->logger->info("✅ Closed Authorization Transaction: " . $authTxnId);
            } else {
                $this->logger->error("⚠️ Authorization Transaction Not Found: " . $authTxnId);
            }
        } catch (\Exception $e) {
            $this->logger->error("❌ Failed to Close Authorization Transaction: " . $e->getMessage());
        }
    }
}