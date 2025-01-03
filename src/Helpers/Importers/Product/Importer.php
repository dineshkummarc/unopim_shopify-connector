<?php

namespace Webkul\Shopify\Helpers\Importers\Product;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Filesystem\FileStorer;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Importers\FieldProcessor;
use Webkul\DataTransfer\Helpers\Importers\Product\SKUStorage;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Traits\DataMappingTrait;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\ValidatedBatched;

class Importer extends AbstractImporter
{
    use DataMappingTrait;
    use ShopifyGraphqlRequest;
    use ValidatedBatched;

    public const BATCH_SIZE = 100;

    public const UNOPIM_ENTITY_NAME = 'product';

    /**
     * Cached attribute families
     */
    protected mixed $attributeFamilies = [];

    /**
     * Cached attributes
     */
    protected mixed $attributes = [];

    /**
     * Cached categories
     */
    protected array $categories = [];

    /**
     * All channels selected currency codes
     */
    protected array $currencies = [];

    /**
     * Channel code as key and locale codes in array as value
     */
    protected array $channelsAndLocales = [];

    /**
     * Shopify credential.
     *
     * @var mixed
     */
    protected $credential;

    /**
     * job locale
     */
    private $locale;

    /**
     * job status
     */
    private $update = false;

    private $updateVariant;

    /**
     * job channel code
     */
    private $channel;

    /**
     * job currency code
     */
    private $currency;

    protected $importMapping;

    protected $defintiionMapping;

    /**
     * Shopify credential as array for api request.
     *
     * @var mixed
     */
    protected $credentialArray;

    protected $exportMapping;

    /**
     * Valid csv columns
     */
    protected array $validColumnNames = [
        'locale',
        'channel',
        'type',
        'attribute_family',
        'parent',
        'categories',
        'related_products',
        'cross_sells',
        'up_sells',
        'configurable_attributes',
        'associated_skus',
    ];

    protected $productIndexes = ['title', 'handle', 'vendor', 'descriptionHtml', 'productType', 'tags'];

    protected $seoFields = ['metafields_global_title_tag', 'metafields_global_description_tag'];

    protected $variantIndexes = ['inventoryPolicy', 'barcode', 'taxable', 'compareAtPrice', 'sku', 'inventoryTracked', 'cost', 'weight', 'price', 'inventoryQuantity'];

