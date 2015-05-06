<?php
/**
 * OLE ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2013.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   David Lacy <david.lacy@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
namespace VuFind\ILS\Driver;
use File_MARC, PDO, PDOException, Exception,
    VuFind\Exception\ILS as ILSException,
    VuFindSearch\Backend\Exception\HttpErrorException,
    Zend\Json\Json,
    Zend\Http\Client,
    Zend\Http\Request;

/**
 * OLE ILS Driver
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   David Lacy <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
class OLE extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{
    /**
     * HTTP service
     *
     * @var \VuFindHttp\HttpServiceInterface
     */
    protected $httpService = null;

    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    /**
     * Name of database
     *
     * @var string
     */
    protected $dbName;
    
    /**
     * Location of OLE's circ service
     *
     * @var string
     */
    protected $circService;
    
    /**
     * Location of OLE's docstore service
     *
     * @var string
     */
    protected $docService;

    /**
     * Location of OLE's solr service
     *
     * @var string
     */
    protected $solrService;
     
    /**
     * OLE operator for API calls
     */
    protected $operatorId;

    /**
     * item_available_codes, the value from ole_dlvr_item_avail_stat_t that indicates that an item is available. All other codes are reflect as unavailable.
     */
    protected $item_available_codes;
    
    /**
     * OLE's default sort for checked out items in user account section. 
     */
    protected $coiSort;
    
    /**
     * Set the HTTP service to be used for HTTP requests.
     *
     * @param HttpServiceInterface $service HTTP service
     *
     * @return void
     */
    public function setHttpService(\VuFindHttp\HttpServiceInterface $service)
    {
        $this->httpService = $service;
    }

    /**
     * Should we check renewal status before presenting a list of items or only
     * after user requests renewal?
     *
     * @var bool
     */
    protected $checkRenewalsUpFront;
    
    /* TODO Delete
    protected $record;
    */
    //
    /**
     * Default pickup location
     *
     * @var string
     */
    protected $defaultPickUpLocation;
    
    /* */
    protected $bibPrefix;
    protected $holdingPrefix;
    protected $itemPrefix;
    
    /* */
    protected $dbvendor;
    
    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }
        
        /* TODO: move these to the config */
        $this->bibPrefix = "wbm-";
        $this->holdingPrefix = "who-";
        $this->itemPrefix = "wio-";
        
        $this->dbvendor
            = isset($this->config['Catalog']['dbvendor'])
            ? $this->config['Catalog']['dbvendor'] : "mysql";
            
        $this->checkRenewalsUpFront
            = isset($this->config['Renewals']['checkUpFront'])
            ? $this->config['Renewals']['checkUpFront'] : true;
            
        $this->defaultPickUpLocation
            = $this->config['Holds']['defaultPickUpLocation'];

        // Define Database Name
        $this->dbName = $this->config['Catalog']['database'];
        
        // Define OLE's circualtion service
        $this->circService = $this->config['Catalog']['circulation_service'];
        
        // Define OLE's docstore service
        $this->docService = $this->config['Catalog']['docstore_service'];
        
        // Define OLE's solr service
        $this->solrService = $this->config['Catalog']['solr_service'];

        // Define OLE's Circ API operator
        $this->operatorId = $this->config['Catalog']['operatorId'];
        
        // Define OLE's available code status
        $this->item_available_codes = explode(":", $this->config['Catalog']['item_available_code']);       
 
        // Define OLE's default sort for checked out items 
        $this->coiSort = $this->config['UserAccount']['checkedOutItemsSort'];
 
        try {
            if ($this->dbvendor == 'oracle') {
                $tns = '(DESCRIPTION=' .
                         '(ADDRESS_LIST=' .
                           '(ADDRESS=' .
                             '(PROTOCOL=TCP)' .
                             '(HOST=' . $this->config['Catalog']['host'] . ')' .
                             '(PORT=' . $this->config['Catalog']['port'] . ')' .
                           ')' .
                         ')' .
                       ')';
                $this->db = new PDO(
                    "oci:dbname=$tns",
                    $this->config['Catalog']['user'],
                    $this->config['Catalog']['password']
                );
            } else {
                $this->db = new PDO(
                    "mysql:host=" . $this->config['Catalog']['host'] . ";port=" . $this->config['Catalog']['port'] . ";dbname=" . $this->config['Catalog']['database']. ";charset=UTF8",
                    $this->config['Catalog']['user'],
                    $this->config['Catalog']['password']
                );
            }
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw $e;
        }
        
    }

    /**
     * Public Function which retrieves renew, hold and cancel settings from the
     * driver ini file.
     *
     * @param string $function The name of the feature to be checked
     *
     * @return array An array with key-value pairs.
     */
    public function getConfig($function)
    {
        if (isset($this->config[$function]) ) {
            $functionConfig = $this->config[$function];
        } else {
            $functionConfig = false;
        }
        return $functionConfig;
    }
    
    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $barcode The patron barcode
     * @param string $login   The patron's last name or PIN (depending on config)
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($barcode, $login)
    {
        // Load the field used for verifying the login from the config file, and
        // make sure there's nothing crazy in there:
        $login_field = isset($this->config['Catalog']['login_field'])
            ? $this->config['Catalog']['login_field'] : 'LAST_NAME';
        $login_field = preg_replace('/[^\w]/', '', $login_field);

        $sql = "SELECT * " .
               "FROM $this->dbName.ole_ptrn_t, $this->dbName.krim_entity_nm_t " .
               "where ole_ptrn_t.OLE_PTRN_ID=krim_entity_nm_t.ENTITY_ID AND " .
               "lower(krim_entity_nm_t.{$login_field}) = :login AND " .
               "lower(ole_ptrn_t.BARCODE) = :barcode";

        
        try {
            $sqlStmt = $this->db->prepare($sql);
            $sqlStmt->bindParam(
                ':login', strtolower(utf8_decode($login)), PDO::PARAM_STR
            );
            $sqlStmt->bindParam(
                ':barcode', strtolower(utf8_decode($barcode)), PDO::PARAM_STR
            );
            //var_dump($sqlStmt);
            $sqlStmt->execute();
            $row = $sqlStmt->fetch(PDO::FETCH_ASSOC);
            if (isset($row['OLE_PTRN_ID']) && ($row['OLE_PTRN_ID'] != '')) {
                return array(
                    'id' => utf8_encode($row['OLE_PTRN_ID']),
                    'firstname' => utf8_encode($row['FIRST_NM']),
                    'lastname' => utf8_encode($row['LAST_NM']),
                    'cat_username' => $barcode,
                    'cat_password' => $login,
                    'email' => null,
                    'major' => null,
                    'college' => null,
                    'barcode' => $barcode);
            } else {
                return null;
            }
        } catch (PDOException $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron The patron array
     *
     * @throws ILSException
     * @return array        Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
        $uri = $this->circService . '?service=lookupUser&patronBarcode=' . $patron['barcode'] . '&operatorId=' . $this->operatorId;

        $request = new Request();
        $request->setMethod(Request::METHOD_GET);
        $request->setUri($uri);
        
        $client = new Client();
        $client->setOptions(array('timeout' => 240));

        
        try {
            $response = $client->dispatch($request);
        } catch (Exception $e) { 
            throw new ILSException($e->getMessage());
        }
        
        // TODO: reimplement something like this when the API starts returning the proper http status code
        /*
        if (!$response->isSuccess()) {
            throw HttpErrorException::createFromResponse($response);
        }
        */

        $content = $response->getBody();
        $xml = simplexml_load_string($content);

        $patron['email'] = '';
        $patron['address1'] = '';
        $patron['address2'] = null;
        $patron['city'] = '';
        $patron['state'] = '';
        $patron['zip'] = '';
        $patron['phone'] = '';
        $patron['group'] = '';
        
        if (!empty($xml->patronName->firstName)) {
            $patron['firstname'] = $xml->patronName->firstName;
        }
        if (!empty($xml->patronName->lastName)) {
            $patron['lastname'] = $xml->patronName->lastName;
        }
        if (!empty($xml->patronEmail->emailAddress)) {
            $patron['email'] = $xml->patronEmail->emailAddress;
        }
        if (!empty($xml->patronAddress->line1)) {
            $patron['address1'] = $xml->patronAddress->line1;
        }
        if (!empty($xml->patronAddress->line2)) {
            $patron['address2'] = $xml->patronAddress->line2;
        }
        if (!empty($xml->patronAddress->city)) {
            $patron['city'] = $xml->patronAddress->city;
        }
        if (!empty($xml->patronAddress->stateProvinceCode)) {
            $patron['state'] = $xml->patronAddress->stateProvinceCode;
        }
        if (!empty($xml->patronAddress->postalCode)) {
            $patron['zip'] = $xml->patronAddress->postalCode;
        }
        if (!empty($xml->patronPhone->phoneNumber)) {
            $patron['phone'] = $xml->patronPhone->phoneNumber;
        }

        return (empty($patron) ? null : $patron);

    }

    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron) {
        $holdList = array();
        
        $uri = $this->circService . '?service=getCheckedOutItems&patronBarcode=' . $patron['barcode'] . '&operatorId=' . $this->operatorId;
        
        $request = new Request();
        $request->setMethod(Request::METHOD_GET);
        $request->setUri($uri);

        $client = new Client();
        $client->setOptions(array('timeout' => 30));
            
        try {
            $response = $client->dispatch($request);
        } catch (Exception $e) { 
            throw new ILSException($e->getMessage());
        }
        // TODO: reimplement something like this when the API starts returning the proper http status code
        /*
        if (!$response->isSuccess()) {
            throw HttpErrorException::createFromResponse($response);
        }
        */
        $content = $response->getBody();

        $xml = simplexml_load_string($content);

        $code = $xml->xpath('//code');
        $code = (string)$code[0][0];
        
        //var_dump($code);
        //var_dump($xml);

        if ($code == '000') {
            $holdItems = $xml->xpath('//checkOutItem');
            $holdsList = array();
            
            foreach($holdItems as $item) {
                //var_dump($item);
                $processRow = $this->processMyTransactions($item, $patron);
                //var_dump($processRow);
                $holdsList[] = $processRow;
            }
        }

        return $holdsList;


    }

    /**
     * Converts a date into a sortable date.
     *
     * @param string, $date a date string to be formatted.
     *
     * @returns, integer date in sortable fashion 
     * e.g. 20141023
     */
    public function sortDate($date) {
        return date('Ynj', strtotime($date));
    }
    
    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return mixed        Array of the patron's fines on success.
     */
     /* TODO: this hasn't been fully implemented yet */
     
    public function getMyFines($patron)
    {

        $fineList = array();
        $transList = $this->getMyTransactions($patron);

        $uri = $this->circService . '?service=fine&patronBarcode=' . $patron['barcode'] . '&operatorId=' . $this->operatorId;
        $request = new Request();
        $request->setMethod(Request::METHOD_GET);
        $request->setUri($uri);

        $client = new Client();
        $client->setOptions(array('timeout' => 30));

        try {
            $response = $client->dispatch($request);
        } catch (Exception $e) { 
            throw new ILSException($e->getMessage());
        }
        
        $content_str = $response->getBody();
        $xml = simplexml_load_string($content_str);
        
        $fines = $xml->xpath('//fineItem');

        foreach($fines as $fine) {
            //var_dump($fine);
            $processRow = $this->processMyFinesData($fine, $patron);
            //var_dump($processRow);
            
            if($processRow['id']) {
                foreach($transList as $trans) {
                    if ($this->bibPrefix . $trans['id'] == $processRow['id']) {
                        $processRow['checkout'] = $trans['loanedDate'];
                        $processRow['duedate'] = $trans['duedate'];
                        $processRow['title'] = $trans['title'];
                        break;
                    }
                }
            }
            $fineList[] = $processRow;
        }


        return $fineList;

    }
    /**
     * Protected support method for getMyHolds.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
     *
     * @throws DateException
     * @return array Keyed data for display by template files
     */
    protected function processMyFinesData($itemXml, $patron = false)
    {

        $recordId = (substr((string)$itemXml->catalogueId, 0, 4) == $this->bibPrefix ? substr((string)$itemXml->catalogueId, 4) : (string)$itemXml->catalogueId);
        
        $record = $this->getRecord($recordId);

        return array(
                 'amount' => (string)$itemXml->amount,
                 'fine' => (string)$itemXml->reason,
                 'balance' => (string)$itemXml->balance,
                 'createdate' => (string)$itemXml->dateCharged,
                 'title' => (string)$itemXml->title,
                 'checkout' => '',
                 'duedate' => '',
                 'id' => $recordId
             );
    }
    
    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws DateException - TODO
     * @throws ILSException
     * @return array        Array of the patron's holds on success.
     */
    public function getMyHolds($patron)
    {

        $holdList = array();
        
        $uri = $this->circService . '?service=holds&patronBarcode=' . $patron['barcode'] . '&operatorId=' . $this->operatorId;
        
        $request = new Request();
        $request->setMethod(Request::METHOD_GET);
        $request->setUri($uri);

        $client = new Client();
        $client->setOptions(array('timeout' => 30));
            
        try {
            $response = $client->dispatch($request);
        } catch (Exception $e) { 
            throw new ILSException($e->getMessage());
        }
        // TODO: reimplement something like this when the API starts returning the proper http status code
        /*
        if (!$response->isSuccess()) {
            throw HttpErrorException::createFromResponse($response);
        }
        */
        $content = $response->getBody();

        $xml = simplexml_load_string($content);
        
        $code = $xml->xpath('//code');
        $code = (string)$code[0][0];
        
        //var_dump($code);
        //var_dump($xml);

        if ($code == '000') {
            $holdItems = $xml->xpath('//hold');
            $holdsList = array();
            
            foreach($holdItems as $item) {
                //var_dump($item);
                $processRow = $this->processMyHoldsData($item, $patron);
                //var_dump($processRow);
                $holdsList[] = $processRow;
            }
        }
        return $holdsList;

    }

    /**
     * Protected support method for getMyHolds.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
     *
     * @throws DateException - TODO
     * @return array Keyed data for display by template files
     */
    protected function processMyHoldsData($itemXml, $patron = false)
    {
        $availableDateTime = (string) $itemXml->availableDate;
        $available = ($availableDateTime <= date('Y-m-d')) ? true:false;
        // JEJ CHANGE
		// Did the API change to return a string instead of date? (DL)
        $available = ((string) $itemXml->availableStatus == 'ONHOLD') ?  true:false;

        return array(
            'id' => substr((string) $itemXml->catalogueId, strpos((string) $itemXml->catalogueId, '-')+1),
            'item_id' => (string) $itemXml->itemId,
            'type' => (string) $itemXml->requestType,
            'location' => '',
            'expire' => (string) $itemXml->expiryDate,
            'create' => (string) $itemXml->createDate,
            'position' => (string) $itemXml->priority,
            'available' => $available,
            'reqnum' => (string) $itemXml->requestId,
            'volume' => '',
            'publication_year' => '',
            'title' => strlen((string) $itemXml->title)
                ? (string) $itemXml->title : "unknown title"
        );

    }

    protected function processMyTransactions($itemXml, $patron = false)
    {
        $availableDateTime = (string) $itemXml->availableDate;
        $available = ($availableDateTime <= date('Y-m-d')) ? true:false;
        // JEJ CHANGE
		// Did the API change to return a string instead of date? (DL)
        $available = ((string) $itemXml->availableStatus == 'ONHOLD') ?  true:false;

        return array(
            'duedate' => (string) $itemXml->dueDate,
            'id' => substr((string) $itemXml->catalogueId, strpos((string) $itemXml->catalogueId, '-')+1),
            'item_id' => (string) $itemXml->itemId,
            'create' => (string) $itemXml->createDate,
            'title' => strlen((string) $itemXml->title)
                ? (string) $itemXml->title : "unknown title",
            'renewable' => array('message' => 'renewable', 'renewable' => true)
        );

    }

    /**
     * Protected support method for getMyTransactions.
     *
     * @param array $row data from the database
     * @param array $patron array
     *
     * @throws DateException - TODO
     * @return array Keyed data for display by template files
     */
    protected function processMyTransactionsData($row, $patron = false)
    {
        $dateFormat = 'n/j/Y';
        $dueDate = substr((string) $row['duedate'], 0, 10);
        $dueTime = substr((string) $row['duedate'], 11);

        $loanedDate = substr((string) $row['loaned_date'], 0, 10);
        $loanedTime = substr((string) $row['loaned_date'], 11);

        $dueStatus = ($row['overdue_notices_count'] > 0) ? "overdue" : "";
        
        $xml = simplexml_load_string($row['bib_data']);
        
        $title = ''; 
        foreach($xml->record->xpath('*') as $field) {
            if ($field->attributes() == '245') {
                $title = (string) $field->subfield;
            }
        }

        /*See if item is on indefinite loan.*/
        $isIndefiniteLoan = strlen($dueDate) < 1;
        
        $transactions = array(
            'id' => $row['bib_num'],
            'item_id' => $row['item_id'],
            'duedate' => ($isIndefiniteLoan == false ? date($dateFormat, strtotime($dueDate)) : null),
            'dueTime' => ($isIndefiniteLoan == false ? $dueTime : null),
            'loanedDate' => date($dateFormat, strtotime($loanedDate)),
            'loanedTime' => $loanedTime,
            'dueStatus' => $dueStatus,
            'volume' => $row['enumeration'],
            'copy' => $row['copy_number'],
            'callNumber' => $row['call_number_prefix'] . ' ' . $row['call_number'],
            'publication_year' => $row['imprint'],
            'renew' => $row['number_of_renewals'],
            'title' => $title != '' ? $title : "unknown title",
            'barcode' => $row['barcode'] 
        );
        $renewData = $this->checkRenewalsUpFront
            ? $this->isRenewable($patron['id'], $transactions['item_id'])
            : array('message' => 'renewable', 'renewable' => true);

        $transactions['renewable'] = $renewData['renewable'];
        $transactions['message'] = $isIndefiniteLoan == false ? $renewData['message'] : null;
        
        return $transactions;
    }
    
    /* TODO: document this */
    public function getRecord($id)
    {

        //$uri = $this->docService . '?docAction=instanceDetails&format=xml&bibIds=' . $id;
        $uri = $this->solrService . "?q=bibIdentifier:" . $this->bibPrefix . $id . "&wt=xml&rows=100000";
        /* TODO: use the zend http service and throw appropriate exception */
        $xml = simplexml_load_string(file_get_contents($uri));

        
        //$xml->registerXPathNamespace('ole', 'http://ole.kuali.org/standards/ole-instance');
        //$xml->registerXPathNamespace('circ', 'http://ole.kuali.org/standards/ole-instance-circulation');

        return $xml;
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {

        $idole = intval(substr($id, 0, -1));
        $sql = 'SELECT DISTINCT loc.LOCN_NAME AS locn_name, 
                    h.LOCATION AS holdings_locn_code, h.call_number_prefix AS holding_call_number_prefix, h.call_number AS holding_call_number, 
                    i.LOCATION AS item_locn_code, i.call_number_prefix AS item_call_number_prefix, i.call_number AS item_call_number, i.COPY_NUMBER AS item_copy_number,
                    status.ITEM_AVAIL_STAT_CD AS item_status_code, status.ITEM_AVAIL_STAT_NM AS item_status_name
                FROM ole_ds_holdings_t h
                    JOIN ole_ds_item_t i ON h.HOLDINGS_ID = i.HOLDINGS_ID
                    JOIN ole_dlvr_item_avail_stat_t status ON i.ITEM_STATUS_ID=status.ITEM_AVAIL_STAT_ID
                LEFT JOIN ole_locn_t loc ON loc.LOCN_CD = if(i.LOCATION is not NULL && length(i.LOCATION) > 0, SUBSTRING_INDEX(i.LOCATION, \'/\', -1), SUBSTRING_INDEX(h.LOCATION, \'/\', -1))
                    WHERE h.STAFF_ONLY = "N"
                        AND i.STAFF_ONLY = "N"
                        AND h.BIB_ID = :id';

        try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':id' => $idole));
 
            /*Build the return array*/
            $items = array();
            while ($row = $stmt->fetch()) {
                $item = array();
 
                /*Set convenience variables.*/
                $status = $row['item_status_code'];
                $available = (in_array($status, $this->item_available_codes) ? true:false);
                $location = $row['locn_name'];

                /*Build item array*/ 
                $item['id'] = $id;
                $item['status'] = $status;
                $item['location'] = $row['locn_name'];
                $item['reserve'] = 'N';
                $item['callnumber'] = (string) $row[2] . ' ' . $row['holding_call_number'];
                $item['availability'] = $available;
                if ($item['status'] == 'ANAL') {
                    $item['availability'] = null;
                }

                $items[] = $item; 
            }
        }
        catch (Exception $e){
            /*Do nothing*/
        }
        return $items;
    }
    
    /**
     * TODO: document this
     *
     */
    public function getItemStatus($itemXML) {

        $status = $itemXML->children('circ', true)->itemStatus->children()->codeValue;
        // TODO: enable all item statuses
        $available = (in_array($status, $this->item_available_codes)) ? true:false;

        $item['status'] = $status;
        $item['location'] = '';
        $item['reserve'] = '';
        $item['availability'] = $available;

        return $item;
    }
    
    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $idList The array of record ids to retrieve the status for
     *
     * @throws ILSException
     * @return array        An array of getStatus() return values on success.
     */
    public function getStatuses($idList)
    {
        $status = array();
        foreach ($idList as $id) {
            $status[] = $this->getStatus($id);
        }
        return $status;
    }

    /**
     *
     */
    protected function getItems($id, $holdingId, $holdingLocation, $holdingLocCodes, $holdingCallNum, $holdingCallNumDisplay) {

        /*Bet items by holding id*/
        $sql = 'SELECT i.ITEM_ID AS item_id, i.HOLDINGS_ID AS holdings_id, i.BARCODE AS barcode, i.URI AS uri, 
                    i.ITEM_TYPE_ID AS item_type_id, i.TEMP_ITEM_TYPE_ID as temp_item_type_id, 
                    itype.ITM_TYP_CD AS itype_code, itype.ITM_TYP_NM AS itype_name, 
                    istat.ITEM_AVAIL_STAT_CD AS status_code, istat.ITEM_AVAIL_STAT_NM AS status_name,
                    i.LOCATION AS location, loc.LOCN_NAME AS locn_name,
                    i.CALL_NUMBER_TYPE_ID, i.CALL_NUMBER_PREFIX, i.CALL_NUMBER, i.ENUMERATION, i.CHRONOLOGY, i.COPY_NUMBER, 
                    i.DUE_DATE_TIME, i.CHECK_OUT_DATE_TIME, i.CLAIMS_RETURNED,
                    CONCAT_WS(";", (SELECT inote.NOTE
                        FROM ole_ds_item_note_t inote
                        WHERE i.ITEM_ID = inote.ITEM_ID
                        AND inote.TYPE="public")
                    ) AS note 
                        FROM ole_ds_item_t i
                    LEFT JOIN ole_dlvr_item_avail_stat_t istat on i.ITEM_STATUS_ID = istat.ITEM_AVAIL_STAT_ID
                    LEFT JOIN ole_cat_itm_typ_t itype on if(i.TEMP_ITEM_TYPE_ID is not null, i.TEMP_ITEM_TYPE_ID, i.ITEM_TYPE_ID) = itype.ITM_TYP_CD_ID
                    LEFT JOIN ole_locn_t loc on loc.LOCN_CD = SUBSTRING_INDEX(i.LOCATION, \'/\', -1)
                        WHERE i.STAFF_ONLY = \'N\'
                            AND i.HOLDINGS_ID = :holdingId';


        try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':holdingId' => $holdingId));

            /*Return array*/
            $items = array();
            while ($row = $stmt->fetch()) {
                $item = array();
                //print_r($row);
 
                /*Set convenience variables.*/
                $status = $row['status_code'];
                $available = (in_array($status, $this->item_available_codes) ? true:false);
                $copyNum = $row['COPY_NUMBER'];
                $enumeration = $row['ENUMERATION'];
                $itemCallNumDisplay = (isset($row['CALL_NUMBER']) ? trim($row['CALL_NUMBER_PREFIX'] . ' ' .$row['CALL_NUMBER']) : null);
                $itemCallNum = (isset($row['CALL_NUMBER']) ? trim($row['CALL_NUMBER']) : null);
                $holdtype = ($available == true) ? "hold":"recall";
                $itemTypeArray = ($row['itype_name'] ? explode('-', $row['itype_name']) : array());
                $itemTypeName = trim($itemTypeArray[1]);
                $itemLocation = $row['locn_name'];
                $itemLocCodes = $row['location'];
                              
                /*Build the items*/ 
                $item['id'] = $id;
                $item['availability'] = $available;
                $item['status'] = $status;
                $item['location'] = (!empty($itemLocation) ? $itemLocation : $holdingLocation);
                $item['reserve'] = '';
                $item['callnumber'] = (!empty($itemCallNum) ? $itemCallNum : $holdingCallNum);
                $item['duedate'] = (isset($row['DUE_DATE_TIME']) ? $row['DUE_DATE_TIME'] : 'Indefinite') ;
                $item['returnDate'] = '';
                $item['number'] = $copyNum . ' : ' . $enumeration;
                $item['requests_placed'] = '';
                $item['barcode'] = $row['barcode'];
                $item['item_id'] = $row['item_id'];
                $item['is_holdable'] = true;
                $item['itemNotes'] = $row['note'];
                $item['holdtype'] = $holdtype;
                /*UChicago specific?*/
                $item['claimsReturned'] = ($row['CLAIMS_RETURNED'] == 'Y' ? true : false);
                $item['sort'] = preg_replace('/[^[:digit:]]/','', $copyNum) .  preg_replace('/[^[:digit:]]/','', array_shift(preg_split('/[\s-]/', $enumeration)));
                $item['itemTypeCode'] = $row['itype_code'];
                $item['itemTypeName'] = $itemTypeName;
                $item['callnumberDisplay'] = (!empty($itemCallNumDisplay) ? $itemCallNumDisplay : $holdingCallNumDisplay);
                $item['locationCodes'] = (!empty($itemLocCodes) ? $itemLocCodes : $holdingLocCodes);
    
                $items[] = $item;
            }
        }
        catch (Exception $e){print_r($e);
            /*Do nothing*/
        }
        /*Sort numerically by copy/volume number.*/
        usort($items, function($a, $b) { return OLE::cmp($a['sort'], $b['sort']); });
        return $items;
    }

    /**
     * A trivial helper method used in getItems as a comparison 
     * callback for usort in ordering item lists by a definied 
     * sort key. This should be removed, along with usort and 
     * the $item['sort'] key in getItems once the DB is returning 
     * things in the proper order.
     *
     * @param $a string, formatted copy/volume numbers in the 
     * $item['sort'] key for each item in he items array.
     * @param $b same as $a
     *
     * returns -1, 0, or 1
     */
    public function cmp($a, $b) {
        if ($a == $b) {
            return 0;
        }
        return ($a < $b) ? -1 : 1;
    }

    /**
     * Get Summary of Holdings
     *
     * Gets the extent of ownership information from OLE.
     *
     * @param string $id the record id.
     * @param string $holdingId holding specific identifier.
     * @param string $location the holding location.
     *
     * @return array of summary data for a specific holding.
     */
    protected function getSummaryHoldings($id, $holdingId, $location) {
        /*Get extent of ownership by bib id*/
        $sql = 'SELECT own.EXT_OWNERSHIP_ID, own.HOLDINGS_ID,
                   ot.TYPE_OWNERSHIP_NM,
                   own.ORD, own.TEXT,
                   CONCAT_WS(\';\', (SELECT note.NOTE
                    from ole_ds_ext_ownership_note_t note 
                    where note.EXT_OWNERSHIP_ID = own.EXT_OWNERSHIP_ID
                    and note.TYPE = \'public\'
                    )) AS note
                    FROM ole_ds_ext_ownership_t own
                JOIN ole_ds_holdings_t h ON own.HOLDINGS_ID = h.HOLDINGS_ID
                LEFT JOIN ole_cat_type_ownership_t ot ON own.EXT_OWNERSHIP_TYPE_ID = ot.TYPE_OWNERSHIP_ID
                    WHERE h.STAFF_ONLY = "N"
                        AND h.BIB_ID = :id ORDER BY own.HOLDINGS_ID, ot.TYPE_OWNERSHIP_ID, own.ORD';

        try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':id' => $id));
            
            /*Return array*/
            $summaryHoldings = array();
            while ($row = $stmt->fetch()) {
                //print_r($row);
                $summary = array();
                if ($holdingId == $row['HOLDINGS_ID']) { 
                    //Convienence variables
                    $summaryType = $row['TYPE_OWNERSHIP_NM'];

                    $summary['id'] = $id;
                    $summary['location'] = $location;
                    //$summary['notes'] = array($summary->note[0]->value);
                    //$summary['summary'] = array($summary->textualHoldings);
                    $summary['libraryHas'] = ($summaryType == 'Basic Bibliographic Unit' ? array($row['TEXT'], $row['note']) : null);
                    $summary['indexes'] =  ($summaryType == 'Indexes' ? array($row['TEXT'], $row['note']) : null);
                    $summary['supplements'] = ($summaryType == 'Supplementary Material' ? array($row['TEXT'], $row['note']) : null);
                    $summary['availability'] = true;
                    $summary['status'] = '';
                    $summary['is_holdable'] = true;
                }
                if (!empty($summary)) {
                    $summaryHoldings[] = $summary;
                }
            }
        }
        catch (Exception $e){
            /*Do nothing*/
        }

        //var_dump($summaryHoldings);
        return $summaryHoldings;

    }

    /**
     * Get E-Holdings 
     *
     * Gets the e-holdings.
     *
     * @param string $id the record id.
     * @param string $holdingId holding specific identifier.
     * @param string $location the holding location.
     *
     * @return array of eholdings represented as "items".
     */
    protected function getEholdings($id, $holdingId, $holdingLocation, $holdingCallNum, $holdingCallNumDisplay) {
        $sql = 'SELECT u.HOLDINGS_ID AS holdings_id, u.URI AS uri, u.TEXT AS text
                    FROM ole_ds_holdings_uri_t u
                        WHERE u.HOLDINGS_ID = :holdingId';

         try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':holdingId' => $holdingId));

            /*Return array*/
            $eHoldings = array();
            while ($row = $stmt->fetch()) {
                $item = array();

                if (!empty($row['uri'])) {
                    $item['id'] = $id;
                    $item['location'] = $holdingLocation;
                    $item['availability'] = true;
                    $item['status'] = 'AVAILABLE';
                    $item['eHolding'] = array('text' => $row['text'], 'uri' => $row['uri']);
                    $item['callnumber'] = $holdingCallNum;
                    /*UChicago Specific?*/
                    $item['callnumberDisplay'] = $holdingCallNumDisplay;
                }

                if (!empty($item)) {
                    $eHoldings[] = $item;
                }
            }
        }
        catch (Exception $e){
            /*Do nothing*/
        }
        return $eHoldings;
    }


    /**
     * Get unbound periodicals and recently received items.
     *
     * @param string $id the record id.
     * @param string $holdingId holding specific identifier.
     * @param string $holdingLocation the holding location.
     *
     * @returns an array of item information for things without a barcode.
     */
    protected function getSerialReceiving($id, $holdingId, $holdingLocation) {
        /*Get serial receiving data (unbound periodicals) by holdingId*/
        $sql = 'SELECT r.INSTANCE_ID, SUBSTRING(r.UNBOUND_LOC,  SUBSTRING_INDEX(r.UNBOUND_LOC, \'/\', -1) + LOCATE(\'/\', REVERSE(r.UNBOUND_LOC)) + 1 )  AS unbound_shelving_loc, 
                s.SER_RCPT_HIS_REC_ID, s.SER_RCV_REC_ID, s.RCV_REC_TYP,
                   CONCAT_WS(" ", s.ENUM_LVL_1, s.ENUM_LVL_2, s.ENUM_LVL_3, s.ENUM_LVL_4, s.ENUM_LVL_5, s.ENUM_LVL_6) AS enum,
                   if(LEFT(s.CHRON_LVL_1,1)=\'(\' || LENGTH(s.CHRON_LVL_1) = 0, 
                      s.CHRON_LVL_1, 
                      CONCAT(\'(\', CONCAT_WS(": ", s.CHRON_LVL_1, CONCAT_WS(" ", s.CHRON_LVL_2, s.CHRON_LVL_3, s.CHRON_LVL_4)), \')\')
                   ) AS chron,
                   (SELECT loc.LOCN_NAME
                        FROM ole_locn_t loc
                        WHERE loc.LOCN_CD = unbound_shelving_loc
                    ) AS unbound_loc_name,
                   s.SER_RCPT_NOTE, 
                   s.PUB_RCPT AS note
                    FROM ole_ser_rcv_his_rec s
                JOIN ole_ser_rcv_rec r ON r.SER_RCV_REC_ID = s.SER_RCV_REC_ID
                    where s.PUB_DISPLAY = "Y"
                        and r.INSTANCE_ID = :holdingId';
       
        /*Return array*/
        $items = array();

        try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':holdingId' => $this->holdingPrefix . $holdingId));

            while ($row = $stmt->fetch()) {
                $item = array();
                $unboundLocation = $row['unbound_loc_name'];
                $unboundLocCodes = $row['unbound_loc_codes'];

                $item['id'] = $id;
                $item['location'] = (!empty($unboundLocation) ? $unboundLocation : $holdingLocation);
                $item['locationCodes'] = $unboundLocCodes;
                $item['availability'] = true;
                $item['status'] = 'AVAILABLE';
                $item['unbound'] = $row['enum'] . $row['chron'];
                $item['note'] = $row['note'];

                if (!empty($item)) {
                    $items[] = $item;
                }
            }
        }
        catch (Exception $e){
            /*Do nothing*/
        }

        return $items;

    }


    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return array         On success, an associative array with the following
     * keys: id, availability (boolean), status, location, reserve, callnumber,
     * duedate, number, barcode.
     */
    public function getHolding($id, array $patron = NULL)
    {
        $idole = intval(substr($id, 0, -1));

        /*Get holdings by bib id, with holdings notes.*/
        $sql = 'SELECT h.HOLDINGS_ID AS holdings_id, h.BIB_ID AS bib_id, h.location AS
                location, loc.LOCN_NAME AS locn_name, 
                       h.CALL_NUMBER_PREFIX AS call_number_prefix, h.CALL_NUMBER AS
                call_number, h.COPY_NUMBER AS copy_number,
                       CONCAT_WS(";", (SELECT note.NOTE
                        FROM ole_ds_holdings_note_t note
                        WHERE note.HOLDINGS_ID = h.HOLDINGS_ID
                        AND note.TYPE="public"
                       )) AS note,
                       (SELECT count(*) 
                        FROM ole_ds_ext_ownership_t own 
                        WHERE own.HOLDINGS_ID = h.HOLDINGS_ID
                        ) AS ext_ownership_count,
                       (SELECT count(*) 
                        FROM ole_ser_rcv_rec r
                        WHERE r.INSTANCE_ID = CONCAT("who-", h.HOLDINGS_ID)
                        ) AS ser_rcv_rec_count,
                       (SELECT count(*)
                        FROM ole_ds_holdings_uri_t uri
                        WHERE uri.HOLDINGS_ID = h.HOLDINGS_ID
                        ) AS uri_count,
                       (SELECT count(*)
                        FROM ole_ds_item_t itm
                        WHERE itm.HOLDINGS_ID = h.HOLDINGS_ID
                        ) AS item_count
                    FROM ole_ds_holdings_t h
                    LEFT JOIN ole_locn_t loc on loc.LOCN_CD = SUBSTRING_INDEX(h.LOCATION, \'/\',
                -1)
                    WHERE h.STAFF_ONLY = "N"
                    AND h.BIB_ID = :id';

        try {
            /*Query the database*/
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array(':id' => $idole));

            /*Final return array*/
            $items = array();
            while ($row = $stmt->fetch()) {
                /*Array for item data*/
                $item = array();
                //print_r($row);

                /*Convenience variables.*/
                $shelvingLocation = $row['locn_name'];
                $holdingCallNum = trim($row['call_number']);
                $holdingCallNumDisplay = trim($row['call_number_prefix'] . ' ' . $row['call_number']);
                $holdingNote = $row['note'];
                $hasExtOwnership = intval($row['ext_ownership_count']) > 0;
                $hasEholdings = intval($row['uri_count']) > 0;
                $hasItems = intval($row['item_count']) > 0;
                $hasHoldingNote = (!empty($holdingNote));
                $hasUnboundItems = intval($row['ser_rcv_rec_count']) > 0;
                $holdingId = $row['holdings_id'];
                $locationCodes = $row['location'];

                /*Get e-holdings if they exist*/
                if ($hasEholdings) {
                    $eHoldings = $this->getEholdings($id, $holdingId, end(explode('/', $locationCodes)), $holdingCallNum, $holdingCallNumDisplay);
                    foreach ($eHoldings as $eHolding) {
                        $items[] = $eHolding;
                    }
                }
                
                /*Build a mock item for each of the holdings if no items exist*/
                if((!$hasItems or $hasHoldingNote) and (!empty($shelvingLocation))) { 
                    $item['id'] = $id; 
                    $item['location'] = $shelvingLocation; 
                    $item['callnumber'] = $holdingCallNum;
                    $item['holdingsNotes'] = $holdingNote; 
                    $item['availability'] = true;
                    $item['status'] = '';
                    $item['is_holdable'] = true;
                    /*UChicago Specific?*/
                    $item['callnumberDisplay'] = $holdingCallNumDisplay;
                    $item['locationCodes'] = $locationCodes;

                    /*Add mock items to the final return array*/
                    $items[] = $item;
                }

                /*Get summary holdigns and extent of ownership*/
                if ($hasExtOwnership) {
                    $summaryHoldings = $this->getSummaryHoldings($id, $holdingId, $shelvingLocation);
                    foreach($summaryHoldings as $summary) {
                        $items[] = $summary;
                    }                    
                } 

                /*Get individual item data*/           
                $oleItems = $this->getItems($id, $holdingId, $shelvingLocation, $locationCodes, $holdingCallNum, $holdingCallNumDisplay);
                foreach($oleItems as $oleItem) {
                    $items[] = $oleItem;
                }

                /*Get serials receiving data*/
                if ($hasUnboundItems) {
                    $unboundSerials = $this->getSerialReceiving($id, $holdingId, $shelvingLocation);
                    foreach($unboundSerials as $unboundItem) {
                        $items[] = $unboundItem;
                    }
                }
            }

        }
        catch (Exception $e){
            /*Do nothing*/
        }
        
        //print_r($items);
        return $items;
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $holdDetails An array of item and patron data
     *
     * @throws ILSException - TODO
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function placeHold($holdDetails)

    {
        //Recall/Delivery Request   //Recall Request
        //Recall/Hold Request       //Recall/Hold Request
        //Hold/Delivery Request     //Hold Request
        //Hold/Hold Request         //Hold/Hold Request
        //Page/Delivery Request     //Page Request
        //Page/Hold Request         //Page/Hold Request
        //Copy Request              //Copy Request
        //In Transit Request        //In Transit Request
        //ASR Request               //ASR Request
      
        $patron = $holdDetails['patron'];
        $patronId = $patron['id'];
        $service = 'placeRequest';
        $requestType = ($holdDetails['holdtype'] == "recall") ? urlencode('Recall/Hold Request'):urlencode('Hold/Delivery Request');
        $bibId = $holdDetails['id'];
        $itemBarcode = $holdDetails['barcode'];
        $patronBarcode = $patron['barcode'];
        
        $uri = $this->circService . "?service={$service}&patronBarcode={$patronBarcode}&operatorId={$this->operatorId}&itemBarcode={$itemBarcode}&requestType={$requestType}";
        var_dump($uri);
        
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setUri($uri);

        $client = new Client();
        $client->setOptions(array('timeout' => 30));
        try {
            $response = $client->dispatch($request);
        } catch (Exception $e) {
            throw new ILSException($e->getMessage());
        }

        // TODO: reimplement something like this when the API starts returning the proper http status code
        /*
        if (!$response->isSuccess()) {
            throw HttpErrorException::createFromResponse($response);
        }
        */
        
        /* TODO: this will always be 201 */
        //$statusCode = $response->getStatusCode();
        $content = $response->getBody();

        $xml = simplexml_load_string($content);
        $msg = $xml->xpath('//message');
        $code = $xml->xpath('//code');

        $success = ((string)$code[0] == '021') ? true:false;

        return $this->returnString($success, (string)$msg[0]);

    }

    /**
     * Hold Error
     *
     * Returns a Hold Error Message
     *
     * @param string $msg An error message string
     *
     * @return array An array with a success (boolean) and sysMessage key
     */
    protected function returnString($success,$msg)
    {
        return array(
                    "success" => $success,
                    "sysMessage" => $msg
        );
    }
    
    /* TODO: config this using options from OLE */
    public function getPickUpLocations($patron = false, $holdDetails = null)
    {

        $pickResponse[] = array(
            "locationID" => '1',
            "locationDisplay" => 'Location 1'
        );

        return $pickResponse;
    }
    
    /* TODO: document this */
    public function getDefaultPickUpLocation($patron = false, $holdDetails = null)
    {
        return $this->defaultPickUpLocation;
    }
    
    /**
     * Determine Renewability
     *
     * This is responsible for determining if an item is renewable
     *
     * @param string $patronId The user's patron ID
     * @param string $itemId   The Item Id of item
     *
     * @return mixed Array of the renewability status and associated
     * message
     */
     /* TODO: implement this with OLE data */
    protected function isRenewable($patronId, $itemId)
    {
        $renewData['message'] = "renable";
        $renewData['renewable'] = true;

        return $renewData;
    }

    /**
     * Support method for VuFind Hold Logic. Take an array of status strings
     * and determines whether or not an item is holdable based on the
     * valid_hold_statuses settings in configuration file
     *
     * @param array $statusArray The status codes to analyze.
     *
     * @return bool Whether an item is holdable
     */
     /* TODO: implement this with OLE data */
    protected function isHoldable($item)
    {
        // User defined hold behaviour
        $is_holdable = true;
        
        return $is_holdable;
    }
    
    /**
     * Get Renew Details
     *
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getRenewDetails($checkOutDetails)
    {
      //var_dump($checkOutDetails);
      $renewDetails = $checkOutDetails['item_id'] . ',' . $checkOutDetails['id'];
    //$renewDetails['item_id'] = $checkOutDetails['id'];
        return $renewDetails;
    }
    
    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @throws ILSException - TODO
     * @return array              An array of renewal information keyed by item ID
     */
     /* TODO: implement error messages from OLE once status codes are returned correctly
     HTTP/1.1 200 OK
     <renewItem>
      <message>Patron has more than $75 in Replacement Fee Charges. (OR) Patron has more than $150 in overall charges. (OR) The item has been renewed the maximum (1) number of times. (OR) </message>
    </renewItem>
    
     */
    public function renewMyItems($renewDetails)
    {

        $patron = $renewDetails['patron'];
        $patronId = $patron['id'];
        $patronBarcode = $patron['barcode'];
        
        $service = 'renewItem';
        
        $finalResult = array();
        
        foreach ($renewDetails['details'] as $key=>$details) {
          $details_arr = explode(',', $details);
          $itemBarcode = $details_arr[0];
          $item_id = $details_arr[1];

            $uri = $this->circService . "?service={$service}&patronBarcode={$patronBarcode}&operatorId={$this->operatorId}&itemBarcode={$itemBarcode}";

            $request = new Request();
            $request->setMethod(Request::METHOD_POST);
            $request->setUri($uri);

            $client = new Client();
            $client->setOptions(array('timeout' => 30));

            try {
                $response = $client->dispatch($request);
            } catch (Exception $e) { 
                throw new ILSException($e->getMessage());
            }
            
            // TODO: reimplement something like this when the API starts returning the proper http status code
            /*
            if (!$response->isSuccess()) {
                throw HttpErrorException::createFromResponse($response);
            }
            */
        
            $content = $response->getBody();
            $xml = simplexml_load_string($content);
            $msg = $xml->xpath('//message');
            $code = $xml->xpath('//code');
            $code = (string)$code[0];
            
            $success = false;
            
            // TODO: base "success" on the returned codes from OLE
            if ($code == '003') {
                $success = true;
            }
            $finalResult['details'][$itemBarcode] = array(
                                "success" => $success,
                                "new_date" => false,
                                "item_id" => $itemBarcode,
                                "sysMessage" => (string)$msg[0]
                                );
            
        }
        //var_dump($finalResult);
        return $finalResult;
    }
    
    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @throws ILSException
     * @return array     An array with the acquisitions data on success.
     */
    public function getPurchaseHistory($id)
    {
        // TODO
        return array();
    }
}
