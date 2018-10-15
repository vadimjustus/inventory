<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventorySales\Plugin\SalesInventory;

use Magento\InventorySalesApi\Model\ReturnProcessor\Request\ItemsToRefundInterfaceFactory;
use Magento\InventorySalesApi\Model\ReturnProcessor\ProcessRefundItemsInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\SalesInventory\Model\Order\ReturnProcessor;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;

class ProcessReturnQtyOnCreditMemoPlugin
{
    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;

    /**
     * @var ItemsToRefundInterfaceFactory
     */
    private $itemsToRefundFactory;

    /**
     * @var ProcessRefundItemsInterface
     */
    private $processRefundItems;

    /**
     * @var IsSourceItemManagementAllowedForProductTypeInterface
     */
    private $isSourceItemManagementAllowedForProductType;

    /**
     * @var GetProductTypesBySkusInterface
     */
    private $getProductTypesBySkus;

    /**
     * @param GetSkusByProductIdsInterface $getSkusByProductIds
     * @param ItemsToRefundInterfaceFactory $itemsToRefundFactory
     * @param ProcessRefundItemsInterface $processRefundItems
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType
     * @param GetProductTypesBySkusInterface $getProductTypesBySkus
     */
    public function __construct(
        GetSkusByProductIdsInterface $getSkusByProductIds,
        ItemsToRefundInterfaceFactory $itemsToRefundFactory,
        ProcessRefundItemsInterface $processRefundItems,
        IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        GetProductTypesBySkusInterface $getProductTypesBySkus
    ) {
        $this->getSkusByProductIds = $getSkusByProductIds;
        $this->itemsToRefundFactory = $itemsToRefundFactory;
        $this->processRefundItems = $processRefundItems;
        $this->isSourceItemManagementAllowedForProductType = $isSourceItemManagementAllowedForProductType;
        $this->getProductTypesBySkus = $getProductTypesBySkus;
    }

    /**
     * @param ReturnProcessor $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @param bool $isAutoReturn
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function aroundExecute(
        ReturnProcessor $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        OrderInterface $order,
        array $returnToStockItems = [],
        $isAutoReturn = false
    ) {
        $items = [];
        foreach ($creditmemo->getItems() as $item) {
            /** @var OrderItemInterface $orderItem */
            $orderItem = $item->getOrderItem();
            $itemSku = $item->getSku() ?: $this->getSkusByProductIds->execute(
                [$item->getProductId()]
            )[$item->getProductId()];

            if ($this->isValidItem($itemSku, $orderItem->getProductType())) {
                $qty = (float)$item->getQty();
                $processedQty = $orderItem->getQtyCanceled() - $orderItem->getQtyRefunded();
                $items[$itemSku] = [
                    'qty' => ($items[$itemSku]['qty'] ?? 0) + $qty,
                    'processedQty' => ($items[$itemSku]['processedQty'] ?? 0) + (float)$processedQty
                ];
            }
        }

        $itemsToRefund = [];
        foreach ($items as $sku => $data) {
            $itemsToRefund[] = $this->itemsToRefundFactory->create([
                'sku' => $sku,
                'qty' => $data['qty'],
                'processedQty' => $data['processedQty']
            ]);
        }
        $this->processRefundItems->execute($order, $itemsToRefund, $returnToStockItems);
    }

    /**
     * @param string $sku
     * @param string $typeId
     * @return bool
     */
    private function isValidItem(string $sku, string $typeId): bool
    {
        //TODO: https://github.com/magento-engcom/msi/issues/1761
        // If product type located in table sales_order_item is "grouped" replace it with "simple"
        if ($typeId === 'grouped') {
            $typeId = 'simple';
        }

        $productType = $this->getProductTypesBySkus->execute(
            [$sku]
        )[$sku] ?: $typeId;

        return $this->isSourceItemManagementAllowedForProductType->execute($productType);
    }
}
