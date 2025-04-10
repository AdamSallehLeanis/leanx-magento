<?php

namespace LeanX\Payments\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Callback extends Action
{
    protected $resultJsonFactory;
    protected $orderFactory;
    protected $orderResource;
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderFactory $orderFactory,
        OrderResource $orderResource
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderFactory = $orderFactory;
        $this->orderResource = $orderResource;

        // ✅ Custom Logger: Write to var/log/leanx_payment_method.log
        $this->logger = new Logger('leanx_payment');
        $logFilePath = BP . '/var/log/leanx_payment_method.log';
        $this->logger->pushHandler(new StreamHandler($logFilePath, Logger::DEBUG));
    }

    public function execute()
    {
        $request = $this->getRequest();
        $this->logger->info('Callback received.', [
            'method' => $request->getMethod(),
            'params' => $request->getParams()
        ]);
        $this->logger->info('Received payment gateway callback.', ['params' => $request->getParams()]);

        $resultJson = $this->resultJsonFactory->create();

        // ✅ Step 1: Get order ID from request
        $orderId = $request->getParam('order_id');
        $transactionStatus = $request->getParam('status');

        if (!$orderId) {
            $this->logger->error('Callback failed: Missing order ID.');
            return $resultJson->setData(['error' => true, 'message' => 'Missing order ID']);
        }

        try {
            // ✅ Step 2: Load order using OrderFactory
            $order = $this->orderFactory->create();
            $this->orderResource->load($order, $orderId);

            if (!$order->getId()) {
                throw new LocalizedException(__('Order not found.'));
            }

            // ✅ Step 3: Handle payment status
            if ($transactionStatus === 'success') {
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                      ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $this->logger->info('Order marked as processing.', ['order_id' => $orderId]);
            } elseif ($transactionStatus === 'failed') {
                $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                      ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
                $this->logger->info('Order marked as canceled.', ['order_id' => $orderId]);
            } elseif ($transactionStatus === 'pending') {
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                      ->setStatus(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
                $this->logger->info('Order marked as pending payment.', ['order_id' => $orderId]);
            } else {
                $this->logger->error('Unknown payment status received.', ['status' => $transactionStatus]);
                return $resultJson->setData(['error' => true, 'message' => 'Unknown payment status.']);
            }

            // ✅ Step 4: Save the order
            $this->orderResource->save($order);

            return $resultJson->setData(['success' => true, 'message' => 'Order updated successfully.']);
        } catch (\Exception $e) {
            $this->logger->error('Error processing callback.', ['error' => $e->getMessage()]);
            return $resultJson->setData(['error' => true, 'message' => $e->getMessage()]);
        }
    }
}