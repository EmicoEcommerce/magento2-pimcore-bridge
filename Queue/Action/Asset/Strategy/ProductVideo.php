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
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\EntryFactory;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\Data\VideoContentInterface;
use Magento\Framework\Api\ImageContentValidatorInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\File\Mime;
use Magento\Framework\File\Uploader;
use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\ClientInterface;
use Magento\ProductVideo\Controller\Adminhtml\Product\Gallery\RetrieveImage;
use Magento\ProductVideo\Model\Product\Attribute\Media\ExternalVideoEntryConverter;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class ProductVideo
 */
class ProductVideo implements AssetHandlerStrategyInterface
{
    public const VIDEO_API_URL_YOUTUBE =
        'https://www.googleapis.com/youtube/v3/videos?id={videoId}&part=snippet&alt=json&key={apiKey}'
    ;

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
     * @var Curl
     */
    private $curl;

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ImageContentInterfaceFactory
     */
    private $imageContentInterfaceFactory;

    /**
     * @var ExternalVideoEntryConverter
     */
    private $externalVideoEntryConverter;

    /**
     * @var ImageContentValidatorInterface
     */
    private $contentValidator;

    /**
     * @var LoggerInterface
     */
    private $logger;

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
        ProductQueueFactory $productQueueFactory,
        CurlAdapter $curl,
        ClientInterface $httpClient,
        ScopeConfigInterface $config,
        ImageContentInterfaceFactory $imageContentInterfaceFactory,
        ExternalVideoEntryConverter $externalVideoEntryConverter,
        ImageContentValidatorInterface $contentValidator,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->state = $state;
        $this->pathResolver = $pathResolver;
        $this->storeManager = $storeManager;
        $this->actionResultFactory = $actionResultFactory;
        $this->queueImporter = $queueImporter;
        $this->productQueueFactory = $productQueueFactory;
        $this->curl = $curl;
        $this->httpClient = $httpClient;
        $this->scopeConfig = $config;
        $this->imageContentInterfaceFactory = $imageContentInterfaceFactory;
        $this->externalVideoEntryConverter = $externalVideoEntryConverter;
        $this->contentValidator = $contentValidator;
        $this->logger = $logger;
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

        if (str_contains($queue->getType(), 'youtube')) {
            return $this->createYoutubeVideoElement($queue->getValue(), $product);
        }

        if (str_contains($queue->getType(), 'youtube')) {
            return $this->createVimeoVideoElement();
        }

