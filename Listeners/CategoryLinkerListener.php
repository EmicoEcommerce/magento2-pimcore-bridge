<?php
/**
 * @package  Divante\PimcoreIntegration
 * @author Bartosz Herba <bherba@divante.pl>
 * @copyright 2018 Divante Sp. z o.o.
 * @license See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\PimcoreIntegration\Listeners;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryLinkRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\StateException;

/**
 * Class CategoryLinkerListener
 */
class CategoryLinkerListener implements ObserverInterface
{
    /**
     * @var CategoryLinkManagementInterface
     */
    private $categoryLinkManagement;

    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;

    /**
     * CategoryModifier constructor.
     *
     * @param CategoryLinkManagementInterface $categoryLinkManagement
     */
    public function __construct(
        CategoryLinkManagementInterface $categoryLinkManagement,
        CategoryRepositoryInterface $categoryRepository
    ) {
        $this->categoryLinkManagement = $categoryLinkManagement;
        $this->categoryRepository = $categoryRepository;
    }

    /**
     * @param Observer $observer
     *
     * @throws CouldNotSaveException
     * @throws StateException
     *
     * @return void
     */
    public function execute(Observer $observer)
    {
        $pimcoreProduct = $observer->getData('pimcore');
        $product = $observer->getData('product');

        $categoryIds = $pimcoreProduct->getData('category_ids') ?? [];

        // Reset internal cache containing category -> product link
        // Otherwise the assignProductToCategories method will not behave consistently
        foreach ($categoryIds as $categoryId) {
            $category = $this->categoryRepository->get($categoryId);
            $category->setData('products_position', null);
        }

        $this->categoryLinkManagement->assignProductToCategories($product->getSku(), $categoryIds);

        $product->setData('category_ids', $categoryIds);
    }
}
