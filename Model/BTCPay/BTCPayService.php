<?php
declare(strict_types=1);
/**
 * Integrates BTCPay Server with Magento 2 for online payments
 * @copyright Copyright © 2019-2021 Storefront bv. All rights reserved.
 * @author    Wouter Samaey - wouter.samaey@storefront.be
 *
 * This file is part of Storefront/BTCPay.
 *
 * Storefront/BTCPay is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Storefront\BTCPay\Model\BTCPay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\ScopeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Storefront\BTCPay\Model\BTCPay\Exception\ForbiddenException;
use Storefront\BTCPay\Model\BTCPay\Exception\InvoiceNotFoundException;
use Storefront\BTCPay\Model\Invoice;
use Magento\Framework\Url;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ValueFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\RequestInterface;


class BTCPayService
{
    const CONFIG_API_KEY = 'payment/btcpay/api_key';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var AdapterInterface
     */
    private $db;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var Transaction
     */
    private $transaction;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Url $urlBuilder
     */
    private $urlBuilder;

    /**
     * @var StoreManagerInterface $storeManager
     */
    private $storeManager;

    /**
     * @var WriterInterface $configWriter
     */
    private $configWriter;

    /**
     * @var ValueFactory
     */
    private $configValueFactory;

    /**
     * @var CollectionFactory $configCollectionFactory
     */
    private $configCollectionFactory;

    /**
     * @var RequestInterface $request
     */
    private $request;

    public function __construct(ResourceConnection $resource, ScopeConfigInterface $scopeConfig, OrderRepository $orderRepository, Transaction $transaction, LoggerInterface $logger, Url $urlBuider, StoreManagerInterface $storeManager, WriterInterface $configWriter, ValueFactory $configValueFactory, CollectionFactory $configCollectionFactory, RequestInterface $request)
    {
        $this->scopeConfig = $scopeConfig;
        $this->db = $resource->getConnection();
        $this->orderRepository = $orderRepository;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuider;
        $this->storeManager = $storeManager;
        $this->configWriter = $configWriter;
        $this->configValueFactory = $configValueFactory;
        $this->configCollectionFactory = $configCollectionFactory;
        $this->request=$request;
    }


    /**
     * @param int $storeId
     * @return Client
     */
    private function getClient(int $storeId): Client
    {
        $host = $this->getHost($storeId);
        $port = $this->getPort($storeId);
        $scheme = $this->getScheme($storeId);

        $client = new Client(['base_uri' => $scheme . '://' . $host . ':' . $port]);

        return $client;
    }

    public function getBtcPayServerBaseUrl(int $storeId): ?string
    {
        $r = $this->getStoreConfig('payment/btcpay/btcpay_base_url', $storeId);
        return $r;
    }

    /**
     * @param Order $order
     * @return Invoice
     * @throws NoSuchEntityException
     * @throws \JsonException
     */
    public function createInvoice(Order $order): array
    {
        $magentoStoreId = (int)$order->getStoreId();
        $btcPayStoreId = $this->getBtcPayStore($magentoStoreId);
        if (!$btcPayStoreId) {
            throw new \Storefront\BTCPay\Model\BTCPay\Exception\NoBtcPayStoreConfigured();
        }
        $orderId = (int)$order->getId();

        // TODO make these configurable
        //$speedPolicy = 'HighSpeed';
        // Other values: 'MediumSpeed', 'LowSpeed','LowMediumSpeed'

        // TODO limit payment methods. By default all methods are shown.
        $paymentMethods = null;
        // Example: array with 'BTC', 'BTC-LightningNetwork'

        // TODO config setting to override the expiration time. Defaults to the store's setting in BTCPay Server
        $expirationMinutes = null;

        $invoiceIdPlaceholder = 'INVOICE_ID';
        $orderHash = $this->getOrderHash($order);
        $returnUrl = $order->getStore()->getUrl('btcpay/redirect/returnafterpayment', [
            'orderId' => $order->getId(),
            'invoiceId' => $invoiceIdPlaceholder,
            'hash' => $orderHash,
            '_secure' => true
        ]);

        $returnUrl = str_replace($invoiceIdPlaceholder, '{InvoiceId}', $returnUrl);

        $orderLocale = $this->getStoreConfig('general/locale/code', $magentoStoreId);
        $defaultLanguage = str_replace('_', '-', $orderLocale);

        $ba = $order->getBillingAddress();
        $sa = $order->getShippingAddress();

        $postData = [];
        $postData['amount'] = $order->getGrandTotal();
        $postData['currency'] = $order->getOrderCurrencyCode();
        $postData['metadata']['orderId'] = $order->getIncrementId();
        $postData['metadata']['buyerName'] = trim($order->getBillingAddress()->getCompany() . ', ' . $order->getCustomerName(), ', ');
        $postData['metadata']['buyerEmail'] = $order->getCustomerEmail();
        $postData['metadata']['buyerCountry'] = $sa->getCountryId();
        $postData['metadata']['buyerZip'] = $sa->getPostcode();
        $postData['metadata']['buyerCity'] = $sa->getCity();
        $postData['metadata']['buyerState'] = $sa->getRegionCode();
        $postData['metadata']['buyerAddress1'] = $sa->getStreetLine(1);
        $postData['metadata']['buyerAddress2'] = $sa->getStreetLine(2);
        $postData['metadata']['buyerPhone'] = $ba->getTelephone();
        $postData['metadata']['physical'] = !$order->getIsVirtual();
        $postData['metadata']['taxIncluded'] = $order->getTaxAmount() > 0;

        //$postData['metadata']['posData'] = null;
        // $postData['metadata']['itemCode'] = '';
        // $postData['metadata']['itemDesc'] = '';

        // $postData['checkout']['speedPolicy'] = $speedPolicy;
        // $btcpayInvoice['checkout']['paymentMethods'] = $paymentMethods;
        // $btcpayInvoice['checkout']['expirationMinutes'] = $expirationMinutes;
        // $btcpayInvoice['checkout']['monitoringMinutes'] = 90;
        // $btcpayInvoice['checkout']['paymentTolerance'] = 0;
        $postData['checkout']['redirectURL'] = $returnUrl;
        $postData['checkout']['redirectAutomatically'] = true;
        $postData['checkout']['defaultLanguage'] = $defaultLanguage;


        // Some extra fields not part of the BTCPay Server spec, but we are including them anyway
        $postData['metadata']['magentoOrderId'] = $order->getId();
        $postData['metadata']['buyerAddress3'] = $sa->getStreetLine(3);
        $postData['metadata']['buyerCompany'] = $ba->getCompany();
        $postData['metadata']['buyerFirstname'] = $order->getCustomerFirstname();
        $postData['metadata']['buyerMiddlename'] = $order->getCustomerMiddlename();
        $postData['metadata']['buyerLastname'] = $order->getCustomerLastname();


        $response = $this->doRequest($magentoStoreId, '/api/v1/stores/' . $btcPayStoreId . '/invoices', 'POST', $postData);

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status === 200) {
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

            // Example:
            // {
            //  "id": "7ePcu635UAV9CiGBKDDQvr",
            //  "checkoutLink": "https:\/\/mybtcpay.com\/i\/7ePcu635UAV9CiGBKDDQvr",
            //  "status": "New",
            //  "additionalStatus": "None",
            //  "monitoringExpiration": 1622043882,
            //  "expirationTime": 1622040282,
            //  "createdTime": 1622039382,
            //  "amount": "166.0000",
            //  "currency": "EUR",
            //  "metadata": {
            //    "orderId": "1",
            //    "buyerName": "ACME Corp, Test Tester",
            //    "buyerEmail": "test.tester@acme.com",
            //    "buyerCountry": "US",
            //    "buyerZip": "1000",
            //    "buyerCity": "Test City",
            //    "buyerState": "TE",
            //    "buyerAddress1": "Test Street",
            //    "buyerAddress2": "100",
            //    "buyerPhone": "+123456789101",
            //    "physical": true,
            //    "taxIncluded": false,
            //    "orderIncrementId": "000000001"
            //  },
            //  "checkout": {
            //    "speedPolicy": "MediumSpeed",
            //    "paymentMethods": [
            //      "BTC"
            //    ],
            //    "expirationMinutes": 15,
            //    "monitoringMinutes": 60,
            //    "paymentTolerance": 0,
            //    "redirectURL": "http:\/\/domain.com\/btcpay\/redirect\/returnafterpayment\/orderId\/1\/invoiceId\/%7BInvoiceId%7D\/hash\/ab49d6c0a6696e17cffc9ae6386be83997741a4f\/",
            //    "redirectAutomatically": true,
            //    "defaultLanguage": "en"
            //  }
            //}

            $tableName = $this->db->getTableName('btcpay_invoices');
            $this->db->insert($tableName, [
                'order_id' => $orderId,
                'btcpay_store_id' => $btcPayStoreId,
                'invoice_id' => $data['id'],
                'status' => $data['status']
            ]);
            return $data;
        } else {
            throw new \Storefront\BTCPay\Model\BTCPay\Exception\CannotCreateInvoice($status, $body, $postData);
        }
    }


    /**
     * @param string $btcPayStoreId
     * @param string $invoiceId
     * @return Order|null
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws \JsonException
     */
    public function updateInvoice(string $btcPayStoreId, string $invoiceId): ?Order
    {
        $tableName = $this->db->getTableName('btcpay_invoices');
        $select = $this->db->select()->from($tableName)->where('invoice_id = ?', $invoiceId)->where('btcpay_store_id = ?', $btcPayStoreId)->limit(1);

        $row = $this->db->fetchRow($select);
        if ($row) {
            $orderId = (int)$row['order_id'];
            /* @var $order Order */
            $order = $this->orderRepository->get($orderId);

            $magentoStoreId = $order->getStoreId();

            $invoice = $this->getInvoice($invoiceId, $btcPayStoreId, $magentoStoreId);

            if ($order->getIncrementId() !== $invoice->getOrderId()) {
                throw new RuntimeException('The supplied order "' . $orderId . '"" does not match BTCPay Invoice "' . $invoiceId . '"". Cannot process BTCPay Server Webhook.');
            }

            $invoiceStatus = $invoice->getStatus();

            // TODO refactor to use the model instead of direct SQL reading
            $where = $this->db->quoteInto('order_id = ?', $orderId) . ' and ' . $this->db->quoteInto('invoice_id = ?', $invoiceId);
            $rowsChanged = $this->db->update($tableName, ['status' => $invoiceStatus], $where);

            if ($rowsChanged === 1) {
                switch ($invoiceStatus) {
                    case Invoice::STATUS_PAID:
                        // 1) Payments have been made to the invoice for the requested amount but the invoice has not been confirmed yet. We also don't know if the amount is enough.
                        $paidNotConfirmedStatus = $this->getStoreConfig('payment/btcpay/payment_paid_status', $magentoStoreId);
                        if (!$paidNotConfirmedStatus) {
                            $paidNotConfirmedStatus = false;
                        }
                        $order->addCommentToStatusHistory('Payment underway, but not sure about the amount and also not confirmed yet', $paidNotConfirmedStatus);
                        break;
                    case Invoice::STATUS_CONFIRMED:

                        // 2) Paid and confirmed (happens before complete and transitions to it quickly)

                        // TODO maybe add the transation ID in the comment or something like that?

                        $confirmedStatus = $this->getStoreConfig('payment/btcpay/payment_confirmed_status', $magentoStoreId);
                        $order->addCommentToStatusHistory('Payment confirmed, but not complete yet', $confirmedStatus);
                        break;
                    case Invoice::STATUS_COMPLETE:
                        // 3) Paid, confirmed and settled. Final!
                        $completeStatus = $this->getStoreConfig('payment/btcpay/payment_complete_status', $magentoStoreId);
                        if (!$completeStatus) {
                            $completeStatus = false;
                        }
                        if ($order->canInvoice()) {
                            $order->addCommentToStatusHistory('Payment complete', $completeStatus);

                            $invoice = $order->prepareInvoice();
                            $invoice->setRequestedCaptureCase(Order\Invoice::CAPTURE_OFFLINE);
                            $invoice->register();

                            // TODO we really need to save the invoice first as we are saving it again in this invoice? Leaving it out for now.
                            //$invoice->save();

                            $invoiceSave = $this->transaction->addObject($invoice)->addObject($invoice->getOrder());
                            $invoiceSave->save();
                        }
                        break;
                    case Invoice::STATUS_INVALID:
                        $order->addCommentToStatusHistory('Failed to confirm the order. The order will automatically update when the status changes.');
                        break;
                    case Invoice::STATUS_EXPIRED:
                        // Invoice expired - let's do nothing?
                        // TODO support auto-canceling, but only when the last invoice for the order is expired. 1 order can have multiple invoices :S
                    default:
                        $order->addCommentToStatusHistory('Invoice status: ' . $invoiceStatus);
                        $this->logger->error('Unknown invoice state "' . $invoiceStatus . '" for invoice "' . $invoiceId . '"');
                        break;
                }

                $order->save();

//
//                case 'invoice_refundComplete':
//                    // Full refund
//
//                    $order->addStatusHistoryComment('Refund received through BTCPay Server.');
//                    $order->setState(Order::STATE_CLOSED)->setStatus(Order::STATE_CLOSED);
//
//                    $order->save();
//
//                    break;
//
//                // TODO what about partial refunds, partial payments and overpayment?

                return $order;
            } else {
                // Nothing was changed
                return null;
            }

        } else {
            // No invoice record found
            return null;
        }
    }

    public function updateIncompleteInvoices(): int
    {
        $tableName = $this->db->getTableName('btcpay_invoices');
        $select = $this->db->select()->from($tableName);
        $select->where('status != ?', Invoice::STATUS_COMPLETE);
        $select->where('status != ?', Invoice::STATUS_EXPIRED);

        $r = 0;

        $rows = $this->db->fetchAll($select);

        foreach ($rows as $row) {
            $invoiceId = $row['invoice_id'];
            $btcPayStoreId = $row['btcpay_store_id'];
            $this->updateInvoice($btcPayStoreId, $invoiceId);
            $r++;
        }
        return $r;
    }

    public function getStoreConfig(string $path, int $storeId): ?string
    {
        $r = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return $r;
    }

    private function getHost(int $storeId): ?string
    {
        $r = $this->getStoreConfig('payment/btcpay/host', $storeId);
        return $r;
    }

    private function getScheme(int $storeId): ?string
    {
        $r = $this->getStoreConfig('payment/btcpay/http_scheme', $storeId);
        return $r;
    }

    public function getInvoiceDetailUrl(int $storeId, string $invoiceId): string
    {

        //TODO: replace with getBaseUrl()
        $host = $this->getHost($storeId);
        $scheme = $this->getScheme($storeId);
        $port = $this->getPort($storeId);
        $r = $scheme . '://' . $host . ':' . $port . '/invoices/' . $invoiceId;
        return $r;
    }

    private function getPort(int $storeId): int
    {
        $r = $this->getStoreConfig('payment/btcpay/http_port', $storeId);
        if (!$r) {
            $scheme = $this->getScheme($storeId);
            if ($scheme === 'https') {
                $r = 443;
            } elseif ($scheme === 'http') {
                $r = 80;
            }
        }
        return (int)$r;
    }

    /**
     * Create a unique hash for an order
     * @param Order $order
     * @return string
     */
    public function getOrderHash(Order $order): string
    {
        $preHash = $order->getId() . '-' . $order->getSecret() . '-' . $order->getCreatedAt();
        $r = sha1($preHash);
        return $r;
    }

    public function getApiKeyPermissions(int $magentoStoreId): ?array
    {
        try {
            $apiKey = $this->getApiKey($magentoStoreId);
            if ($apiKey) {
                $client = new \BTCPayServer\Client\ApiKey($this->getBtcPayServerBaseUrl($magentoStoreId), $this->getApiKey($magentoStoreId));
                $data = $client->getCurrent();
                $data = $data->getData();
                $currentPermissions = $data['permissions'];
                sort($currentPermissions);
                return $currentPermissions;
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function doRequest(int $magentoStoreId, string $url, string $method, array $postData = null): ResponseInterface
    {
        $apiKey = $this->getApiKey($magentoStoreId);
        $client = $this->getClient($magentoStoreId);
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'token ' . $apiKey
            ],
        ];

        if ($postData) {
            $options['json'] = $postData;
        }

        try {
            $r = $client->request($method, $url, $options);
        } catch (ClientException $e) {
            $r = $e->getResponse();
            if ($r->getStatusCode() === 403) {
                throw new ForbiddenException($e);
            }
        }
        return $r;
    }

    public function getApiKey(int $storeId): ?string
    {

        $config = $this->getConfigWithoutCache(self::CONFIG_API_KEY);

        return $config[self::CONFIG_API_KEY];
    }

    private function getConfigWithoutCache($path, $scope, $scopeId): ?string
    {
        $dataCollection = $this->configValueFactory->create()->getCollection();
        $dataCollection->addFieldToFilter('path', ['like' => $path . '%']);
        $dataCollection->addFieldToFilter('scope', ['like' => $scope . '%']);
        $dataCollection->addFieldToFilter('scope_id', ['like' => $scopeId . '%']);

        $config = null;
        foreach ($dataCollection as $row) {
            $config[$row->getPath()] = $row->getValue();
        }

        return $config[$path];
    }


    public function getInvoice(string $invoiceId, string $btcpayStoreId, int $magentoStoreId): array
    {
        $url = '/api/v1/stores/' . $btcpayStoreId . '/invoices/' . $invoiceId;
        $response = $this->doRequest($magentoStoreId, $url, 'GET');
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status === 200) {
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            return $data;
        } else {
            throw new InvoiceNotFoundException($invoiceId, $btcpayStoreId, $magentoStoreId);
        }
    }

    public function getStores(int $magentoStoreId): ?array
    {
        $response = $this->doRequest($magentoStoreId, 'api/v1/stores', 'GET');
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status === 200) {
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            return $data;
        } else {
            return null;
        }
    }

    public function getBtcPayStore(int $magentoStoreId): ?string
    {
        $btcPayStoreId = $this->getStoreConfig('payment/btcpay/btcpay_store_id', $magentoStoreId);
        return $btcPayStoreId;
    }

    public function getWebhooksForStore(string $btcPayStoreId, int $magentoStoreId): array
    {
        $response = $this->doRequest($magentoStoreId, 'api/v1/stores/' . $btcPayStoreId . '/webhooks', 'GET');
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status === 200) {
            $data = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            return $data;
        } else {
            // TODO handle this
        }
    }

    public function getWebhookForStore(int $magentoStoreId): ?array
    {
        $btcPayStoreId = $this->getBtcPayStore($magentoStoreId);
        $response = $this->doRequest($magentoStoreId, 'api/v1/stores/' . $btcPayStoreId . '/webhooks', 'GET');
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        $url = $this->getWebhookUrl($magentoStoreId);

        if ($status === 200) {
            $webhooks = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

            foreach ($webhooks as $webhook) {
                if ($webhook['url'] === $url) {
                    return $webhook;
                }
            }
            return null;
        } else {
            throw new \Storefront\BTCPay\Model\BTCPay\Exception\UnexpectedSituation();
        }
    }

    public function createWebhook(int $magentoStoreId, $apiKey): array
    {

        $client = new \BTCPayServer\Client\Webhook($this->getBtcPayServerBaseUrl($magentoStoreId), $apiKey);
        $btcPayStoreId = $this->getBtcPayStore($magentoStoreId);
        $url = $this->getWebhookUrl($magentoStoreId);


        $data = $client->createWebhook($btcPayStoreId, $url);

        $data = $data->getData();

    }

    public function getWebhookUrl(int $magentoStoreId): string
    {
        $url = $this->getStoreConfig('web/secure/base_url', $magentoStoreId);
        $url .= 'rest/V2/btcpay/webhook';
        return $url;
    }

    public function getReceiveApikeyUrl(int $magentoStoreId): string
    {
        $url = $this->getStoreConfig('web/secure/base_url', $magentoStoreId);
        $url .= 'btcpay/apikey/save';
        $hashedSecret = $this->hashSecret($magentoStoreId);
        return $url . '?secret=' . urlencode($hashedSecret).'&store='.$magentoStoreId;
    }

    public function getCurrentMagentoStoreId(): ?int
    {
        $storeId = $this->request->getParam('store');
        if(!$storeId){
            return null;
        }
        return (int)$storeId;

    }

    public function getWebhookSecret(int $magentoStoreId): string
    {
        $secret = $this->getStoreConfig('payment/btcpay/webhook_secret', $magentoStoreId);
        if (!$secret) {
            $secret = $this->createWebhookSecret();

            //Save globally
            $this->configWriter->save('payment/btcpay/webhook_secret', $secret);
        }
        return $secret;
    }

    public function createWebhookSecret(): string
    {
        $str = (string)rand();
        return hash("sha256", $str);
    }

    public function hashSecret(int $magentoStoreId)
    {

        $secret = $this->getWebhookSecret($magentoStoreId);
        $salt = (string)$magentoStoreId;
        return sha1($secret . $salt);
    }

}
