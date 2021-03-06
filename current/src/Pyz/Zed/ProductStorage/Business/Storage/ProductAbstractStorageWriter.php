<?php

/**
 * This file is part of the Spryker Suite.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Pyz\Zed\ProductStorage\Business\Storage;

use Generated\Shared\Transfer\ProductAbstractStorageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;
use Generated\Shared\Transfer\SynchronizationDataTransfer;
use Propel\Runtime\Propel;
use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Service\Synchronization\SynchronizationServiceInterface;
use Spryker\Zed\ProductStorage\Business\Attribute\AttributeMapInterface;
use Spryker\Zed\ProductStorage\Business\Storage\ProductAbstractStorageWriter as SprykerProductAbstractStorageWriter;
use Spryker\Zed\ProductStorage\Dependency\Facade\ProductStorageToProductInterface;
use Spryker\Zed\ProductStorage\Dependency\Facade\ProductStorageToStoreFacadeInterface;
use Spryker\Zed\ProductStorage\Persistence\ProductStorageQueryContainerInterface;

/**
 * @example
 *
 * This is an example of running ProductAbstractStorageWriter
 * with CTE (@see https://www.postgresql.org/docs/9.1/queries-with.html).
 * By using this class, reduce the amount of database queries and increase the performance
 * for saving storage data in database.
 */
class ProductAbstractStorageWriter extends SprykerProductAbstractStorageWriter
{
    /**
     * @var \Spryker\Service\Synchronization\SynchronizationServiceInterface
     */
    protected $synchronizationService;

    /**
     * @var \Spryker\Client\Queue\QueueClientInterface
     */
    protected $queueClient;

    /**
     * @var array
     */
    protected $synchronizedDataCollection = [];

    /**
     * @var array
     */
    protected $synchronizedMessageCollection = [];

    /**
     * @param \Spryker\Zed\ProductStorage\Dependency\Facade\ProductStorageToProductInterface $productFacade
     * @param \Spryker\Zed\ProductStorage\Business\Attribute\AttributeMapInterface $attributeMap
     * @param \Spryker\Zed\ProductStorage\Persistence\ProductStorageQueryContainerInterface $queryContainer
     * @param \Spryker\Zed\ProductStorage\Dependency\Facade\ProductStorageToStoreFacadeInterface $storeFacade
     * @param bool $isSendingToQueue
     * @param \Spryker\Service\Synchronization\SynchronizationServiceInterface $synchronizationService
     * @param \Spryker\Client\Queue\QueueClientInterface $queueClient
     */
    public function __construct(
        ProductStorageToProductInterface $productFacade,
        AttributeMapInterface $attributeMap,
        ProductStorageQueryContainerInterface $queryContainer,
        ProductStorageToStoreFacadeInterface $storeFacade,
        $isSendingToQueue,
        SynchronizationServiceInterface $synchronizationService,
        QueueClientInterface $queueClient
    ) {
        parent::__construct($productFacade, $attributeMap, $queryContainer, $storeFacade, $isSendingToQueue);

        $this->synchronizationService = $synchronizationService;
        $this->queueClient = $queueClient;
    }

