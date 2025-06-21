<?php

namespace Vindi\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vindi\Payment\Helper\WebhookQueueManager;
use Vindi\Payment\Api\WebhookQueueRepositoryInterface;
use Vindi\Payment\Model\ResourceModel\WebhookQueue\CollectionFactory;
use Vindi\Payment\Model\WebhookQueue;

/**
 * Console command to manage webhook queue
 */
class WebhookQueueManagerCommand extends Command
{
    /**
     * @var WebhookQueueManager
     */
    private $webhookQueueManager;

    /**
     * @var WebhookQueueRepositoryInterface
     */
    private $webhookQueueRepository;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * Constructor
     *
     * @param WebhookQueueManager $webhookQueueManager
     * @param WebhookQueueRepositoryInterface $webhookQueueRepository
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        WebhookQueueManager $webhookQueueManager,
        WebhookQueueRepositoryInterface $webhookQueueRepository,
        CollectionFactory $collectionFactory
    ) {
        $this->webhookQueueManager = $webhookQueueManager;
        $this->webhookQueueRepository = $webhookQueueRepository;
        $this->collectionFactory = $collectionFactory;
        parent::__construct();
    }

    /**
     * Configure command
     */
    protected function configure()
    {
        $this->setName('vindi:webhook:queue')
            ->setDescription('Manage Vindi webhook queue')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: status, cleanup, retry, reset'
            )
            ->addOption(
                'event-type',
                'e',
                InputOption::VALUE_OPTIONAL,
                'Filter by event type'
            )
            ->addOption(
                'queue-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Specific queue ID for reset/retry actions'
            )
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Days for cleanup (default: 30)',
                30
            );
    }

    /**
     * Execute command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $eventType = $input->getOption('event-type');
        $queueId = $input->getOption('queue-id');
        $days = (int) $input->getOption('days');

        switch ($action) {
            case 'status':
                return $this->showStatus($output, $eventType);
            case 'cleanup':
                return $this->cleanup($output, $days);
            case 'retry':
                return $this->retryItems($output, $eventType, $queueId);
            case 'reset':
                return $this->resetItems($output, $eventType, $queueId);
            default:
                $output->writeln('<error>Invalid action. Use: status, cleanup, retry, reset</error>');
                return Command::FAILURE;
        }
    }

    /**
     * Show queue status
     *
     * @param OutputInterface $output
     * @param string|null $eventType
     * @return int
     */
    private function showStatus(OutputInterface $output, $eventType = null)
    {
        $collection = $this->collectionFactory->create();
        
        if ($eventType) {
            $collection->addEventTypeFilter($eventType);
        }

        $output->writeln('<info>Webhook Queue Status</info>');
        $output->writeln('===================');

        // Group by event type and status
        $collection->getSelect()
            ->reset(\Zend_Db_Select::COLUMNS)
            ->columns(['event_type', 'status', 'count' => 'COUNT(*)'])
            ->group(['event_type', 'status']);

        $rows = [];
        foreach ($collection as $item) {
            $rows[] = [
                $item->getData('event_type'),
                $item->getData('status'),
                $item->getData('count')
            ];
        }

        if (empty($rows)) {
            $output->writeln('<comment>No items in queue</comment>');
            return Command::SUCCESS;
        }

        // Display as table
        $table = new \Symfony\Component\Console\Helper\Table($output);
        $table->setHeaders(['Event Type', 'Status', 'Count']);
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Cleanup old items
     *
     * @param OutputInterface $output
     * @param int $days
     * @return int
     */
    private function cleanup(OutputInterface $output, $days)
    {
        $output->writeln("<info>Cleaning up items older than {$days} days...</info>");
        
        $deletedCount = $this->webhookQueueManager->cleanupOldItems($days);
        
        $output->writeln("<info>Deleted {$deletedCount} old items</info>");
        
        return Command::SUCCESS;
    }

    /**
     * Retry failed items
     *
     * @param OutputInterface $output
     * @param string|null $eventType
     * @param int|null $queueId
     * @return int
     */
    private function retryItems(OutputInterface $output, $eventType = null, $queueId = null)
    {
        if ($queueId) {
            // Retry specific item
            try {
                $webhookQueue = $this->webhookQueueRepository->getById($queueId);
                
                if ($webhookQueue->getStatus() !== WebhookQueue::STATUS_FAILED) {
                    $output->writeln("<error>Item {$queueId} is not in failed status</error>");
                    return Command::FAILURE;
                }

                $webhookQueue->setStatus(WebhookQueue::STATUS_PENDING);
                $webhookQueue->setRetryCount(0);
                $webhookQueue->setErrorMessage(null);
                $webhookQueue->setScheduledAt(null);
                
                $this->webhookQueueRepository->save($webhookQueue);
                
                $output->writeln("<info>Item {$queueId} reset for retry</info>");
                
            } catch (\Exception $e) {
                $output->writeln("<error>Error: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
        } else {
            // Retry all failed items of specific type
            $collection = $this->collectionFactory->create();
            $collection->addStatusFilter(WebhookQueue::STATUS_FAILED);
            
            if ($eventType) {
                $collection->addEventTypeFilter($eventType);
            }

            $count = 0;
            foreach ($collection as $webhookQueue) {
                $webhookQueue->setStatus(WebhookQueue::STATUS_PENDING);
                $webhookQueue->setRetryCount(0);
                $webhookQueue->setErrorMessage(null);
                $webhookQueue->setScheduledAt(null);
                
                $this->webhookQueueRepository->save($webhookQueue);
                $count++;
            }

            $output->writeln("<info>Reset {$count} failed items for retry</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * Reset items to pending
     *
     * @param OutputInterface $output
     * @param string|null $eventType
     * @param int|null $queueId
     * @return int
     */
    private function resetItems(OutputInterface $output, $eventType = null, $queueId = null)
    {
        if ($queueId) {
            // Reset specific item
            try {
                $webhookQueue = $this->webhookQueueRepository->getById($queueId);
                
                $webhookQueue->setStatus(WebhookQueue::STATUS_PENDING);
                $webhookQueue->setRetryCount(0);
                $webhookQueue->setErrorMessage(null);
                $webhookQueue->setScheduledAt(null);
                
                $this->webhookQueueRepository->save($webhookQueue);
                
                $output->writeln("<info>Item {$queueId} reset to pending</info>");
                
            } catch (\Exception $e) {
                $output->writeln("<error>Error: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
        } else {
            $output->writeln('<error>Queue ID is required for reset action</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
