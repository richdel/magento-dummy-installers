<?php
    require_once dirname(__FILE__) . '/../abstract.php';
    require_once dirname(__FILE__) . "/../error_handler.php";

    /**
     * Magento Shell Category Manager
     *
     * @category    Mage
     * @package     Mage_Shell
     * @author      Richard Delph <richard@agentdesign.co.uk>
     *
     * Work based on dummy installer scripts by Sander Mangel <sander@sandermangel.nl>
     *              https://github.com/sandermangel/magento-dummy-installers
     *
     * Goals of this script:
     * - Provide a quick way of quickly importing 1000's of categories
     * - Provide several options out of the box to manage categories
     *      1. Clear categories
     *      2. Import categories
     * - Accept simple text file, custom format, and eventually CSV and json types for extended attributes
     *      By default it will set name, meta, display mode,
     * - Reindex category tree after import
     * - Provide a clear and understandable output
     * - Perhaps look at using direct SQL
     */
    class Mage_Shell_Category_Manager extends Mage_Shell_Abstract
    {

        protected $_store;
        protected $_storeId;
        protected $_rootCategoryId;
        protected $_categoryCache = [];
        private $_errorHandler;

        public function _construct(){
            $this->_errorHandler = new Mage_Shell_Error_Handler();
            return $this;
        }

        public function run()
        {
            // Check for import command
            if ($this->getArg('import')) {

                // test for both store_code and filename options
                // for some reason if you put the arg -s with no value it defaults to '1',
                // this could be problematic as we're performing a lookup for store id based on store view code
                $storeCode = $this->getArg('s');
                $filename  = $this->getArg('f');

                if (!$storeCode || $storeCode === true) {
                    $this->_errorHandler->err("ERROR: -s store_code is required", $this);
                }

                if (is_null($this->_setStoreByCode($storeCode))) {
                    Mage_Shell_Error_Handler::err("ERROR: ID for store code ${storeCode} not found", $this);
                }

                if (!$filename || $filename === true) {
                    Mage_Shell_Error_Handler::err("ERROR: -f store_code is required", $this);
                }

                $validExts = array('txt');
                $fileExt   = pathinfo($filename, PATHINFO_EXTENSION);

                if (!in_array($fileExt, $validExts)) {
                    Mage_Shell_Error_Handler::err("ERROR: Invalid file extension. Valid extensions are: " . implode(',', $validExts));
                }

                $this->_import($filename);
            } elseif ($this->getArg('clear')) {

                print "Are you sure you want to reset all store categories? (Y/N) >";

                $stdin    = fopen('php://stdin', 'r');
                $response = fgetc($stdin);
                if (strtolower($response) != 'y') {
                    print "Aborted.\n";
                    exit;
                }

                print "\nOk, you said it boss...";
                $this->_resetCategories();

            } else {
                print $this->usageHelp();
            }
            print "\n";
        }

        protected function _setStoreByCode($storeCode = 'default')
        {
            if (!isset($this->_store) || $this->_store->getCode() != $storeCode) {
                $stores = array_keys(Mage::app()->getStores());

                foreach ($stores as $id) {
                    $store = Mage::app()->getStore($id);
                    if ($store->getCode() == $storeCode) {
                        $this->_store          = $store;
                        $this->_storeId        = $store->getId();
                        $this->_rootCategoryId = $store->getRootCategoryId();

                        return $this->_store;
                    }
                }

                return null;
            }

            return $this->_store;
        }

        protected function _resetCategories()
        {
            $_resource   = Mage::getSingleton('core/resource');
            $_connection = $_resource->getConnection('core_write');

            $sql = <<<SQL
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE catalog_category_entity_datetime;
TRUNCATE TABLE catalog_category_entity_decimal;
TRUNCATE TABLE catalog_category_entity_int;
TRUNCATE TABLE catalog_category_entity_text;
TRUNCATE TABLE catalog_category_entity_varchar;
TRUNCATE TABLE catalog_category_product;
TRUNCATE TABLE catalog_category_product_index;
TRUNCATE TABLE catalog_category_entity;

INSERT INTO catalog_category_entity (entity_id, entity_type_id, attribute_set_id, parent_id, created_at, updated_at, path, position, level, children_count)
VALUES
  (1, 3, 0, 0, NOW(), NOW(), '1', 0, 0, 1),
  (2, 3, 3, 1, NOW(), NOW(), '1/2', 1, 1, 0);

INSERT INTO catalog_category_entity_int (value_id, entity_type_id, attribute_id, store_id, entity_id, value)
VALUES
  (1, 3, 67, 0, 1, 1),
  (2, 3, 67, 1, 1, 1),
  (3, 3, 42, 0, 2, 1),
  (4, 3, 67, 0, 2, 1),
  (5, 3, 42, 1, 2, 1),
  (6, 3, 67, 1, 2, 1);


INSERT INTO catalog_category_entity_varchar (value_id, entity_type_id, attribute_id, store_id, entity_id, value)
VALUES
  (1, 3, 41, 0, 1, 'Root Catalog'),
  (2, 3, 41, 1, 1, 'Root Catalog'),
  (3, 3, 43, 1, 1, 'root-catalog'),
  (4, 3, 41, 0, 2, 'Default Category'),
  (5, 3, 41, 1, 2, 'Default Category'),
  (6, 3, 49, 1, 2, 'PRODUCTS'),
  (7, 3, 43, 1, 2, 'default-category');

SET FOREIGN_KEY_CHECKS = 1
SQL;
            $_connection->query($sql);

            print "\n\n-- Magento Categories have been reset! --";

        }

        protected function _buildCategoryCache()
        {

            $root_path = "1/" . $this->_rootCategoryId;

            $collection = Mage::getModel('catalog/category')
                              ->setStoreId($this->_storeId)
                              ->getCollection()
                              ->addAttributeToSelect('name');

            $collection->getSelect()->where("path like '" . $root_path . "/%'");

            foreach ($collection as $category) {
                $pathArray = explode("/", $category->getPath());
                $namePath  = '';

                // start iterations at second index of array which will consist of
                // root path (1)/store root category id/subsequent/names/to/use/to/build/cache
                for ($i = 2; $i < sizeof($pathArray); $i++) {

                    $categoryName = $collection->getItemById($pathArray[$i])->getName();
                    $namePath .= (empty($namePath) ? '' : '/') . trim($categoryName);

                }

                $this->_categoryCache[$namePath] = $category;
            }

            return;
        }

        protected function _import($filename)
        {

            $filePath = $this->_getRootPath() . $filename;

            if (!file_exists($filePath)) {
                Mage_Shell_Error_Handler::err("ERROR: ${filePath} can't be found, ensure file arg specified is relative to Magento root.");
            } elseif (!$handle = fopen($filePath, "r")) {
                Mage_Shell_Error_Handler::err("ERROR: Can't open file ${filePath}");
            }

            // build category cache to search against later
            $this->_buildCategoryCache();

            $lineCount = 0;

            while (!feof($handle)) {
                $line = fgets($handle, 4096);
                $lineCount += substr_count($line, PHP_EOL);
            }

            $lineCount++; #increment as it's zero based

            print "${lineCount} categories found to process. Beginning import...\n\n";
            $startTime = microtime(true);

            fseek($handle, 0);

            $i                     = 0;
            $existingCategoryCount = 0;
            $newCategoryCount      = 0;

            while (($line = fgets($handle)) !== false) {
                $fullCategoryNamePath = preg_replace('#\s*/\s*#', '/', trim($line));
                $categoryNamePath     = '';
                $path                 = "1/" . $this->_rootCategoryId;

                foreach (explode('/', $fullCategoryNamePath) as $categoryNamePathPart) {

                    $categoryNamePath .= (empty($categoryNamePath) ? '' : '/') . $categoryNamePathPart;

                    if (empty($this->_categoryCache[$categoryNamePath])) {

                        # create  new category
                        $newCategory = Mage::getModel('catalog/category')
                                           ->setStoreId($this->_storeId);

                        $newCategory->addData(array(
                                                  'name'         => $categoryNamePathPart,
                                                  'meta_title'   => $categoryNamePathPart,
                                                  'display_mode' => Mage_Catalog_Model_Category::DM_PRODUCT,
                                                  'is_active'    => 1,
                                                  'is_anchor'    => 1,
                                                  'path'         => $path,
                                              ));

                        $newCategory->save();
                        $this->_categoryCache[$categoryNamePath] = $newCategory;

                        try {
                            $newCategory->save();
                            $newCategoryCount++;
                        } catch (Exception $e) {
                            Mage_Shell_Error_Handler::err($e->getMessage());
                        }

                    } else {
                        $existingCategoryCount++;
                    }

                    $path .= "/" . $this->_categoryCache[$categoryNamePath]->getId();
                }

                print ".";
                $i++;
                if ($i % 80 === 0) {
                    print "\n";
                }
            }

            fclose($handle);

            $endTime     = microtime(true);
            $processTime = $endTime - $startTime;
            echo "\n\n-- Processed {$lineCount} records in {$processTime} seconds --\n\n";
            echo "No. of existing categories: " . $existingCategoryCount . " (including duplicate parent categories)\n";
            echo "No. of new categories created: " . $newCategoryCount . "\n";

        }

        /**
         * Retrieve Usage Help Message
         */
        public function usageHelp()
        {
            return <<<USAGE
Usage:  php -f category_manager.php -- command [options]

  import -s <store_code> -f <filename>          Import categories

  clear                     Clear all categories for the given store code,
                            if no store code given all categories will be removed

  help                      This help

  <store_code>  Store code e.g. default
  <filename>    Text file from which categories will be imported, formatted as follows:
                Parent Category
                Parent Category/Child Category
                Parent Category/Child Category/Another Child

                File path must be relative to magento root e.g. var/import/categories.txt
USAGE;
        }

    }

    $shell = new Mage_Shell_Category_Manager();
    $shell->run();
