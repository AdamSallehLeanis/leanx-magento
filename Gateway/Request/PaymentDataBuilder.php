<?php
namespace LeanX\Payments\Gateway\Request;

use Magento\Payment\Gateway\Request\BuilderInterface;

class PaymentDataBuilder implements BuilderInterface
{
    public function build(array $buildSubject)
    {
        $paymentDO = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();
        $order = $paymentDO->getOrder();

        return [
            'amount' => $order->getGrandTotalAmount(),
            'currency' => $order->getCurrencyCode(),
            'client_data' => $order->getOrderIncrementId(),
            'customer_email' => $order->getCustomerEmail(),
            'redirect_url' => $payment->getAdditionalInformation('redirect_url'),
        ];
    }
}