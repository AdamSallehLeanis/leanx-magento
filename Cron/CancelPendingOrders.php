<?php

namespace LeanX\Payments\Cron;

use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class CancelPendingOrders
{
    protected $orderCollectionFactory;
    protected $orderRepository;
    protected $scopeConfig;
    protected $logger;

    public function __construct(
        CollectionFactory $orderCollectionFactory,
        OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function execute()
    {
        $this->logger->info("🔍 Running Cron: Cancel Unpaid Orders...");

        // Get the configured timeout (default: 1 hour)
        $timeout = (int) $this->scopeConfig->getValue('payment/leanx/pending_order_timeout', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        if (!$timeout) {
            $timeout = 60; // Default to 60 minutes
        }

        $timeThreshold = date('Y-m-d H:i:s', strtotime("-$timeout minutes"));

        // Fetch orders that are still in "pending_payment"
        $orders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('state', \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['lt' => $timeThreshold]);

        foreach ($orders as $order) {
            try {
                $this->cancelOrder($order);
                $this->logger->info("✅ Order Canceled Due to Timeout: " . $order->getIncrementId());
            } catch (\Exception $e) {
                $this->logger->error("❌ Error Cancelling Order " . $order->getIncrementId() . ": " . $e->getMessage());
            }
        }
    }

    private function cancelOrder($order)
    {
        if ($order->canCancel()) {
            $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                  ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
            $order->addStatusHistoryComment(__('Order was automatically canceled due to unpaid status.'));
            $this->orderRepository->save($order);
        }
    }
}