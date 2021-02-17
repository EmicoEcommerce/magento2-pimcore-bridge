<?php
/**
 * @package  Divante\PimcoreIntegration
 * @author Bartosz Herba <bherba@divante.pl>
 * @copyright 2018 Divante Sp. z o.o.
 * @license See LICENSE_DIVANTE.txt for license details.
 */

namespace Divante\PimcoreIntegration\Queue\Action\Product;

use Divante\PimcoreIntegration\Api\Pimcore\PimcoreProductInterface;
use Divante\PimcoreIntegration\Api\Queue\AssetQueueRepositoryInterface;
use Divante\PimcoreIntegration\Model\Queue\Asset\AssetQueueFactory;
use Divante\PimcoreIntegration\Queue\Action\Asset\TypeMetadataBuilderFactory;
use Divante\PimcoreIntegration\Queue\Action\Asset\TypeMetadataBuilderInterface;
use Divante\PimcoreIntegration\Queue\Importer\AbstractImporter;
use Divante\PimcoreIntegration\Queue\QueueStatusInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Divante\PimcoreIntegration\Logger\BridgeLoggerFactory;

/**
 * Class MediaGalleryDataModifier
 */
class VideoModifier implements DataModifierInterface
{
    /**
     * @var AssetQueueRepositoryInterface
     */
    private $assetQueueRepository;

    /**
     * @var AssetQueueFactory
     */
    private $assetQueueFactory;

    /**
     * @var TypeMetadataBuilderInterface
     */
    private $metadataBuilderFactory;

    /**
     * @var AbstractImporter
     */
    private $queueImporter;

    /**
     * @var \Monolog\Logger
     */
    private $logger;

    /**
     * MediaGalleryDataModifier constructor.
     *
     * @param AssetQueueRepositoryInterface $assetQueueRepository
     * @param AssetQueueFactory $assetQueueFactory
     * @param TypeMetadataBuilderFactory $metadataBuilderFactory
     * @param AbstractImporter $queueImporter
     * @param BridgeLoggerFactory $loggerFactory
     */
    public function __construct(
        AssetQueueRepositoryInterface $assetQueueRepository,
        AssetQueueFactory $assetQueueFactory,
        TypeMetadataBuilderFactory $metadataBuilderFactory,
        AbstractImporter $queueImporter,
        BridgeLoggerFactory $loggerFactory
    ) {
        $this->assetQueueRepository = $assetQueueRepository;
        $this->assetQueueFactory = $assetQueueFactory;
        $this->metadataBuilderFactory = $metadataBuilderFactory;
        $this->queueImporter = $queueImporter;
        $this->logger = $loggerFactory->getLoggerInstance();
    }

    /**
     * @param Product $product
     * @param PimcoreProductInterface $pimcoreProduct
     *
     * @return array
     */
    public function handle(Product $product, PimcoreProductInterface $pimcoreProduct): array
    {
        $mediaGalleryEntries = $product->getMediaGalleryEntries();

        $video = $pimcoreProduct->getData('video');
        $deleteVideo = false;
        if (empty($video)) {
            $deleteVideo = true;
        } else {
            try {
                $newVideoUrl = $this->getCompleteVideoUrl(
                    $pimcoreProduct->getData('video')['format'],
                    $pimcoreProduct->getData('video')['link']
                );
            } catch (LocalizedException $exception) {
                $this->logger->error('Could not process video element', [$exception]);
                return [$product, $pimcoreProduct];
            }
        }

        if (!empty($mediaGalleryEntries)) {
            foreach ($mediaGalleryEntries as $key => $mediaGalleryEntry) {
                if ($mediaGalleryEntry->getMediaType() === 'external-video') {
                    $currentVideoUrl = $mediaGalleryEntry->getExtensionAttributes()->getVideoContent()->getVideoUrl();

                    if ($deleteVideo === true) {
                        unset($mediaGalleryEntries[$key]);
                        continue;
                    }

                    if ($newVideoUrl === $currentVideoUrl) {
                        return [$product, $pimcoreProduct];
                    }
                }
            }
            $product->setMediaGalleryEntries($mediaGalleryEntries);
        }

        if ($deleteVideo === false) {
            $assetQueue = $this->assetQueueFactory->create();
            /** @var TypeMetadataBuilderInterface $metadataBuilder */
            $metadataBuilder = $this->metadataBuilderFactory->create([
                'entityType' => Product::ENTITY,
                'assetTypes' => ['video' . '_' . $pimcoreProduct->getData('video')['format']],
            ]);

            $assetQueue->setAction(AbstractImporter::ACTION_INSERT_UPDATE)
                ->setStoreViewId($product->getStoreId())
                ->setTargetEntityId($pimcoreProduct->getData('pimcore_id'))
                ->setType($metadataBuilder->getTypeMetadataString())
                ->setStatus(QueueStatusInterface::PENDING)
                ->setValue($newVideoUrl)
                ->setAssetId(0);

            if (!$this->queueImporter->isAlreadyQueued($assetQueue)) {
                $this->assetQueueRepository->save($assetQueue);
            }
        }

        return [$product, $pimcoreProduct];
    }

    /**
     * @param string $format
     * @param string $link
     * @return string
     * @throws LocalizedException
     */
    private function getCompleteVideoUrl(string $format, string $link): string
    {
        switch ($format) {
            case 'youtube':
                return 'https://youtube.com/watch?v=' . $link;
            case 'vimeo':
                return 'https://vimeo.com/' . $link;
            default:
                throw new LocalizedException(__('Only youtube and vimeo are supported'));
        }
    }

}
