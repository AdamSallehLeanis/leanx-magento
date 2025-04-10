<?php

namespace LeanX\Payments\Model\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Zend_Log;
use Zend_Log_Writer_Stream;

class GatewayClient
{
    protected $client;
    protected $scopeConfig;
    private $apiBaseUrl;
    private $logger;
    
    const CONFIG_AUTH_TOKEN = 'payment/leanx/auth_token';
    const CONFIG_IS_SANDBOX = 'payment/leanx/is_sandbox';
    const CONFIG_BILL_INVOICE_ID = 'payment/leanx/bill_invoice_id';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        $apiBaseUrl = ''
    ) {
        $this->client = new Client();
        $this->scopeConfig = $scopeConfig;
        $this->apiBaseUrl = $apiBaseUrl;

        // Setup Zend Logger
        $writer = new Zend_Log_Writer_Stream(BP . '/var/log/leanx_payment_method.log');
        $this->logger = new Zend_Log();
        $this->logger->addWriter($writer);
        
    }

    public function authorizePayment(array $payload)
    {
        try {
            $is_sandbox = $this->scopeConfig->getValue(
                self::CONFIG_IS_SANDBOX,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            $auth_token = $this->scopeConfig->getValue(
                self::CONFIG_AUTH_TOKEN,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $this->logger->info('Auth Token: ' . ($auth_token ?: 'MISSING'));

            $billInvoiceId = $this->scopeConfig->getValue(
                self::CONFIG_BILL_INVOICE_ID,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $this->logger->info('Bill Invoice Id: ' . ($billInvoiceId ?: 'MISSING'));

            // Ensure client_data exists in the payload
            if (!isset($payload['client_data'])) {
                $this->logger->info('Missing client_data in payload: ' . $apiUrl);
                throw new \Exception('Missing client_data in payload');
            }

            if ($is_sandbox) {
                $this->apiBaseUrl = 'https://api.leanx.dev/api/v1/public-merchant/public/collection-payment-portal?invoice_no=';
            } else {
                $this->apiBaseUrl = 'https://api.leanx.io/api/v1/public-merchant/public/collection-payment-portal?invoice_no=';
            }
            $apiUrl = $this->apiBaseUrl . $billInvoiceId . '-' . $payload['client_data'];
            $this->logger->info('Final API URL: ' . $apiUrl);
            
            // Log the API Request Details
            $this->logger->info('Sending API Request:');
            $this->logger->info('URL: ' . $apiUrl);
            $this->logger->info('Headers: ' . json_encode([
                'Content-Type' => 'application/json; charset=utf-8',
                'auth-token' => $auth_token
            ], JSON_PRETTY_PRINT));
            $this->logger->info('Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

            $response = $this->client->post($apiUrl, [
                'method'      => 'POST',
                'timeout'     => 60,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => array(
                    'Content-Type' => 'application/json; charset=utf-8',
                    'auth-token' => $auth_token
                ),
                'body'        => json_encode($payload),
                'cookies'     => array(),
            ]);
            // Get the response body
            $responseBody = $response->getBody()->getContents();
            $decodedResponse = json_decode($responseBody, true);

            // Log the API Response
            $this->logger->info('API Response: ' . json_encode($responseBody, JSON_PRETTY_PRINT));

            if (!is_array($decodedResponse)) {
                $this->logger->err('API response is not a valid JSON object. Response: ' . $responseBody);
                return ['status' => 'error', 'message' => 'Invalid API response format'];
            }
            
            return $decodedResponse;
            
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}