    /**
     * Create a new helper instance.
     *
     * @return void
     */
    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository,
        protected AttributeRepository $attributeRepository,
        protected ProductRepository $productRepository,
        protected SKUStorage $skuStorage,
        protected ChannelRepository $channelRepository,
        protected FieldProcessor $fieldProcessor,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyExportMappingRepository $shopifyExportmapping,
        protected FileStorer $fileStorer,
        protected CategoryRepository $categoryRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
    ) {
        parent::__construct($importBatchRepository);

        $this->initAttributes();
    }

    /**
     * Load all attributes and families to use later
     */
    protected function initAttributes(): void
    {
        $this->attributeFamilies = $this->attributeFamilyRepository->all();

        $this->attributes = $this->attributeRepository->all();

        $this->importMapping = $this->shopifyExportmapping->find(3);

        $this->initializeChannels();

        foreach ($this->attributes as $key => $attribute) {
            if ($attribute->type === 'price') {
                $this->addPriceAttributesColumns($attribute->code);

                continue;
            }

            $this->validColumnNames[] = $attribute->code;
        }
    }

    /**
     * initialize channels, locales and currecies value
     */
    protected function initializeChannels(): void
    {
        $this->exportMapping = $this->shopifyExportmapping->find(3);

        $channels = $this->channelRepository->all();

        foreach ($channels as $channel) {
            $this->channelsAndLocales[$channel->code] = $channel->locales?->pluck('code')?->toArray() ?? [];

            $this->currencies = array_merge($this->currencies, $channel->currencies?->pluck('code')?->toArray() ?? []);
        }
    }

    /**
     * Add valid column names for the price attribute according to currencies
     */
    public function addPriceAttributesColumns(string $attributeCode): void
    {
        foreach ($this->currencies as $currency) {
            $this->validColumnNames[] = $this->getPriceTypeColumnName($attributeCode, $currency);
        }
    }

    /**
     * Get formatted price column name
     */
    protected function getPriceTypeColumnName(string $attributeCode, string $currency): string
    {
        return "{$attributeCode} ({$currency})";
    }

    public function validateData(): void
    {
        $this->saveValidatedBatches();
    }

    /**
     * Initialize Filters
     */
    protected function initFilters(): void
    {
        $filters = $this->import->jobInstance->filters;

        $this->credential = $this->shopifyRepository->find($filters['credentials'] ?? null);
        if (! $this->credential?->active) {
            throw new \InvalidArgumentException('Invalid Credential: The credential is either disabled, incorrect, or does not exist');
        }
        $this->locale = $filters['locale'] ?? null;

        $this->channel = $filters['channel'] ?? null;

        $this->currency = $filters['currency'] ?? null;

        $this->defintiionMapping = array_merge(array_keys($this->credential?->extras['productMetafield'] ?? []), array_keys($this->credential?->extras['productVariantMetafield'] ?? []));
    }

    /**
     * Import instance.
     *
     * @return \Webkul\DataTransfer\Helpers\Source
     */
    public function getSource()
    {
        $this->initFilters();
        if (! $this->credential?->active) {
            throw new \InvalidArgumentException('Disabled Shopify credential');
        }

        $this->credentialArray = [
            'shopUrl'     => $this->credential?->shopUrl,
            'accessToken' => $this->credential?->accessToken,
            'apiVersion'  => $this->credential?->apiVersion,
        ];

        $products = new \ArrayIterator($this->getProductsByCursor());

        return $products;
    }

    /**
     * Get product data from shopify API
     */
    public function getProductsByCursor(): array
    {
        $cursor = null;
        $allProducts = [];
        do {
            $mutationType = 'productAllvalueGetting';
            $variable = [];
            if ($cursor) {
                $variable = [
                    'first'       => 10,
                    'afterCursor' => $cursor,
                ];
                $mutationType = 'productAllvalueGettingByCursor';
            }
            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credentialArray, $variable);

            $graphqlProducts = ! empty($graphResponse['body']['data']['products']['edges'])
                ? $graphResponse['body']['data']['products']['edges']
                : [];
            $allProducts = array_merge($allProducts, $graphqlProducts);

            $lastCursor = ! empty($graphqlProducts) ? end($graphqlProducts)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }

            $cursor = $lastCursor;
        } while (! empty($graphqlProducts));

        return $allProducts;
    }

    /**
     * Validates row
     */
    public function validateRow(array $rowData, int $rowNumber): bool
    {
        return true;
    }

    /**
     * Start the import process for Category Import
     */
    public function importBatch(JobTrackBatchContract $batch): bool
    {
        $this->saveProductsData($batch);

        return true;
    }

    /**
     * Save products from current batch
     */
    protected function saveProductsData(JobTrackBatchContract $batch): bool
    {
        $this->initFilters();
        foreach ($batch->data as $rowData) {
            $unopimCategory = $this->getCollectionFromShopify($rowData['node']['collections']['edges'] ?? []);
            $productImages = $rowData['node']['images']['edges'];
            $productMedias = $rowData['node']['media']['nodes'];

            $imageMediaids = array_column(array_filter($productMedias, fn ($item) => $item['__typename'] === 'MediaImage'), 'id') ?? [];

            $image = [];
            $image = array_map(fn ($productImage) => $this->imageStorer($productImage['node']['originalSrc']), $productImages);
            $variants = $rowData['node']['variants']['edges'];

            $count = 0;
            $count = count(array_filter($rowData['node']['options'], fn ($option) => $option['name'] !== 'Title' || ! in_array('Default Title', $option['values'])));

            $mappingAttr = $this->importMapping->mapping['shopify_connector_settings'] ?? [];
            $simpleProductFamilyId = $mappingAttr['family_variant'] ?? null;

            if (! $simpleProductFamilyId) {
                continue;
            }

            $metaFieldAllAttr = $this->defintiionMapping ?? [];

            unset($mappingAttr['family_variant']);
            $variantImageAttr = $mappingAttr['variantimages'] ?? null;
            $extractProductAttr = array_intersect_key($mappingAttr, array_flip($this->productIndexes));
            $extractSeoAttr = array_intersect_key($mappingAttr, array_flip($this->seoFields));
            $extractVariantAttr = array_intersect_key($mappingAttr, array_flip($this->variantIndexes));
            $common = [];
            $channel_specific = [];
            $locale_specific = [];
            $channelAndLocaleSpecific = [];
            $productModelattribiteUnopim = $this->mapAttributes($extractProductAttr, $rowData, false);

            $seoAttrUnopim = $this->mapAttributes($extractSeoAttr, $rowData, true);

            if (! $productModelattribiteUnopim || ! $seoAttrUnopim) {
                continue;
            }

            [$pcommon, $plocale_specific, $pchannel_specific, $pchannelAndLocaleSpecific] = $productModelattribiteUnopim;

            [$scommon, $slocale_specific, $schannel_specific, $schannelAndLocaleSpecific] = $seoAttrUnopim;

            [$mdcommon, $mdlocale_specific, $mdchannel_specific, $mdchannelAndLocaleSpecific] = $this->mapMetafieldsAttribute($rowData['node']['metafields']['edges'] ?? [], $metaFieldAllAttr);

            $common = array_merge($pcommon, $scommon, $mdcommon);
            $common['status'] = $rowData['node']['status'] == 'ACTIVE' ? 'true' : 'false';
            $locale_specific = array_merge($plocale_specific, $slocale_specific, $mdlocale_specific);
            $channel_specific = array_merge($pchannel_specific, $schannel_specific, $mdchannel_specific);
            $channelAndLocaleSpecific = array_merge($pchannelAndLocaleSpecific, $schannelAndLocaleSpecific, $mdchannelAndLocaleSpecific);
            $imagre = $mappingAttr['images'] ?? null;
            $allMappedImageAttrs = explode(',', $imagre);
            if ($count > 0) {
                $parentData = $this->processConfigurableProduct(
                    $rowData,
                    $simpleProductFamilyId,
                    $unopimCategory,
                    $variants,
                    $image,
                    $imageMediaids,
                    $common,
                    $locale_specific,
                    $channel_specific,
                    $channelAndLocaleSpecific,
                    $allMappedImageAttrs,
                    $variantImageAttr,
                    $extractVariantAttr,
                    $metaFieldAllAttr
                );
                if (! $parentData) {
                    continue;
                }

            } else {
                $childData = $this->processSimpleProduct(
                    $rowData,
                    $simpleProductFamilyId,
                    $unopimCategory,
                    $variants,
                    $image,
                    $imageMediaids,
                    $common,
                    $locale_specific,
                    $channel_specific,
                    $channelAndLocaleSpecific,
                    $allMappedImageAttrs,
                    $extractVariantAttr,
                    $mdcommon,
                    $mdchannel_specific,
                    $mdlocale_specific,
                    $mdchannelAndLocaleSpecific
                );

                if (! $childData) {
                    continue;
                }
            }

            if ($this->update) {
                $this->updatedItemsCount++;
            } else {
                $this->createdItemsCount++;
            }
        }

        $this->updateBatchtate($batch);

        return true;
    }

    /**
     * process configurable product
     */
    public function processConfigurableProduct(
        $rowData,
        $simpleProductFamilyId,
        $unopimCategory,
        $variants,
        $image,
        $imageMediaids,
        $common,
        $locale_specific,
        $channel_specific,
        $channelAndLocaleSpecific,
        $allMappedImageAttrs,
        $variantImageAttr,
        $extractVariantAttr,
        $metaFieldAllAttr
    ) {
        $attributes = [];
        $storeForVariant = [];
        $attributes = $this->validateAttributes($rowData['node']['options']);
        if ($attributes === null) {
            return null;
        }
        $family_code = $simpleProductFamilyId;

        $familyModel = $this->attributeFamilyRepository->where('id', $family_code)->first();

        if (! $familyModel) {
            $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.family-not-exist', ['title' => $rowData['node']['title']]));

            return null;
        }

        $configurableAttributes = [];

        foreach ($familyModel?->getConfigurableAttributes() ?? [] as $attribute) {
            $configurableAttributes[] = [
                'code' => $attribute->code,
                'name' => $attribute->name,
                'id'   => $attribute->id,
            ];
        }

        if (empty($configurableAttributes)) {
            return null;
        }

        $shopifyProductId = $rowData['node']['id'];
        $configProductMapping = $this->checkMappingInDb(['code' => $rowData['node']['handle']]);
        $configId = $this->processConfigurableProductData(
            $rowData,
            $familyModel,
            $attributes,
            $common,
            $locale_specific,
            $channel_specific,
            $channelAndLocaleSpecific,
            $allMappedImageAttrs,
            $image,
            $imageMediaids
        );
        if ($configId === null) {
            return null;
        }

        if (! $configProductMapping) {
            $this->parentMapping($rowData['node']['handle'], $shopifyProductId, $this->import->id);
        }

        $mappedImageAttr = $this->processMappedImages($allMappedImageAttrs, $image, $configId, $storeForVariant, $rowData['node']['title'] ?? '', $imageMediaids);

        if (! $mappedImageAttr) {
            return null;
        }

        [$mcommon, $mlocale_specific, $mchannel_specific, $mchannelAndLocaleSpecific] = $mappedImageAttr;

        $variantProductData = $this->processVariants(
            $variants,
            $rowData,
            $shopifyProductId,
            $configId,
            $storeForVariant,
            $variantImageAttr,
            $extractVariantAttr,
            $allMappedImageAttrs,
            $metaFieldAllAttr
        );

        $dataToUpdate = [
            'sku'     => $rowData['node']['handle'],
            'status'  => $rowData['node']['status'] == 'ACTIVE' ? 1 : 0,
            'channel' => $this->channel,
            'locale'  => $this->locale,
            'values'  => [
                'common'           => array_merge($common, $mcommon),
                'channel_specific' => [
                    $this->channel => array_merge($channel_specific, $mchannel_specific),
                ],

                'locale_specific'  => [
                    $this->locale => array_merge($locale_specific, $mlocale_specific),
                ],

                'channel_locale_specific' => [
                    $this->channel => [
                        $this->locale => array_merge($channelAndLocaleSpecific, $mchannelAndLocaleSpecific),
                    ],
                ],
            ],
            'variants'   => $variantProductData,
            'categories' => $unopimCategory,
        ];
        $product = $this->productRepository->update($dataToUpdate, $configId);
        $allVariant = $product->variants?->toArray();
        $ids = array_column($allVariant, 'id');
        $skus = array_column($allVariant, 'sku');
        $formattedArray = array_combine($skus, $ids);

        $variantProductData = array_values($variantProductData);
        foreach ($product->variants->toArray() as $key => $svariant) {
            $variantData = null;
            if (isset($variantProductData[$key])) {
                $variantData = $variantProductData[$key];
            } elseif (isset($variantProductData[$svariant['id']])) {
                $variantData = $variantProductData[$svariant['id']];
            } else {
                continue;
            }

            $product = $this->productRepository->update($variantData, $formattedArray[$variantData['sku']]);
        }

        return true;
    }

    private function processVariants(
        array $variants,
        array $rowData,
        string $shopifyProductId,
        int $configId,
        array $storeForVariant,
        ?string $variantImageAttr,
        array $extractVariantAttr,
        array $allMappedImageAttrs,
        array $metaFieldAllAttr,
    ) {
        $variantSkus = [];
        $variantProductData = [];
        $mcommon = [];
        $mlocale_specific = [];
        $mchannel_specific = [];
        $mchannelAndLocaleSpecific = [];
        foreach ($variants as $key => $productVariant) {
            $vsku = $productVariant['node']['sku'] ?? null;
            if (empty($vsku)) {

                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.variant-sku-not-exist', ['id' => $shopifyProductId]));

                continue;
            }
            $vsku = str_replace(["\r", "\n"], '', $vsku);
            if (in_array($vsku, $variantSkus)) {

                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.duplicate-sku', ['sku' => $vsku]));

                continue;
            }
            $variantSkus[] = $vsku;
            $variantMapping = $this->checkMappingInDb(['code' => $vsku]);
            if (! $variantMapping) {
                $this->parentMapping($vsku, $productVariant['node']['id'], $this->import->id, $shopifyProductId);
            }

            $variantProductExist = $this->productRepository->findOneByField('sku', $vsku);
            $imageValue = null;
            if (! empty($productVariant['node']['image'])) {
                $imageUrl = $productVariant['node']['image']['originalSrc'];
                $fileName = explode('/', $imageUrl);
                $fileName = end($fileName);
                $fileName = explode('?', $fileName)[0];
                $imageValue = $storeForVariant[$fileName] ?? null;
                $tempFile = '/tmp/tmpstorage/'.$fileName;
                $variantImage = $variantProductExist->id ?? $configId;
                if (! $imageValue && $variantImageAttr) {
                    $imageValue = $this->fileStorer->store(
                        path: 'product'.DIRECTORY_SEPARATOR.$variantImage.DIRECTORY_SEPARATOR.$variantImageAttr,
                        file: new UploadedFile($tempFile, $fileName),
                        options: [FileStorer::HASHED_FOLDER_NAME_KEY => true]
                    );
                }
            }

            $variantProductValue = $this->formatVariantData($productVariant, $extractVariantAttr);

            if (! $variantProductValue) {
                continue;
            }

            [$vcommon, $vlocale_specific, $vchannel_specific, $vchannelAndLocaleSpecific] = $variantProductValue;

            foreach ($allMappedImageAttrs as $vimage) {
                $vcommon[$vimage] = isset($mcommon[$vimage]) ? '' : ($vlocale_specific[$vimage] ?? null);
                $vlocale_specific[$vimage] = isset($mlocale_specific[$vimage]) ? '' : ($vlocale_specific[$vimage] ?? null);
                $vchannel_specific[$vimage] = isset($mchannel_specific[$vimage]) ? '' : ($vchannel_specific[$vimage] ?? null);
                $vchannelAndLocaleSpecific[$vimage] = isset($mchannelAndLocaleSpecific[$vimage]) ? '' : ($vchannelAndLocaleSpecific[$vimage] ?? null);
            }

            if ($variantImageAttr) {
                $vImageAttribute = $this->attributeRepository->findOneByField('code', $variantImageAttr);
                if ($vImageAttribute->is_required && empty($imageValue)) {

                    $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.required-field', ['sku' => $vsku, 'attribute' => $variantImageAttr]));

                    continue;
                }
                if (! $vImageAttribute?->value_per_locale && ! $vImageAttribute?->value_per_channel) {
                    $vcommon[$variantImageAttr] = $imageValue;
                }

                if ($vImageAttribute?->value_per_locale && ! $vImageAttribute?->value_per_channel) {
                    $vlocale_specific[$variantImageAttr] = $imageValue;
                }

                if (! $vImageAttribute?->value_per_locale && $vImageAttribute?->value_per_channel) {
                    $vchannel_specific[$variantImageAttr] = $imageValue;
                }

                if ($vImageAttribute?->value_per_locale && $vImageAttribute?->value_per_channel) {
                    $vchannelAndLocaleSpecific[$variantImageAttr] = $imageValue;
                }
            }

            [$vMdcommon, $vMdlocale_specific, $vMdchannel_specific, $vMdchannelAndLocaleSpecific] = $this->mapMetafieldsAttribute($productVariant['node']['metafields']['edges'] ?? [], $metaFieldAllAttr);
            $vkey = $variantProductExist ? $variantProductExist->id : 'variant_'.$key;

            if ($this->updateVariant) {
                $this->updatedItemsCount++;
            } else {
                $this->createdItemsCount++;
            }
            $variantProductData[$vkey] = [
                'sku'    => $vsku ?? '',
                'status' => $rowData['node']['status'] == 'ACTIVE' ? 1 : 0,
                'values' => [
                    'common'           => array_merge($vcommon, $vMdcommon),
                    'channel_specific' => [
                        $this->channel => array_merge($vchannel_specific, $vMdchannel_specific),
                    ],
                    'locale_specific'  => [
                        $this->locale => array_merge($vlocale_specific, $vMdlocale_specific),
                    ],
                    'channel_locale_specific' => [
                        $this->channel => [
                            $this->locale => array_merge($vchannelAndLocaleSpecific, $vMdchannelAndLocaleSpecific),
                        ],
                    ],
                ],
            ];
        }

        return $variantProductData;
    }

    private function processConfigurableProductData($rowData, $familyModel, $attributes)
    {
        $configProductExist = $this->productRepository->findOneByField('sku', $rowData['node']['handle']);
        $configId = $configProductExist?->id;
        $this->update = true;

        $this->updateVariant = true;
        if (! $configProductExist) {

            if (! $familyModel) {
                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.family-not-mapping', ['title' => $rowData['node']['title']]));

                return null;
            }

            $this->updateVariant = false;
            $data[$rowData['node']['handle']] = [
                'type'                => 'configurable',
                'sku'                 => $rowData['node']['handle'],
                'status'              => $rowData['node']['status'] == 'ACTIVE' ? 1 : 0,
                'attribute_family_id' => $familyModel->id,
                'super_attributes'    => $attributes,
            ];

            $createdConfigProduct = $this->productRepository->create($data[$rowData['node']['handle']]);
            $configId = $createdConfigProduct->id;
            $this->update = false;
        }

        return $configId;
    }

    /**
     * check attributes exist in unopim
     */
    private function validateAttributes($options)
    {
        $attributes = [];
        $attrNotExist = [];

        foreach ($options as $attr) {
            $attrCode = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($attr['name']));
            $vAttribute = $this->attributeRepository->findOneByField('code', $attrCode);

            if ($vAttribute) {
                $attributes[$attrCode] = $attrCode;
            } else {
                $attrNotExist[] = $attrCode;
            }
        }

        if (! empty($attrNotExist)) {
            $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.attribute-not-exist', ['attributes' => json_encode($attrNotExist)]));

            return null;
        }

        return $attributes;
    }

    /**
     * process simple product
     */
    public function processSimpleProduct(
        $rowData,
        $simpleProductFamilyId,
        $unopimCategory,
        $variants,
        $image,
        $imageMediaids,
        $common,
        $locale_specific,
        $channel_specific,
        $channelAndLocaleSpecific,
        $allMappedImageAttrs,
        $extractVariantAttr,
        $mdcommon,
        $mdchannel_specific,
        $mdlocale_specific,
        $mdchannelAndLocaleSpecific
    ) {
        $shopifyProductId = $rowData['node']['id'];
        $storeForVariant = [];
        foreach ($variants as $key => $productVariant) {
            $variantData = $this->formatVariantData($productVariant, $extractVariantAttr);
            if (empty($productVariant['node']['sku'])) {
                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.not-found-sku', ['id' => $shopifyProductId]));

                continue;
            }
        }

        if (! $variantData) {
            return false;
        }

        [$vcommon, $vlocale_specific, $vchannel_specific, $vchannelAndLocaleSpecific] = $variantData;

        if (empty($vcommon['sku'])) {
            $vcommon['sku'] = $rowData['node']['handle'];
        }

        $productExist = $this->productRepository->findOneByField('sku', $vcommon['sku']);

        $simpleId = $productExist?->id;
        $this->update = true;
        $variantSku = $productVariant['node']['sku'] ?? $rowData['node']['handle'];
        $simpleProductMapping = $this->checkMappingInDb(['code' => $variantSku]);

        if (! $simpleProductMapping) {
            $this->parentMapping($variantSku, $shopifyProductId, $this->import->id);
        }

        if (! $productExist) {
            $familyModel = $this->attributeFamilyRepository->where('id', $simpleProductFamilyId)->first();

            if (! $familyModel) {
                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.family-not-mapping', ['title' => $rowData['node']['title']]));

                return false;
            }

            $data[$vcommon['sku']] = [
                'type'                => 'simple',
                'sku'                 => $vcommon['sku'],
                'status'              => $rowData['node']['status'] == 'ACTIVE' ? 1 : 0,
                'attribute_family_id' => $simpleProductFamilyId,
            ];
            $this->update = false;

            $createdProduct = $this->productRepository->create($data[$vcommon['sku']]);
            $simpleId = $createdProduct->id;
        }

        $mappedImageAttr = $this->processMappedImages($allMappedImageAttrs, $image, $simpleId, $storeForVariant, $rowData['node']['title'] ?? '', $imageMediaids);

        if (! $mappedImageAttr) {
            return false;
        }

        [$mcommon, $mlocale_specific, $mchannel_specific, $mchannelAndLocaleSpecific] = $mappedImageAttr;

        $dataToUpdate = [
            'sku'     => $vcommon['sku'],
            'channel' => $this->channel,
            'status'  => $rowData['node']['status'] == 'ACTIVE' ? 1 : 0,
            'locale'  => $this->locale,
            'values'  => [
                'common'           => array_merge($common, $vcommon, $mcommon, $mdcommon),
                'channel_specific' => [
                    $this->channel => array_merge($channel_specific, $vchannel_specific, $mchannel_specific, $mdchannel_specific),
                ],
                'locale_specific'  => [
                    $this->locale => array_merge($locale_specific, $vlocale_specific, $mlocale_specific, $mdlocale_specific),
                ],
                'channel_locale_specific' => [
                    $this->channel => [
                        $this->locale => array_merge($channelAndLocaleSpecific, $vchannelAndLocaleSpecific, $mchannelAndLocaleSpecific, $mdchannelAndLocaleSpecific),
                    ],
                ],
            ],
            'categories' => $unopimCategory,
        ];

        $product = $this->productRepository->update($dataToUpdate, $simpleId);

        return $product;
    }

    public function mapMetafieldsAttribute($shopifyMetaFiled, $metaFieldAllAttr): array
    {
        $common = [];
        $locale_specific = [];
        $channel_specific = [];
        $channelAndLocaleSpecific = [];
        foreach ($shopifyMetaFiled ?? [] as $metaData) {
            if (! in_array($metaData['node']['key'], $metaFieldAllAttr)) {
                continue;
            }
            $unoAttr = $metaData['node']['key'];
            $source = $metaData['node']['value'];
            $attribute = $this->attributeRepository->findOneByField('code', $metaData['node']['key']);
            if (! $attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $common[$unoAttr] = $source;
            }

            if ($attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $locale_specific[$unoAttr] = $source;
            }

            if (! $attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channel_specific[$unoAttr] = $source;
            }

            if ($attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channelAndLocaleSpecific[$unoAttr] = $source;
            }
        }

        return [
            $common,
            $locale_specific,
            $channel_specific,
            $channelAndLocaleSpecific,
        ];
    }

    public function updateBatchtate(JobTrackBatchContract $batch): void
    {
        $this->importBatchRepository->update([
            'state'   => Import::STATE_PROCESSED,
            'summary' => [
                'created' => $this->getCreatedItemsCount(),
                'updated' => $this->getUpdatedItemsCount(),
            ],
        ], $batch->id);
    }

    /*
    * Get collection code from shopify
    *
    */
    public function getCollectionFromShopify(array $collections): array
    {
        $collectionCode = [];

        foreach ($collections as $collection) {
            $categoryExist = $this->categoryRepository->where('code', $collection['node']['handle'])->first();
            if (! $categoryExist) {
                continue;
            }

            $collectionCode[] = $categoryExist?->code;
        }

        return $collectionCode;
    }

    /*
    * process image attributes
    */
    public function processMappedImages(array $allMappedImageAttrs, array $image, string $configId, array &$storeForVariant, string $title = '', $imageMediaids = []): ?array
    {
        $common = [];
        $locale_specific = [];
        $channel_specific = [];
        $channelAndLocaleSpecific = [];
        foreach ($allMappedImageAttrs as $index => $mappedImageAttr) {
            $imgStore = '';
            $attribute = $this->attributeRepository->findOneByField('code', $mappedImageAttr);
            if (! empty($image[$index])) {
                if ($attribute?->is_required && empty($image[$index])) {
                    $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.required-field', ['sku' => $title, 'attribute' => $mappedImageAttr]));

                    return null;
                }

                $fileName = explode('/', $image[$index]);

                $imgStore = $storeForVariant[end($fileName)] = $this->fileStorer->store(
                    path: 'product'.DIRECTORY_SEPARATOR.$configId.DIRECTORY_SEPARATOR.$mappedImageAttr,
                    file: new UploadedFile($image[$index], end($fileName)),
                    options: [FileStorer::HASHED_FOLDER_NAME_KEY => true]
                );
            }

            if (! $attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $common[$mappedImageAttr] = $imgStore;
            }

            if ($attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $locale_specific[$mappedImageAttr] = $imgStore;
            }

            if (! $attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channel_specific[$mappedImageAttr] = $imgStore;
            }

            if ($attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channelAndLocaleSpecific[$mappedImageAttr] = $imgStore;
            }
        }

        return [
            $common,
            $locale_specific,
            $channel_specific,
            $channelAndLocaleSpecific,
        ];
    }

    /**
     * Image Store
     */
    public function imageStorer(string $imageUrl): string
    {
        $fileName = explode('/', $imageUrl);
        $fileName = end($fileName);
        $fileName = explode('?', $fileName)[0];
        $localpath = '/tmp'.'/tmpstorage/'.$fileName;
        if (! file_exists(dirname($localpath))) {
            mkdir(dirname($localpath), 0777, true);
        }

        if (! is_writable(dirname($localpath))) {
            throw new \Exception(sprintf('%s must writable !!! ', dirname($localpath)));
        }

        $check = file_put_contents($localpath, $this->grabImage($imageUrl));

        return $localpath;
    }

    public function grabImage($url)
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.1 Safari/537.11',
        ])->withoutVerifying()
            ->timeout(30)
            ->retry(3, 1000) // Retry up to 3 times with 1-second intervals
            ->get($url);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    /**
     * Mapped attribute data for seo and product
     */
    public function mapAttributes($attributes, $rowData, $isSeo = false): ?array
    {
        $common = [];
        $locale_specific = [];
        $channel_specific = [];
        $channelAndLocaleSpecific = [];

        foreach ($attributes as $shopifyAttr => $unoAttr) {
            $attribute = $this->attributeRepository->findOneByField('code', $unoAttr);

            if ($isSeo) {
                if ($shopifyAttr == 'metafields_global_title_tag') {
                    $shopifyAttr = 'title';
                }

                if ($shopifyAttr == 'metafields_global_description_tag') {
                    $shopifyAttr = 'description';
                }
            }

            $source = $isSeo ? $rowData['node']['seo'][$shopifyAttr] : $rowData['node'][$shopifyAttr];

            if ($shopifyAttr == 'tags') {
                $source = implode(',', $source);
            }
            if ($attribute->is_required && empty($source)) {
                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.required-field', ['sku' => $rowData['node']['title'], 'attribute' => $unoAttr]));

                return null;
            }

            if (! $attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $common[$unoAttr] = $source;
            }

            if ($attribute?->value_per_locale && ! $attribute?->value_per_channel) {
                $locale_specific[$unoAttr] = $source;
            }

            if (! $attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channel_specific[$unoAttr] = $source;
            }

            if ($attribute?->value_per_locale && $attribute?->value_per_channel) {
                $channelAndLocaleSpecific[$unoAttr] = $source;
            }
        }

        return [
            $common,
            $locale_specific,
            $channel_specific,
            $channelAndLocaleSpecific,
        ];
    }

    /**
     * Variant Data formater
     */
    public function formatVariantData($variantData, $extractVariantAttr): ?array
    {
        // Initialize arrays to store different types of attributes
        $Opcommon = $Oplocale_specific = $Opchannel_specific = $OpchannelAndLocaleSpecific = [];
        $vcommon = $vlocale_specific = $vchannel_specific = $vchannelAndLocaleSpecific = [];

        // Helper function to classify attributes
        $classifyAttribute = function ($attribute, $name, $value, &$common, &$locale_specific, &$channel_specific, &$channelAndLocaleSpecific) {
            match (true) {
                ! $attribute?->value_per_locale && ! $attribute?->value_per_channel => $common[$name] = $value,
                $attribute?->value_per_locale && ! $attribute?->value_per_channel   => $locale_specific[$name] = $value,
                ! $attribute?->value_per_locale && $attribute?->value_per_channel   => $channel_specific[$name] = $value,
                $attribute?->value_per_locale && $attribute?->value_per_channel     => $channelAndLocaleSpecific[$name] = $value,
            };
        };

        // Process selected options
        foreach ($variantData['node']['selectedOptions'] ?? [] as $option) {
            if ($option['name'] == 'Title' && $option['value'] == 'Default Title') {
                continue;
            }

            $name = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($option['name']));
            $attribute = $this->attributeRepository->findOneByField('code', $name);

            $optionvalue = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $option['value']), '-');
            $optionForShopify = $attribute->options()->where('code', $optionvalue)?->get()?->first();

            if (! $optionForShopify) {
                $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.option-not-found', ['sku' => $variantData['node']['sku'], 'attribute' => $option['name'], 'option' => $option['value']]));

                return null;
            }

            $classifyAttribute($attribute, $name, $optionForShopify?->code, $Opcommon, $Oplocale_specific, $Opchannel_specific, $OpchannelAndLocaleSpecific);
        }

        // Process extracted variant attributes
        foreach ($extractVariantAttr as $shopifyAttr => $unoAttr) {
            $value = null;
            $attribute = $this->attributeRepository->findOneByField('code', $unoAttr);

            switch ($shopifyAttr) {
                case 'cost':
                    $costPerItem = $variantData['node']['inventoryItem']['unitCost'];
                    $value[$this->currency] = $costPerItem ? (string) $costPerItem['amount'] : '0';
                    break;

                case 'price':
                    $value[$this->currency] = (string) ($variantData['node']['price'] ?? '0');
                    break;

                case 'weight':
                    $value = (string) ($variantData['node']['inventoryItem']['measurement']['weight']['value'] ?? '0');
                    break;

                case 'barcode':
                    $value = $variantData['node']['barcode'] ?? '';
                    if ($attribute->is_required && empty($value)) {
                        $this->jobLogger->warning(trans('shopify::app.shopify.import.job.product.required-field', ['sku' => $variantData['node']['sku'], 'attribute' => $unoAttr]));

                        return null;
                    }

                    break;

                case 'taxable':
                    $value = $variantData['node']['taxable'] ? 'true' : 'false';
                    break;

                case 'compareAtPrice':
                    $value[$this->currency] = (string) ($variantData['node']['compareAtPrice'] ?? '0');
                    break;

                case 'inventoryPolicy':
                    $value = $variantData['node']['inventoryPolicy'] == 'CONTINUE' ? 'true' : 'false';
                    break;

                case 'inventoryTracked':
                    $value = $variantData['node']['inventoryItem']['tracked'] ? 'true' : 'false';
                    break;

                case 'inventoryQuantity':
                    $value = $variantData['node']['inventoryQuantity'];
                    break;
            }

            $classifyAttribute($attribute, $unoAttr, $value, $vcommon, $vlocale_specific, $vchannel_specific, $vchannelAndLocaleSpecific);
        }

        $vcommon['sku'] = str_replace(["\r", "\n"], '', $variantData['node']['sku']);

        return [
            array_merge($vcommon, $Opcommon),
            array_merge($vlocale_specific, $Oplocale_specific),
            array_merge($vchannel_specific, $Opchannel_specific),
            array_merge($vchannelAndLocaleSpecific, $OpchannelAndLocaleSpecific),
        ];
    }

    /**
     * Check if SKU exists
     */
    public function isSKUExist(string $sku): bool
    {
        return $this->skuStorage->has($sku);
    }
}