        return $this->actionResultFactory->create(['result' => ActionResultInterface::SKIPPED]);
    }

    /**
     * @param string $url
     * @return ActionResultInterface
     */
    private function createYoutubeVideoElement(string $url, Product $product): ActionResultInterface
    {
        try {
            $apiKey = $this->scopeConfig->getValue('catalog/product_video/youtube_api_key');

            if (empty($apiKey)) {
                $this->logger->error('No youtube api key provided');
                return $this->actionResultFactory->create(['result' => ActionResultInterface::ERROR]);
            }

            $videoId = str_replace('v=', '', strstr($url, 'v='));

            $this->httpClient
                ->get(
                    str_replace(
                        ['{videoId}', '{apiKey}'],
                        [$videoId, $apiKey],
                        self::VIDEO_API_URL_YOUTUBE
                    )
                )
            ;
        } catch (\Throwable $exception) {
            var_dump($exception->getMessage());exit;
            $this->logger->error('Could not retrieve video data from youtube api', [$exception]);
            return $this->actionResultFactory->create(['result' => ActionResultInterface::ERROR]);
        }

        $response = json_decode($this->httpClient->getBody());

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Error occurred while trying to decode google apis data from youtube');
            return $this->actionResultFactory->create(['result' => ActionResultInterface::ERROR]);
        }

        if (!isset($response->items) && empty($response->items)) {
            $this->logger->error(sprintf('No items retrieved from youtube api using video id %s', $videoId));
            return $this->actionResultFactory->create(['result' => ActionResultInterface::ERROR]);
        }

        if ($response->items[0]->id !== $videoId) {
            $this->logger->error(sprintf('Wrong video returned in youtube api for video with id %s', $videoId));
            return $this->actionResultFactory->create(['result' => ActionResultInterface::ERROR]);
        }

        $title = $response->items[0]->snippet->title;
        $description = $response->items[0]->snippet->description;
        if (isset($response->items[0]->snippet->thumbnails->standard)) {
            $image = $response->items[0]->snippet->thumbnails->standard->url;
        } elseif (isset($response->items[0]->snippet->thumbnails->high)) {
            $image = $response->items[0]->snippet->thumbnails->high->url;
        } else {
            $image = $response->items[0]->snippet->thumbnails->default->url;
        }
        $provider = 'youtube';

        $entry = $this->createVideoEntry(
            $product,
            $url,
            compact('title', 'description', 'image', 'videoId', 'provider')
        );

        $this->addVideoForProduct($product, $entry);

        return $this->actionResultFactory->create(['result' => ActionResultInterface::SUCCESS]);
    }

    /**
     * @param ProductInterface $product
     * @param ProductAttributeMediaGalleryEntryInterface $videoEntry
     *
     * @return int|null
     * @throws InputException
     * @throws StateException
     */
    private function addVideoForProduct(Product $product, ProductAttributeMediaGalleryEntryInterface $videoEntry)
    {
        /** @var $videoEntry ProductAttributeMediaGalleryEntryInterface */
        $entryContent = $videoEntry->getContent();

        if (!$this->contentValidator->isValid($entryContent)) {
            throw new InputException(__('The image content is not valid.'));
        }

        $currentMediaGalleryEntries = $product->getMediaGalleryEntries();
        if (empty($currentMediaGalleryEntries)) {
            $currentMediaGalleryEntries = [$videoEntry];
        } else {
            foreach ($currentMediaGalleryEntries as $existingMediaGalleryEntry) {
                $currentMediaGalleryEntrieIds[$existingMediaGalleryEntry->getId()] = $existingMediaGalleryEntry->getId();
            }
            $currentMediaGalleryEntries[] = $videoEntry;
        }
        $product->setMediaGalleryEntries($currentMediaGalleryEntries);

        try {
            $product = $this->productRepository->save($product);
        } catch (InputException $inputException) {
            throw $inputException;
        } catch (\Exception $e) {
            throw new StateException(__('Cannot save product.'));
        }

        foreach ($product->getMediaGalleryEntries() as $entry) {
            if (!isset($existingEntryIds[$entry->getId()])) {
                return $entry->getId();
            }
        }

        throw new StateException(__('Failed to save new media gallery entry.'));
    }

    /**
     * @param Product $product
     * @param string $videoUrl
     * @param array $videoData
     * @return ProductAttributeMediaGalleryEntryInterface
     */
    private function createVideoEntry(
        Product $product,
        string $videoUrl,
        array $videoData
    ): ProductAttributeMediaGalleryEntryInterface
    {
        $thumbnailImage = $this->getRemoteImage($videoData['image']);

        /** @var \Magento\Framework\Api\Data\ImageContentInterface $imageContent */
        $imageContent = $this->imageContentInterfaceFactory->create();
        $imageContent->setName($videoData['provider'] . '_' . $videoData['videoId'])
            ->setType('image/jpeg') //Due to time, jpg for now since all youtube video have jpg as thumbnail
            ->setBase64EncodedData(base64_encode($thumbnailImage));

        $generalMediaEntryData = [
            ProductAttributeMediaGalleryEntryInterface::LABEL => $videoData['title'],
            ProductAttributeMediaGalleryEntryInterface::TYPES => [],
            ProductAttributeMediaGalleryEntryInterface::CONTENT => $imageContent,
            ProductAttributeMediaGalleryEntryInterface::DISABLED => false
        ];

        $videoData = array_merge($generalMediaEntryData, [
            VideoContentInterface::TITLE => $videoData['title'],
            VideoContentInterface::DESCRIPTION => $videoData['description'],
            VideoContentInterface::PROVIDER => $videoData['provider'],
            VideoContentInterface::METADATA => null,
            VideoContentInterface::URL => $videoUrl,
            VideoContentInterface::TYPE => ExternalVideoEntryConverter::MEDIA_TYPE_CODE,
        ]);

        return $this->externalVideoEntryConverter->convertTo($product, $videoData);
    }

    /**
     * @param string $fileUrl
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function getRemoteImage($fileUrl)
    {
        $this->curl->setConfig(['header' => false]);
        $this->curl->write('GET', $fileUrl);
        $image = $this->curl->read();

        if (empty($image)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Could not get preview image information. Please check your connection and try again.')
            );
        }
        return $image;
    }

    /**
     * @param string $url
     * @return ActionResultInterface
     */
    private function createVimeoVideoElement(string $url): ActionResultInterface
    {
        //VIMEO not supported now
        return $this->actionResultFactory->create(['result' => ActionResultInterface::SKIPPED]);
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