    /**
     * @param array $productAbstractLocalizedEntities
     * @param \Orm\Zed\ProductStorage\Persistence\SpyProductAbstractStorage[] $productAbstractStorageEntities
     *
     * @return void
     */
    protected function storeData(array $productAbstractLocalizedEntities, array $productAbstractStorageEntities)
    {
        $pairedEntities = $this->pairProductAbstractLocalizedEntitiesWithProductAbstractStorageEntities(
            $productAbstractLocalizedEntities,
            $productAbstractStorageEntities
        );

        $attributeMapBulk = $this->attributeMap->generateAttributeMapBulk(
            array_column($productAbstractLocalizedEntities, static::COL_FK_PRODUCT_ABSTRACT),
            array_column($productAbstractLocalizedEntities, static::COL_FK_LOCALE)
        );

        foreach ($pairedEntities as $pair) {
            $productAbstractLocalizedEntity = $pair[static::PRODUCT_ABSTRACT_LOCALIZED_ENTITY];
            $productAbstractStorageEntity = $pair[static::PRODUCT_ABSTRACT_STORAGE_ENTITY];

            if ($productAbstractLocalizedEntity === null || !$this->isActive($productAbstractLocalizedEntity)) {
                $this->deleteProductAbstractStorageEntity($productAbstractStorageEntity);

                continue;
            }

            $this->addProductAbstractStorageEntity(
                $productAbstractLocalizedEntity,
                $pair[static::STORE_NAME],
                $pair[static::LOCALE_NAME],
                $attributeMapBulk
            );
        }

        $this->write();

        if ($this->synchronizedMessageCollection !== []) {
            $this->queueClient->sendMessages('sync.storage.product', $this->synchronizedMessageCollection);
        }
    }

    /**
     * @param array $productAbstractLocalizedEntity
     * @param string $storeName
     * @param string $localeName
     * @param array $attributeMapBulk
     *
     * @return void
     */
    protected function addProductAbstractStorageEntity(
        array $productAbstractLocalizedEntity,
        $storeName,
        $localeName,
        array $attributeMapBulk = []
    ) {
        $productAbstractStorageTransfer = $this->mapToProductAbstractStorageTransfer(
            $productAbstractLocalizedEntity,
            new ProductAbstractStorageTransfer(),
            $attributeMapBulk
        );

        $productAbstractStorageData = [
            'fk_product_abstract' => $productAbstractLocalizedEntity['SpyProductAbstract'][static::COL_ID_PRODUCT_ABSTRACT],
            'data' => $productAbstractStorageTransfer->toArray(),
            'store' => $storeName,
            'locale' => $localeName,
        ];

        $this->add($productAbstractStorageData);
    }

    /**
     * @param array $productAbstractStorageData
     *
     * @return void
     */
    protected function add(array $productAbstractStorageData)
    {
        $synchronizedData = $this->buildSynchronizedData($productAbstractStorageData, 'fk_product_abstract', 'product_abstract');
        $this->synchronizedDataCollection[] = $synchronizedData;

        if ($this->isSendingToQueue) {
            $this->synchronizedMessageCollection[] = $this->buildSynchronizedMessage($synchronizedData, 'product_abstract');
        }
    }

    /**
     * @param array $data
     * @param string $keySuffix
     * @param string $resourceName
     *
     * @return array
     */
    public function buildSynchronizedData(array $data, string $keySuffix, string $resourceName): array
    {
        $key = $this->generateResourceKey($data, $keySuffix, $resourceName);
        $encodedData = json_encode($data['data']);
        $data['key'] = $key;
        $data['data'] = $encodedData;

        return $data;
    }

    /**
     * @param array $data
     * @param string $keySuffix
     * @param string $resourceName
     *
     * @return string
     */
    protected function generateResourceKey(array $data, string $keySuffix, string $resourceName)
    {
        $syncTransferData = new SynchronizationDataTransfer();
        if (isset($data['store'])) {
            $syncTransferData->setStore($data['store']);
        }

        if (isset($data['locale'])) {
            $syncTransferData->setLocale($data['locale']);
        }

        $syncTransferData->setReference($data[$keySuffix]);
        $keyBuilder = $this->synchronizationService->getStorageKeyBuilder($resourceName);

        return $keyBuilder->generateKey($syncTransferData);
    }

    /**
     * @param array $data
     * @param string $resourceName
     * @param array $params
     *
     * @return \Generated\Shared\Transfer\QueueSendMessageTransfer
     */
    public function buildSynchronizedMessage(array $data, string $resourceName, array $params = []): QueueSendMessageTransfer
    {
        $data['_timestamp'] = microtime(true);
        $payload = [
            'write' => [
                'key' => $data['key'],
                'value' => $data['data'],
                'resource' => $resourceName,
                'params' => $params,
            ],
        ];

        $queueSendTransfer = new QueueSendMessageTransfer();
        $queueSendTransfer->setBody(json_encode($payload));

        if (isset($data['store'])) {
            $queueSendTransfer->setStoreName($data['store']);

            return $queueSendTransfer;
        }

        $queueSendTransfer->setQueuePoolName('synchronizationPool');

        return $queueSendTransfer;
    }

