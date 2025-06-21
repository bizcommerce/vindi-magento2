<?php
namespace Vindi\Payment\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Vindi\Payment\Logger\Logger;
use Vindi\Payment\Helper\Api;
use Vindi\Payment\Helper\Data;
use Vindi\Payment\Helper\WebhookHandler;
use Vindi\Payment\Helper\WebhookQueueManager;

/**
 * Class Webhook
 * @package Vindi\Payment\Controller\Index
 */
class Webhook extends Action
{
    protected $_pageFactory;
    private $webhookHandler;
    private $webhookQueueManager;
    /**
     * @var Api
     */
    private $api;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var Data
     */
    private $helperData;

    /**
     * Webhook constructor.
     *
     * @param Api $api
     * @param Logger $logger
     * @param WebhookHandler $webhookHandler
     * @param WebhookQueueManager $webhookQueueManager
     * @param Data $helperData
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Api $api,
        Logger $logger,
        WebhookHandler $webhookHandler,
        WebhookQueueManager $webhookQueueManager,
        Data $helperData,
        Context $context,
        PageFactory $pageFactory
    ) {
        $this->api = $api;
        $this->logger = $logger;
        $this->_pageFactory = $pageFactory;
        $this->webhookHandler = $webhookHandler;
        $this->webhookQueueManager = $webhookQueueManager;
        $this->helperData = $helperData;
        parent::__construct($context);
    }

    /**
     * The route that webhooks will use.
     */
    public function execute()
    {
        // Validate webhook request
        if (!$this->validateRequest()) {
            $ip = $this->webhookHandler->getRemoteIp();
            $this->logger->error(__(sprintf('Invalid webhook attempt from IP %s', $ip)));
            return $this->getResponse()->setHttpResponseCode(401);
        }

        $body = file_get_contents('php://input');
        $this->logger->info("=========================");
        $this->logger->info(__(sprintf("Webhook New Event!\n%s", $body)));

        try {
            // Parse webhook data
            $jsonBody = json_decode($body, true);
            
            if (!$jsonBody || !isset($jsonBody['event'])) {
                $this->logger->error('Invalid webhook payload received');
                return $this->getResponse()->setHttpResponseCode(400);
            }

            $eventType = $jsonBody['event']['type'];
            $eventData = $jsonBody['event']['data'];

            // Handle test events synchronously
            if ($eventType === 'test') {
                $this->logger->info(__('Webhook test event received.'));
                return $this->getResponse()->setHttpResponseCode(200);
            }

            // Add webhook to async queue
            $priority = $this->webhookQueueManager->getPriorityForEventType($eventType);
            $this->webhookQueueManager->addToQueue($eventType, $eventData, $priority);

            $this->logger->info("Webhook {$eventType} added to processing queue");

            return $this->getResponse()->setHttpResponseCode(200);

        } catch (\Exception $e) {
            $this->logger->error('Error processing webhook: ' . $e->getMessage());
            return $this->getResponse()->setHttpResponseCode(500);
        }
    }

    /**
     * Validate the webhook for security reasons.
     *
     * @return bool
     */
    private function validateRequest()
    {
        $systemKey = $this->helperData->getWebhookKey();
        $requestKey = $this->getRequest()->getParam('key');
        return $systemKey === $requestKey;
    }
}
