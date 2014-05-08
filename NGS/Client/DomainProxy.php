<?php
namespace NGS\Client;

require_once(__DIR__.'/../Utils.php');
require_once(__DIR__.'/RestHttp.php');
require_once(__DIR__.'/../Converter/PrimitiveConverter.php');
require_once(__DIR__.'/../Patterns/Repository.php');


use NGS\Converter\PrimitiveConverter;
use NGS\Name;
use NGS\Patterns\AggregateDomainEvent;
use NGS\Patterns\DomainEvent;
use NGS\Patterns\IDomainObject;
use NGS\Patterns\Repository;
use NGS\Patterns\Specification;

/**
 * Proxy service to remote REST-like API for basic domain operations
 * such as searching, counting and event sourcing.
 * It is preferred to use domain patterns instead of this proxy service.
 */
class DomainProxy
{
    const DOMAIN_URI = 'Domain.svc';

    /**
     * @var RestHttp Instance of RestHttp client
     */
    protected $http;

    /**
     * @var DomainProxy Singleton instance
     */
    protected static $instance;

    /**
     * Create a new DomainProxy instance
     *
     * @param RestHttp $http RestHttp instance used for http request.
     * Optionally specify an instance, otherwise use a singleton instance
     */
    public function __construct(RestHttp $http = null)
    {
        $this->http = $http !== null ? $http : RestHttp::instance();
    }

    /**
     * Gets singleton instance of Domain.svc proxy
     *
     * @return DomainProxy
     */
    public static function instance()
    {
        if(self::$instance === null)
            self::$instance = new DomainProxy();
        return self::$instance;
    }