    /**
     * @return void
     */
    public function write()
    {
        if (empty($this->synchronizedDataCollection)) {
            return;
        }

        $sql = $this->getSql();

        $con = Propel::getConnection();
        $stmt = $con->prepare($sql);

        $foreignKeys = $this->formatPostgresArray(array_column($this->synchronizedDataCollection, 'fk_product_abstract'));
        $stores = $this->formatPostgresArrayString(array_column($this->synchronizedDataCollection, 'store'));
        $locales = $this->formatPostgresArrayString(array_column($this->synchronizedDataCollection, 'locale'));
        $data = $this->formatPostgresArrayFromJson(array_column($this->synchronizedDataCollection, 'data'));
        $keys = $this->formatPostgresArrayString(array_column($this->synchronizedDataCollection, 'key'));

        $params = [
            $foreignKeys,
            $stores,
            $locales,
            $data,
            $keys,
        ];

        $stmt->execute($params);
    }

    /**
     * @param array $values
     *
     * @return string
     */
    public function formatPostgresArray(array $values): string
    {
        if (is_array($values) && empty($values)) {
            return '{null}';
        }

        $values = array_map(function ($value) {
            return ($value === null || $value === "") ? "NULL" : $value;
        }, $values);

        return sprintf(
            '{%s}',
            pg_escape_string(implode(',', $values))
        );
    }

    /**
     * @param array $values
     *
     * @return string
     */
    public function formatPostgresArrayString(array $values): string
    {
        return sprintf(
            '{"%s"}',
            pg_escape_string(implode('","', $values))
        );
    }

    /**
     * @param array $values
     *
     * @return string
     */
    public function formatPostgresArrayFromJson(array $values): string
    {
        return sprintf(
            '[%s]',
            pg_escape_string(implode(',', $values))
        );
    }

    /**
     * @return string
     */
    protected function getSql()
    {
        $sql = <<<SQL
WITH records AS (
    SELECT 
      input.fk_product_abstract,
      input.store,
      input.locale,
      input.data,
      input.key,
      id_product_abstract_storage
    FROM (
           SELECT 
             unnest(? :: INTEGER []) AS fk_product_abstract,
             unnest(? :: VARCHAR []) AS store,
             unnest(? :: VARCHAR []) AS locale,
             json_array_elements(?) AS data,
             unnest(? :: VARCHAR []) AS key
         ) input
      LEFT JOIN spy_product_abstract_storage ON spy_product_abstract_storage.key = input.key
    ),
    updated AS (
    UPDATE spy_product_abstract_storage
    SET 
      fk_product_abstract = records.fk_product_abstract,
      store = records.store,
      locale = records.locale,
      data = records.data,
      key = records.key,
      updated_at = now()
    FROM records
    WHERE records.key = spy_product_abstract_storage.key
    RETURNING spy_product_abstract_storage.id_product_abstract_storage
  ),
    inserted AS (
    INSERT INTO spy_product_abstract_storage(
      id_product_abstract_storage, 
      fk_product_abstract,
      store,
      locale,
      data,
      key,
      created_at,
      updated_at
    ) (
      SELECT
        nextval('spy_product_abstract_storage_pk_seq'), 
        fk_product_abstract,
        store,
        locale,
        data,
        key,
        now(),
        now()
      FROM records
      WHERE id_product_abstract_storage is null
    ) RETURNING spy_product_abstract_storage.id_product_abstract_storage
  )
SELECT updated.id_product_abstract_storage FROM updated
UNION ALL
SELECT inserted.id_product_abstract_storage FROM inserted;
SQL;

        return $sql;
    }
}
