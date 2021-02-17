<?php
/**
 * @package  Divante\PimcoreIntegration
 * @author Bartosz Herba <bherba@divante.pl>
 * @copyright 2018 Divante Sp. z o.o.
 * @license See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\PimcoreIntegration\Queue\Action\Asset\Strategy;

use Divante\PimcoreIntegration\Api\ProductRepositoryInterface;
use Divante\PimcoreIntegration\Api\Queue\Data\AssetQueueInterface;
use Divante\PimcoreIntegration\Api\Queue\ProductQueueImporterInterface;
use Divante\PimcoreIntegration\Http\Response\Transformator\Data\AssetInterface;
use Divante\PimcoreIntegration\Model\Queue\Asset\AssetQueue;
use Divante\PimcoreIntegration\Model\Queue\Product\ProductQueue;
use Divante\PimcoreIntegration\Model\Queue\Product\ProductQueueFactory;
use Divante\PimcoreIntegration\Queue\Action\ActionResultFactory;
use Divante\PimcoreIntegration\Queue\Action\ActionResultInterface;
use Divante\PimcoreIntegration\Queue\Action\Asset\PathResolver;
use Divante\PimcoreIntegration\Queue\Action\Asset\TypeMetadataExtractorInterface;
use Divante\PimcoreIntegration\Queue\Importer\AbstractImporter;
use Divante\PimcoreIntegration\Queue\QueueStatusInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\EntryFactory;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class ProductVideo
 */
class ProductVideo implements AssetHandlerStrategyInterface
{
    public const VIDEO_FORMAT_YOUTUBE = 'youtube';
    public const VIDEO_FORMAT_VIMEO = 'vimeo';

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var State
     */
    private $state;

    /**
     * @var DataObject|AssetInterface
     */
    private $dto;

    /**
     * @var PathResolver
     */
    private $pathResolver;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ActionResultFactory
     */
    private $actionResultFactory;

    /**
     * @var ProductQueueImporterInterface
     */
    private $queueImporter;

    /**
     * @var ProductQueueFactory
     */
    private $productQueueFactory;

    /**
     * ProductImage constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param State $state
     * @param PathResolver $pathResolver
     * @param StoreManagerInterface $storeManager
     * @param ActionResultFactory $actionResultFactory
     * @param AbstractImporter $queueImporter
     * @param ProductQueueFactory $productQueueFactory
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        State $state,
        PathResolver $pathResolver,
        StoreManagerInterface $storeManager,
        ActionResultFactory $actionResultFactory,
        AbstractImporter $queueImporter,
        ProductQueueFactory $productQueueFactory
    ) {
        $this->productRepository = $productRepository;
        $this->state = $state;
        $this->pathResolver = $pathResolver;
        $this->storeManager = $storeManager;
        $this->actionResultFactory = $actionResultFactory;
        $this->queueImporter = $queueImporter;
        $this->productQueueFactory = $productQueueFactory;
    }

    /**
     * @param DataObject $dto
     * @param TypeMetadataExtractorInterface $metadataExtractor
     * @param AssetQueueInterface|AssetQueue $queue
     *
     * @throws LocalizedException
     *
     * @return ActionResultInterface
     */
    public function execute(
        DataObject $dto,
        TypeMetadataExtractorInterface $metadataExtractor,
        AssetQueueInterface $queue = null
    ): ActionResultInterface {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception $ex) {
            // Fail gracefully
        }

        if (null === $queue) {
            throw new LocalizedException(__('Queue object is required for this strategy.'));
        }

        $this->dto = $dto;
        $this->storeManager->setCurrentStore($queue->getStoreViewId());

        /** @var Product $product */
        try {
            $product = $this->productRepository->getByPimId($queue->getTargetEntityId());
        } catch (\Exception $e) {
            if ($this->queueImporter->isAlreadyQueued($this->createProductQueue($queue))) {
                return $this->actionResultFactory->create(['result' => ActionResultInterface::SKIPPED]);
            }

            throw new LocalizedException(
                __(
                    'Unable to import video. Related product with ID "%1" is not published yet.',
                    $queue->getTargetEntityId()
                )
            );
        }

        //Create video logic
    }

    /**
     * @param AssetQueueInterface $queue
     *
     * @return ProductQueue
     */
    protected function createProductQueue(AssetQueueInterface $queue): ProductQueue
    {
        $productQueue = $this->productQueueFactory->create();
        $productQueue->setStatus(QueueStatusInterface::PENDING);
        $productQueue->setAction($queue->getAction());
        $productQueue->setStoreViewId($queue->getStoreViewId());
        $productQueue->setProductId($queue->getTargetEntityId());

        return $productQueue;
    }
}