    /**
     * Returns an array of domain objects uniquely represented with their URIs.
     * Only found objects will be returned (array is empty if no objects are found).
     *
     * Example:<br>
     * <code>
     * $proxy = DomainProxy::instance();
     * $proxy->find('Test\\Item', array('uri1', 'uri2'));
     * </code>
     *
     * @param string $class Domain object class name or instance
     * @param array $uris Array of string URIs
     * @return array Array of found objects or empty array if none found
     */
    public function find($class, array $uris)
    {
        $name = $this->http->getDslName($class);
        $body = json_encode(PrimitiveConverter::toStringArray($uris));
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/find/'.rawurlencode($name),
                'PUT',
                $body,
                array(200));
        return RestHttp::parseResult($response, $class);
    }

    /**
     * Returns an array of all domain objects
     * with up to $limit results.
     *
     * Example:<br>
     * <code>
     * $proxy = DomainProxy::instance();
     * $proxy->search('Test\\Item', 10, 20, array('title' => true));
     * </code>
     *
     * @param string $class Domain object class name or instance
     * @param int $limit Limits maximum number of returned results
     * @param int $offset Offset results by this number
     * @param array $order Array of key=>value pairs: keys are property names,
     * values determine sorting direction: true=ascending, false=descending
     * @return array Array of found objects or empty array if none found
     */
    public function search(
        $class,
        $limit = null,
        $offset = null,
        array $order = null)
    {
        $name = $this->http->getDslName($class);
        $lo = QueryString::formatLimitAndOffsetAndOrder($limit, $offset, $order);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/search/'.rawurlencode($name).($lo ? '?'.$lo : ''),
                'GET',
                null,
                array(200));
        return RestHttp::parseResult($response, $class);
    }

    /**
     * Returns a list of domain objects satisfying {@see NGS\Patterns\Specification}
     * with up to $limit results.
     *
     * @param string        $class
     * @param Specification $specification Specification instance used for searching
     * @param type          $limit
     * @param type          $offset
     * @param array         $order
     * @return array Array of found objects or empty array if none found
     */
    public function searchWithSpecification(
        $class,
        Specification $specification,
        $limit = null,
        $offset = null,
        array $order = null)
    {
        $objectName = $this->http->getDslName($class);
        $specName = $this->http->getDslModuleName($specification).'+'.$this->http->getDslObjectName($specification);
        $lo = QueryString::formatLimitAndOffsetAndOrder($limit, $offset, $order);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/search/'
                    .rawurlencode($objectName)
                    .'?specification='.rawurlencode($specName)
                    .($lo ? '&'.$lo : ''),
                'PUT',
                $specification->toJson(),
                array(200));
        return RestHttp::parseResult($response, $this->http->getClassName($objectName));
    }

    /**
     * Returns a list of domain objects satisfying conditions in {@see NGS\Patterns\GenericSearch}
     *
     * @param string|IDomainObject $class
     * @param array $filters
     * @param type $limit
     * @param type $offset
     * @param array $order
     * @return type
     */
    public function searchGeneric(
        $class,
        array $filters = null,
        $limit = null,
        $offset = null,
        array $order = null)
    {
        $object = $this->http->getDslName($class);
        $lo = QueryString::formatLimitAndOffsetAndOrder($limit, $offset, $order);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/search-generic/'.rawurlencode($object).($lo ? '?'.$lo : ''),
                'PUT',
                json_encode($filters),
                array(200));
        return RestHttp::parseResult($response, $this->http->getClassName($object));
    }

    /**
     * Count total number of domain objects satisfying conditions in {@see NGS\Patterns\GenericSearch}
     *
     * @param type $class
     * @param array $filters
     * @return type
     */
    public function countGeneric(
        $class,
        array $filters = null)
    {
        $object = $this->http->getDslName($class);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/count-generic/'.rawurlencode($object),
                'PUT',
                json_encode($filters),
                array(200));
        return RestHttp::parseResult($response, $this->http->getClassName($object));
    }

    /**
     * Returns a total number of domain objects.
     *
     * @param string $class
     * @return integer Total number of objects
     */
    public function count($class)
    {
        $name = $this->http->getDslName($class);
        $response = $this->http->sendRequest(
            self::DOMAIN_URI.'/count/'.rawurlencode($name),
            'GET',
            null,
            array(200));
        $count = RestHttp::parseResult($response);
        return PrimitiveConverter::toInteger($count);
    }

    /**
     * Count number of domain objects satisfying {@see NGS\Patterns\Specification}
     *
     * @param Specification $specification
     * @return int Total number of objects
     */
    public function countWithSpecification(Specification $specification)
    {
        $object = $this->http->getDslModuleName($specification);
        $name = $this->http->getDslObjectName($specification);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/count/'.rawurlencode($object).'?specification='.rawurlencode($name),
                'PUT',
                $specification->toJson(),
                array(200));
        $count = RestHttp::parseResult($response);
        return PrimitiveConverter::toInteger($count);
    }

    /**
     * Send domain event to the server. Server will return identity under which it was stored.
     * Events can't be modified once they are submitted. Only new events can be created.
     *
     * @param DomainEvent $event Event to be executed
     * @param bool $returnInstance Whether to return event instance or event URI
     * @return mixed Event instance or URI string
     */
    public function submitEvent(DomainEvent $event, $returnInstance=false)
    {
        $name = $this->http->getDslName($event);
        $returnResult = $returnInstance ? 'instance' : 'uri';
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/submit/'.rawurlencode($name).'?result='.$returnResult,
                'POST',
                $event->toJson(),
                array(201));
        if ($returnInstance) {
            return RestHttp::parseResult($response, $this->http->getClassName($event));
        }
        $uri = RestHttp::parseResult($response);
        return PrimitiveConverter::toString($uri);
    }

    /**
     * Apply domain event to a single aggregate. Server will return modified aggregate root.
     * Events can't be modified once they are submitted. Only new events can be created.
     *
     * @param AggregateDomainEvent $event
     * @param string $uri URI of aggregate root
     * @return \NGS\Patterns\AggregateRoot Aggregate on which event was applied
     */
    public function submitAggregateEvent(AggregateDomainEvent $event, $uri)
    {
        $rootName = $this->http->getDslModuleName($event);
        $eventName = $this->http->getDslObjectName($event);
        $response =
            $this->http->sendRequest(
                self::DOMAIN_URI.'/submit/'.rawurlencode($rootName).'/'.rawurlencode($eventName).'?uri='.rawurlencode($uri),
                'POST',
                $event->toJson(),
                array(201));
        Repository::instance()->invalidate($rootName, $uri);
        return RestHttp::parseResult($response, $this->http->getClassName($rootName));
    }

}
