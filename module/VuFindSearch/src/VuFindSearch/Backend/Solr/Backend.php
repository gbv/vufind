<?php
/**
 * SOLR backend.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
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
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
namespace VuFindSearch\Backend\Solr;

use VuFindSearch\Query\AbstractQuery;
use VuFindSearch\Query\Query;

use VuFindSearch\ParamBag;

use VuFindSearch\Response\RecordCollectionInterface;
use VuFindSearch\Response\RecordCollectionFactoryInterface;

use VuFindSearch\Backend\Solr\Response\Json\Terms;

use VuFindSearch\Backend\BackendInterface;
use VuFindSearch\Feature\MoreLikeThis;
use VuFindSearch\Feature\RetrieveBatchInterface;

use Zend\Log\LoggerInterface;

use VuFindSearch\Backend\Exception\BackendException;
use VuFindSearch\Backend\Exception\RemoteErrorException;

use VuFindSearch\Exception\InvalidArgumentException;

/**
 * SOLR backend.
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class Backend implements BackendInterface, MoreLikeThis, RetrieveBatchInterface
{
    /**
     * Record collection factory.
     *
     * @var RecordCollectionFactoryInterface
     */
    protected $collectionFactory;

    /**
     * Dictionaries for spellcheck.
     *
     * @var array
     */
    protected $dictionaries;

    /**
     * Logger, if any.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Connector.
     *
     * @var Connector
     */
    protected $connector;

    /**
     * Backend identifier.
     *
     * @var string
     */
    protected $identifier;

    /**
     * Query builder.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Constructor.
     *
     * @param Connector $connector SOLR connector
     *
     * @return void
     */
    public function __construct(Connector $connector)
    {
        $this->connector    = $connector;
        $this->dictionaries = array();
        $this->identifier   = null;
    }

    /**
     * Set the backend identifier.
     *
     * @param string $identifier Backend identifier
     *
     * @return void
     */
    public function setIdentifier($identifier)
    {
        $this->identifier = $identifier;
    }

    /**
     * Set the spellcheck dictionaries to use.
     *
     * @param array $dictionaries Spellcheck dictionaries
     *
     * @return void
     */
    public function setDictionaries(array $dictionaries)
    {
        $this->dictionaries = $dictionaries;
    }


    /**
     * Perform a search and return record collection.
     *
     * @param AbstractQuery $query  Search query
     * @param integer       $offset Search offset
     * @param integer       $limit  Search limit
     * @param ParamBag      $params Search backend parameters
     *
     * @return RecordCollectionInterface
     */
    public function search(AbstractQuery $query, $offset, $limit,
        ParamBag $params = null
    ) {
        $params = $params ?: new ParamBag();
        $this->injectResponseWriter($params);

        if ($params->get('spellcheck.q')) {
            if (!empty($this->dictionaries)) {
                reset($this->dictionaries);
                $params->set('spellcheck', 'true');
                $params->set('spellcheck.dictionary', current($this->dictionaries));
            } else {
                $this->log(
                    'warn',
                    'Spellcheck requested but no spellcheck dictionary configured'
                );
            }
        }

        $response   = $this->connector
            ->search($query, $offset, $limit, $this->getQueryBuilder(), $params);
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);

        // Submit requests for more spelling suggestions
        while (next($this->dictionaries) !== false) {
            $prev = $this->connector->getLastQueryParameters();
            // Bypass secondary spell check if initial query disabled it:
            if (is_array($prev->get('spellcheck'))
                && current($prev->get('spellcheck')) == 'true'
            ) {
                $next = new ParamBag(
                    array('q' => '*:*', 'spellcheck' => 'true', 'rows' => 0)
                );
                $this->injectResponseWriter($next);
                $next->mergeWith($this->connector->getQueryInvariants());
                $next->set('spellcheck.q', $prev->get('spellcheck.q'));
                $next->set('spellcheck.dictionary', current($this->dictionaries));
                $response   = $this->connector->resubmit($next);
                $spellcheck = $this->createRecordCollection($response);
                $collection->getSpellcheck()
                    ->mergeWith($spellcheck->getSpellcheck());
            }
        }

        return $collection;
    }

    /**
     * Retrieve a single document.
     *
     * @param string   $id     Document identifier
     * @param ParamBag $params Search backend parameters
     *
     * @return RecordCollectionInterface
     */
    public function retrieve($id, ParamBag $params = null)
    {
        $params = $params ?: new ParamBag();
        $this->injectResponseWriter($params);

        $response   = $this->connector->retrieve($id, $params);
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Retrieve a batch of documents.
     *
     * @param array    $ids    Array of document identifiers
     * @param ParamBag $params Search backend parameters
     *
     * @return \VuFindSearch\Response\RecordCollectionInterface
     */
    public function retrieveBatch($ids, ParamBag $params = null)
    {
        // Load 100 records at a time; this is a good number to avoid memory
        // problems while still covering a lot of ground.
        $pageSize = 100;

        // Callback function for formatting IDs:
        $formatIds = function ($i) {
            return '"' . addcslashes($i, '"') . '"';
        };

        // Retrieve records a page at a time:
        $results = false;
        while (count($ids) > 0) {
            $currentPage = array_splice($ids, 0, $pageSize, array());
            $currentPage = array_map($formatIds, $currentPage);
            $query = new Query('id:(' . implode(' OR ', $currentPage) . ')');
            $next = $this->search($query, 0, $pageSize);
            if (!$results) {
                $results = $next;
            } else {
                foreach ($next->getRecords() as $record) {
                    $results->add($record);
                }
            }
        }

        return $results;
    }

    /**
     * Return similar records.
     *
     * @param string   $id     Id of record to compare with
     * @param ParamBag $params Search backend parameters
     *
     * @return RecordCollectionInterface
     */
    public function similar($id, ParamBag $params = null)
    {
        $params = $params ?: new ParamBag();
        $this->injectResponseWriter($params);

        $response   = $this->connector->similar($id, $params);
        $collection = $this->createRecordCollection($response);
        $this->injectSourceIdentifier($collection);
        return $collection;
    }

    /**
     * Return terms from SOLR index.
     *
     * @param string   $field  Index field
     * @param string   $start  Starting term (blank for beginning of list)
     * @param int      $limit  Maximum number of terms
     * @param ParamBag $params Additional parameters
     *
     * @return Terms
     */
    public function terms($field, $start, $limit, ParamBag $params = null)
    {
        $params = $params ?: new ParamBag();
        $this->injectResponseWriter($params);

        $params->set('terms', 'true');
        $params->set('terms.fl', $field);
        $params->set('terms.lower', $start);
        $params->set('terms.limit', $limit);
        $params->set('terms.lower.incl', 'false');
        $params->set('terms.sort', 'index');

        $response = $this->connector->query('term', $params);
        $terms    = new Terms($this->deserialize($response));
        return $terms;
    }

    /**
     * Obtain information from an alphabetic browse index.
     *
     * @param string   $source Name of index to search
     * @param string   $from   Starting point for browse results
     * @param int      $page   Result page to return (starts at 0)
     * @param int      $limit  Number of results to return on each page
     * @param ParamBag $params Additional parameters
     * POST)
     *
     * @return array
     */
    public function alphabeticBrowse($source, $from, $page, $limit = 20,
        $params = null
    ) {
        $params = $params ?: new ParamBag();
        $this->injectResponseWriter($params);

        $params->set('from', $from);
        $params->set('offset', $page * $limit);
        $params->set('rows', $limit);
        $params->set('source', $source);

        try {
            $response = $this->connector->query('browse', $params);
        } catch (RemoteErrorException $e) {
            $this->refineBrowseException($e);
        }
        return $this->deserialize($response);
    }

    /**
     * Set the Logger.
     *
     * @param LoggerInterface $logger Logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Return query builder.
     *
     * Lazy loads an empty QueryBuilder if none was set.
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder()
    {
        if (!$this->queryBuilder) {
            $this->queryBuilder = new QueryBuilder();
        }
        return $this->queryBuilder;
    }

    /**
     * Set the query builder.
     *
     * @param QueryBuilder $queryBuilder Query builder
     *
     * @return void
     *
     * @todo Typehint QueryBuilderInterface
     */
    public function setQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Return backend identifier.
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * Set the record collection factory.
     *
     * @param RecordCollectionFactoryInterface $factory Factory
     *
     * @return void
     */
    public function setRecordCollectionFactory(
        RecordCollectionFactoryInterface $factory
    ) {
        $this->collectionFactory = $factory;
    }

    /**
     * Return the record collection factory.
     *
     * Lazy loads a generic collection factory.
     *
     * @return RecordCollectionFactoryInterface
     */
    public function getRecordCollectionFactory()
    {
        if (!$this->collectionFactory) {
            $this->collectionFactory = new Response\Json\RecordCollectionFactory();
        }
        return $this->collectionFactory;
    }

    /**
     * Return the SOLR connector.
     *
     * @return Connector
     */
    public function getConnector()
    {
        return $this->connector;
    }

    /// Internal API

    /**
     * Inject source identifier in record collection and all contained records.
     *
     * @param ResponseInterface $response Response
     *
     * @return void
     */
    protected function injectSourceIdentifier(RecordCollectionInterface $response)
    {
        $response->setSourceIdentifier($this->identifier);
        foreach ($response as $record) {
            $record->setSourceIdentifier($this->identifier);
        }
        return $response;
    }

    /**
     * Send a message to the logger.
     *
     * @param string $level   Log level
     * @param string $message Log message
     * @param array  $context Log context
     *
     * @return void
     */
    protected function log($level, $message, array $context = array())
    {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }

    /**
     * Create record collection.
     *
     * @param string $json Serialized JSON response
     *
     * @return RecordCollectionInterface
     */
    protected function createRecordCollection($json)
    {
        return $this->getRecordCollectionFactory()
            ->factory($this->deserialize($json));
    }

    /**
     * Deserialize JSON response.
     *
     * @param string $json Serialized JSON response
     *
     * @return array
     *
     * @throws BackendException Deserialization error
     */
    protected function deserialize($json)
    {
        $response = json_decode($json, true);
        $error    = json_last_error();
        if ($error != \JSON_ERROR_NONE) {
            throw new BackendException(
                sprintf('JSON decoding error: %s -- %s', $error, $json)
            );
        }
        $qtime = isset($response['responseHeader']['QTime'])
            ? $response['responseHeader']['QTime'] : 'n/a';
        $this->log('debug', 'Deserialized SOLR response', array('qtime' => $qtime));
        return $response;
    }

    /**
     * Improve the exception message for alphaBrowse errors when appropriate.
     *
     * @param RemoteErrorException $e Exception to clean up
     *
     * @return void
     * @throws RemoteErrorException
     */
    protected function refineBrowseException(RemoteErrorException $e)
    {
        $error = $e->getMessage();
        if (strstr($error, 'does not exist') || strstr($error, 'no such table')
            || strstr($error, 'couldn\'t find a browse index')
        ) {
            throw new RemoteErrorException(
                "Alphabetic Browse index missing.  See " .
                "http://vufind.org/wiki/alphabetical_heading_browse for " .
                "details on generating the index.",
                $e->getCode()
            );
        }
        throw $e;
    }

    /**
     * Inject response writer and named list implementation into parameters.
     *
     * @param ParamBag $params Parameters
     *
     * @return void
     *
     * @throws InvalidArgumentException Response writer and named list
     * implementation already set to an incompatible type.
     */
    protected function injectResponseWriter(ParamBag $params)
    {
        if (array_diff($params->get('wt') ?: array(), array('json'))) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid response writer type: %s',
                    print_r($params->get('wt'), true)
                )
            );
        }
        if (array_diff($params->get('json.nl') ?: array(), array('arrarr'))) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid named list implementation type: %s',
                    print_r($params->get('json.nl'), true)
                )
            );
        }
        $params->set('wt', array('json'));
        $params->set('json.nl', array('arrarr'));
    }
}