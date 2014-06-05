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
					"mysql:host=" . $this->config['Catalog']['host'] . ";port=" . $this->config['Catalog']['port'] . ";dbname=" . $this->config['Catalog']['database'],
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
		$uri = $this->circService . '?service=lookupUser&patronBarcode=' . $patron['barcode'] . '&operatorId=API';

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

		$patron['email'] = '';
		$patron['address1'] = '';
		$patron['address2'] = null;
		$patron['zip'] = '';
		$patron['phone'] = '';
		$patron['group'] = '';
		
		if (!empty($xml->patronName->firstName)) {
			$patron['firstname'] = utf8_encode($xml->patronName->firstName);
		}
		if (!empty($xml->patronName->lastName)) {
			$patron['lastname'] = utf8_encode($xml->patronName->lastName);
		}
		if (!empty($xml->patronEmail->emailAddress)) {
			$patron['email'] = utf8_encode($xml->patronEmail->emailAddress);
		}
		if (!empty($xml->patronAddress->line1)) {
			$patron['address1'] = utf8_encode($xml->patronAddress->line1);
		}
		if (!empty($xml->patronAddress->line2)) {
			$patron['address2'] = utf8_encode($xml->patronAddress->line2);
		}
		if (!empty($xml->patronAddress->postalCode)) {
			$patron['zip'] = utf8_encode($xml->patronAddress->postalCode);
		}
		if (!empty($xml->patronPhone->phoneNumber)) {
			$patron['phone'] = utf8_encode($xml->patronPhone->phoneNumber);
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
    public function getMyTransactions($patron)
    {

        $transList = array();

		$uri = $this->circService . '?service=getCheckedOutItems&patronBarcode=' . $patron['barcode'] . '&operatorId=API';
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
		
		$content_str = $response->getBody();
		$xml = simplexml_load_string($content_str);
		
		$code = $xml->xpath('//code');
		$code = (string)$code[0][0];

		if ($code == '000') {
			$checkedOutItems = $xml->xpath('//checkOutItem');
			
			foreach($checkedOutItems as $item) {
				$processRow = $this->processMyTransactionsData($item, $patron);
				$transList[] = $processRow;
			}
		}
		//var_dump($transList);
		
		return $transList;

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

		$uri = $this->circService . '?service=fine&patronBarcode=' . $patron['barcode'] . '&operatorId=API';
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

		$recordId = (string)$itemXml->catalogueId;
		
		$record = $this->getRecord($recordId);

        return array(
				 'amount' => (string)$itemXml->amount,
				 'fine' => (string)$itemXml->reason,
				 'balance' => (string)$itemXml->balance,
				 'createdate' => (string)$itemXml->dateCharged,
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
		
		$uri = $this->circService . '?service=holds&patronBarcode=' . $patron['barcode'] . '&operatorId=API';
		//var_dump($uri);
		
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

    /**
     * Protected support method for getMyTransactions.
     *
     * @param array $itemXml simplexml object of item data
     * @param array $patron array
     *
     * @throws DateException - TODO
     * @return array Keyed data for display by template files
     */
    protected function processMyTransactionsData($itemXml, $patron = false)
    {

        $dueDate = substr((string) $itemXml->dueDate, 0, 10);
        $dueTime = substr((string) $itemXml->dueDate, 11);
		
        $loanedDate = substr((string) $itemXml->loanDate, 0, 10);
        $loanedTime = substr((string) $itemXml->loanDate, 11);
		
        $dueStatus = ((string) $itemXml->overDue == 'true') ? "overdue" : "";
        $numberOfRenewals = (string) $itemXml->numberOfRenewals;
		
        $transactions = array(
            'id' => substr((string) $itemXml->catalogueId, strpos((string) $itemXml->catalogueId, '-')+1),
            'item_id' => (string) $itemXml->itemId,
            'duedate' => $dueDate,
            'dueTime' => $dueTime,
            'loanedDate' => $loanedDate,
            'loanedTime' => $loanedTime,
            'dueStatus' => $dueStatus,
            'volume' => '',
            'publication_year' => '',
			'renew' => $numberOfRenewals,
            'title' => strlen((string) $itemXml->title)
                ? (string) $itemXml->title : "unknown title"
        );
		$renewData = $this->checkRenewalsUpFront
            ? $this->isRenewable($patron['id'], $transactions['item_id'])
            : array('message' => 'renewable', 'renewable' => true);

        $transactions['renewable'] = $renewData['renewable'];
        $transactions['message'] = $renewData['message'];
		
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
		$items = array();
		$items = $this->getHolding($id);

        return $items;
    }
	
	/**
	 * TODO: document this
	 *
	 */
	public function getItemStatus($itemXML) {

		$status = $itemXML->children('circ', true)->itemStatus->children()->fullValue;
		// TODO: enable all item statuses
		$available = ($status != 'LOANED') ? true:false;

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
    public function getHolding($id, $patron = false)
    {
		
		//$uri = $this->solrService . "?q=bibIdentifier:" . $this->bibPrefix . $id . "%20AND%20DocType:holdings&wt=json&rows=100000";
		$uri = $this->docService . "/holdings/tree?bibId={$id}";
		//var_dump($uri);
		
		$request = new Request();
		$request->setMethod(Request::METHOD_GET);
		$request->setUri($uri);
		$request->getHeaders()->addHeaders(array(
			'Accept' => 'application/json'
		));

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
		$holdingsJSON = Json::decode($content);
		
		
		
		$items = array();

		foreach($holdingsJSON->holdingsTrees as $tree) {
			//var_dump($holding);
			
			$holding = $tree->oleHoldings;
			
			$location = (string)$holding->location->locationLevel->name;
			$callNumber = (string)$holding->callNumber->number;
			$holdingsIdentifier = (string)$holding->holdingsIdentifier;
			
			foreach($holding->extentOfOwnership as $summary) {
				//var_dump($summary);
				
				$itemIdentifier = "";
				$barcode = "";
				$copyNumber = "";
				$enumeration = "";
				$status = "AVAILABLE";
				$bibIdentifier = $id;
				$available = ($status != 'LOANED') ? true:false;

				//var_dump($summary);
				$item['id'] = str_replace($this->bibPrefix,"",$bibIdentifier);
				$item['item_id'] = str_replace($this->itemPrefix,"",$itemIdentifier);
				$item['availability'] = $available;
				$item['status'] = $status;
				$item['location'] = $location;
				$item['reserve'] = '';
				$item['callnumber'] = $callNumber;
				//$item['duedate']
				$item['returnDate'] = '';
				$item['number'] = $copyNumber . " : " . $enumeration;
				$item['requests_placed'] = '';
				$item['barcode'] = $barcode;
				$item['notes'] = array($summary->note[0]->value);
				$item['summary'] = array($summary->textualHoldings);
				$item['is_holdable'] = true;
				$item['holdtype'] = 'hold';
				$item['addLink'] = true;
				
				$items[] = $item;
				
			}

			foreach($tree->items->item as $oleItem) {

				$itemIdentifier = (string)$oleItem->itemIdentifier;
				$barcode = (string)$oleItem->accessInformation->barcode;
				$copyNumber = (string)$oleItem->copyNumber;
				$enumeration = (string)$oleItem->enumeration;
				$status = (string)$oleItem->itemStatus->codeValue;
				$bibIdentifier = $id;
				$available = ($status == 'AVAILABLE') ? true:false;
				$holdtype = ($available == true) ? "hold":"recall";

				$item['id'] = str_replace($this->bibPrefix,"",$bibIdentifier);
				$item['item_id'] = str_replace($this->itemPrefix,"",$itemIdentifier);
				$item['availability'] = $available;
				$item['status'] = $status;
				$item['location'] = $location;
				$item['reserve'] = '';
				$item['callnumber'] = $callNumber;
				//$item['duedate']
				$item['returnDate'] = '';
				$item['number'] = $copyNumber . " : " . $enumeration;
				$item['requests_placed'] = '';
				$item['barcode'] = $barcode;
				$item['notes'] = "";
				$item['summary'] = '';
				$item['is_holdable'] = true;
				$item['holdtype'] = $holdtype;
				$item['addLink'] = true;
				
				$items[] = $item;
				
			}

		}

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
		//Recall/Delivery Request	//Recall Request
		//Recall/Hold Request		//Recall/Hold Request
		//Hold/Delivery Request		//Hold Request
		//Hold/Hold Request			//Hold/Hold Request
		//Page/Delivery Request		//Page Request
		//Page/Hold Request			//Page/Hold Request
		//Copy Request				//Copy Request
		//In Transit Request		//In Transit Request
		//ASR Request				//ASR Request
		
		$patron = $holdDetails['patron'];
		$patronId = $patron['id'];
		$operatorId = 'API';
		$service = 'placeRequest';
		$requestType = ($holdDetails['holdtype'] == "recall") ? urlencode('Recall/Hold Request'):urlencode('Hold/Delivery Request');
		$bibId = $holdDetails['id'];
		$itemBarcode = $holdDetails['barcode'];
		$patronBarcode = $patron['barcode'];
		
		$uri = $this->circService . "?service={$service}&patronBarcode={$patronBarcode}&operatorId={$operatorId}&itemBarcode={$itemBarcode}&requestType={$requestType}";
		
		//var_dump($uri);
		
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
		
		// TODO: API account can not renew this item
		//$operatorId = 'API';
		$operatorId = 'API';
		
		$service = 'renewItem';
		
		$finalResult = array();
		
		foreach ($renewDetails['details'] as $key=>$details) {
		  $details_arr = explode(',', $details);
		  $itemBarcode = $details_arr[0];
		  $item_id = $details_arr[1];

			$uri = $this->circService . "?service={$service}&patronBarcode={$patronBarcode}&operatorId={$operatorId}&itemBarcode={$itemBarcode}";

			//var_dump($uri);
			
			$request = new Request();
			$request->setMethod(Request::METHOD_POST);
			$request->setUri($uri);

			$client = new Client();

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
