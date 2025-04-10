<?php

namespace LeanX\Payments\Model\Payment;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\Logger as MethodLogger;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;
use LeanX\Payments\Gateway\Command\PurchaseCommand;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;

class LeanX extends AbstractMethod
{
    /**
     * Payment method code that must match <payment><leanx> in your etc/config.xml
     */
    protected $_code = 'leanx';

    /**
     * Enable authorize() calls
     */
    protected $_canAuthorize = true;

    /**
     * @var PurchaseCommand|null
     */
    private $purchaseCommand;

    /**
     * Constructor
     *
     * Replicates AbstractMethod's constructor parameters in the same order,
     * with array $data = [] as an optional argument,
     * and THEN adds PurchaseCommand $purchaseCommand = null as another optional argument.
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory = null,
        AttributeValueFactory $customAttributeFactory = null,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        MethodLogger $logger,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = [],
        PaymentDataObjectFactory $paymentDataObjectFactory = null,
        PurchaseCommand $purchaseCommand = null
    ) {
        // Pass the first 9 parameters plus $data to AbstractMethod:
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->paymentDataObjectFactory = $paymentDataObjectFactory;
        // Store the PurchaseCommand (may be null if DI not configured)
        $this->purchaseCommand = $purchaseCommand;

        // Debug log
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('LeanX Constructor - PurchaseCommand injected.');
    }

    private function createPaymentDataObject(\Magento\Payment\Model\InfoInterface $payment)
    {
        return $this->paymentDataObjectFactory->create($payment);
    }

    /**
     * Optional isAvailable() override
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $log = new \Zend_Log();
        $log->addWriter($writer);
        $log->info('LeanX Payment Method isAvailable called.');

        return parent::isAvailable($quote);
    }

    /**
     * Authorize payment using our PurchaseCommand
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws \Exception
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $log = new \Zend_Log();
        $log->addWriter($writer);
        $log->info('LeanX Payment Method authorize called. Amount: ' . $amount);
        
        $order = $payment->getOrder();
    
        // Get Quote ID and log it
        $quoteId = $order->getQuoteId();
        $log->info('Setting Quote ID in Additional Information: ' . ($quoteId ?: 'MISSING'));
        if (!$quoteId) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Quote ID is missing at authorization.'));
        }
    
        // Store Quote ID in payment additional information
        $payment->setAdditionalInformation('quote_id', $quoteId);

        if ($this->purchaseCommand) {
            try {
                $this->purchaseCommand->execute([
                    'payment' => $this->createPaymentDataObject($payment),
                    'amount'  => $amount
                ]);
            } catch (\Exception $e) {
                $log->err('Error during authorization: ' . $e->getMessage());
                throw $e;
            }
        } else {
            $log->err('No PurchaseCommand injected - cannot authorize.');
        }

        return $this;
    }
}