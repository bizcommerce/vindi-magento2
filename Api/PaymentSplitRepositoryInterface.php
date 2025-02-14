<?php

namespace Vindi\Payment\Api;

use Vindi\Payment\Api\Data\PaymentSplitInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;

interface PaymentSplitRepositoryInterface
{
    public function getById($id);

    public function save(PaymentSplitInterface $paymentSplit);

    public function delete(PaymentSplitInterface $paymentSplit);

    public function deleteById($id);

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
