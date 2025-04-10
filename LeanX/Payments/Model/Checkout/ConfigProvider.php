<?php

namespace Leanx\Payments\Model\Checkout;

use Magento\Checkout\Model\ConfigProviderInterface;

class ConfigProvider implements ConfigProviderInterface
{
    /**
     * Return configuration array
     *
     * @return array
     */
    public function getConfig()
    {
        $config = [
            'payment' => [
                'leanx' => [
                    'title' => 'LeanX Payment',
                    'logoUrl' => 'https://via.placeholder.com/150', // Temporary static logo
                ],
            ],
        ];

        // Debug log
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->info('ConfigProvider executed');
        $logger->info(print_r($config, true));

        return $config;
    }
}