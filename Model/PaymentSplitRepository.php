<?php

namespace Vindi\Payment\Model;

use Vindi\Payment\Api\PaymentSplitRepositoryInterface;
use Vindi\Payment\Api\Data\PaymentSplitInterface;
use Vindi\Payment\Model\ResourceModel\PaymentSplit as PaymentSplitResource;
use Vindi\Payment\Model\ResourceModel\PaymentSplit\CollectionFactory as PaymentSplitCollectionFactory;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\CouldNotDeleteException;

class PaymentSplitRepository implements PaymentSplitRepositoryInterface
{
    private $resource;
    private $paymentSplitFactory;
    private $collectionFactory;
    private $searchResultsFactory;

    public function __construct(
        PaymentSplitResource $resource,
        PaymentSplitFactory $paymentSplitFactory,
        PaymentSplitCollectionFactory $collectionFactory,
        SearchResultsFactory $searchResultsFactory
    ) {
        $this->resource = $resource;
        $this->paymentSplitFactory = $paymentSplitFactory;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
    }

    public function getById($id)
    {
        $paymentSplit = $this->paymentSplitFactory->create();
        $this->resource->load($paymentSplit, $id);
        if (!$paymentSplit->getId()) {
            throw new NoSuchEntityException(__('Payment Split with ID "%1" does not exist.', $id));
        }
        return $paymentSplit;
    }

    public function save(PaymentSplitInterface $paymentSplit)
    {
        try {
            $this->resource->save($paymentSplit);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save Payment Split: %1', $e->getMessage()));
        }
        return $paymentSplit;
    }

    public function delete(PaymentSplitInterface $paymentSplit)
    {
        try {
            $this->resource->delete($paymentSplit);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete Payment Split: %1', $e->getMessage()));
        }
        return true;
    }

    public function deleteById($id)
    {
        $paymentSplit = $this->getById($id);
        return $this->delete($paymentSplit);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setItems($collection->getItems());
        return $searchResults;
    }
}
