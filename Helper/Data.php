<?php
namespace AccelaSearch\Search\Helper;

use AccelaSearch\Search\Constants;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\AttributeRepository;
use Magento\Framework\App\Config;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use AccelaSearch\Search\Logger\Logger;

/**
 * Class Data
 * @package AccelaSearch\Search\Helper
 */
class Data extends AbstractHelper
{
    public $dbmage_read;

    /**
     * @var StoreManagerInterface
     */
    private $_storeManager;
    /**
     * @var ResourceConnection
     */
    protected $_resource;
    /**
     * @var DirectoryList
     */
    protected $_directoryList;
    /**
     * @var Logger
     */
    protected $_logger;
    /**
     * @var Json
     */
    private $jsonSerializer;
    /**
     * @var AttributeRepositoryInterface
     */
    protected $attributeRepository;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resource
     * @param DirectoryList $directoryList
     * @param Logger $logger
     * @param Json $jsonSerializer
     */
    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource,
        DirectoryList $directoryList,
        Logger $logger,
        Json $jsonSerializer,
        AttributeRepositoryInterface $attributeRepository
    )
    {
        $this->_storeManager = $storeManager;
        $this->_resource = $resource;
        $this->_directoryList = $directoryList;
        $this->_logger = $logger;
        $this->jsonSerializer = $jsonSerializer;
        $this->_attributeRepository = $attributeRepository;
        parent::__construct($context);
    }

    /**
     * @param $code
     * @return int|null
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getAttributeIdByCode($code)
    {
        return $this->_attributeRepository->get(Product::ENTITY, $code)->getAttributeId();
    }

    /**
     * Magento database connection initialization
     *
     * @return array
     */
    public function getConnectionMagento()
    {
        try {
            if (!$this->dbmage_read) {
                $this->dbmage_read = $this->_resource->getConnection('core_read');
            }
        } catch(\Exception $exception) {
            $this->_logger->error("Error in " . __METHOD__ . ": DB Magento connection error");
            return
                array("success" => false,
                    "message" => "DB Magento connection error: " . $exception->getMessage()
                );
        }

        return array("success" => true);
    }

    /**
     * Get the table name adding the prefix (if it exists)
     *
     * @param $table
     * @return string
     */
    public function getMagentoTableWithPrefix($table)
    {
        return $this->_resource->getTableName($table);
    }

    /**
     * Get configuration field value for boolean type
     *
     * @param $config_path
     * @param string $config_scope
     * @param null $config_code
     * @return bool
     */
    public function isSetConfig(
        $config_path,
        $config_scope = Config::SCOPE_TYPE_DEFAULT,
        $config_code = null
    )
    {
        return $this->scopeConfig->isSetFlag($config_path, $config_scope, $config_code);
    }

    /**
     * Get configuration field value
     *
     * @param $config_path
     * @param string $config_scope
     * @param null $config_code
     * @return mixed
     */
    public function getConfig(
        $config_path,
        $config_scope = Config::SCOPE_TYPE_DEFAULT,
        $config_code = null
    )
    {
        return (string)$this->scopeConfig->getValue($config_path, $config_scope, $config_code);
    }

    /**
     * @return array|bool|float|int|mixed|string|null
     */
    public function getCustomFields(){
        $value = $this->getConfig('accelasearch_search/fields/custom_fields');
        return $value ? $this->jsonSerializer->unserialize($value) : [];
    }

    /**
     * @return array|bool|float|int|mixed|string|null
     */
    public function getCustomMultipleFields(){
        $value = $this->getConfig('accelasearch_search/fields/custom_multiple_fields');
        return $value ? $this->jsonSerializer->unserialize($value) : [];
    }

    /**
     * Get store code by store id
     *
     * @param $storeId
     * @return string|null
     */
    public function getStoreCodeById($storeId)
    {
        try {
            $storeData = $this->_storeManager->getStore($storeId);
            $storeCode = (string)$storeData->getCode();
        } catch (LocalizedException $localizedException) {
            $storeCode = null;
            $this->_logger->error($localizedException->getMessage());
        }

        return $storeCode;
    }

    /**
     * Checks if lock file exists; if it doesn't exist, the script generates it
     *
     * @param bool $createLock
     * @return array
     */
    public function fileLockCheck($createLock = true)
    {
        // Getting lock file directory
        $result = $this->_getLocksDir();
        if (!$result["success"]) {
            return $result;
        }
        $locksDir = $result["locksDir"];

        // complete lock file
        $locksFile = $locksDir . Constants::FILE_LOCK;
        // checking if lock file exist
        if (file_exists($locksFile)) {
            // If lock file creation date is expired
            if ((time() - filectime($locksFile)) > Constants::FILE_LOCK_EXPIRE) {
                // Deleting lock file
                unlink($locksFile);
                // Creating new lock file
                if ($createLock) {
                    touch($locksFile);
                }
            }
            else {
                return array(
                    "success" => false,
                    "message" => "The lock file " . Constants::FILE_LOCK . " already exists!"
                );
            }
        }
        // lock file doesn't exist
        else {
            if ($createLock) {
                // If lock file directory doesn't exist
                if (!is_dir($locksDir)) {
                    // Creating lock file directory
                    if (!mkdir($locksDir)) {
                        return array(
                            "success" => false,
                            "message" => "It is impossible to create the directory: " . $locksDir);
                    }
                }
                // Creating the lock file
                touch($locksFile);
            }
        }

        return array("success" => true);
    }

    /**
     * Delete the lock file
     *
     * @return array
     */
    public function fileLockDelete()
    {
        $result = $this->_getLocksDir();
        if (!$result["success"]) {
            return $result;
        } else {
            $locksDir = $result["locksDir"];
        }

        $lockFile = $locksDir . Constants::FILE_LOCK;
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Get lock file directory
     *
     * @return array
     */
    private function _getLocksDir()
    {
        try {
            $locksDir =
                $this->_directoryList->getPath(DirectoryList::VAR_DIR)
                . DIRECTORY_SEPARATOR
                . Constants::FILE_LOCKS_DIR
                . DIRECTORY_SEPARATOR;
        } catch (FileSystemException $exception) {
            return array(
                "success" => false,
                "message" => "Get lock file directory error: " . $exception->getMessage()
            );
        }

        return array("success" => true, "locksDir" => $locksDir);
    }

    /**
     * Get lock file containing all the path
     *
     * @return array
     */
    public function getLockFilePath()
    {
        try {
            $lockFilePath = $this->_directoryList->getPath(DirectoryList::VAR_DIR)
                . DIRECTORY_SEPARATOR
                . Constants::FILE_LOCKS_DIR
                . DIRECTORY_SEPARATOR
                . Constants::FILE_LOCK;
        } catch (FileSystemException $exception) {
            return array(
                "success" => false,
                "message" => __("Get lock file path error: " . $exception->getMessage()));
        }

        return array("success" => true, "lockFilePath" => $lockFilePath);
    }

    /**
     * Get log file containing all the path
     *
     * @return array
     */
    public function getLogFilePath()
    {
        try {
            $logFilePath = $this->_directoryList->getPath(DirectoryList::VAR_DIR)
                . DIRECTORY_SEPARATOR
                . Constants::FILE_LOG_DIR
                . DIRECTORY_SEPARATOR
                . Constants::FILE_LOG;
        } catch (FileSystemException $exception) {
            return array(
                "success" => false,
                "message" => __("Get log file path error: " . $exception->getMessage())
            );
        }

        return array("success" => true, "logFilePath" => $logFilePath);
    }
}
