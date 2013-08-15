<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\MongoDB\Query;

use Doctrine\MongoDB\Collection;
use Doctrine\MongoDB\Cursor;
use Doctrine\MongoDB\Database;
use Doctrine\MongoDB\EagerCursor;
use Doctrine\MongoDB\Iterator;
use Doctrine\MongoDB\IteratorAggregate;
use BadMethodCallException;
use InvalidArgumentException;

/**
 * Query class used in conjunction with the Builder class for executing queries
 * or commands and returning results.
 *
 * @since  1.0
 * @author Jonathan H. Wage <jonwage@gmail.com>
 * @author Bulat Shakirzyanov <mallluhuct@gmail.com>
 */
class Query implements IteratorAggregate
{
    const TYPE_FIND            = 1;
    const TYPE_FIND_AND_UPDATE = 2;
    const TYPE_FIND_AND_REMOVE = 3;
    const TYPE_INSERT          = 4;
    const TYPE_UPDATE          = 5;
    const TYPE_REMOVE          = 6;
    const TYPE_GROUP           = 7;
    const TYPE_MAP_REDUCE      = 8;
    const TYPE_DISTINCT        = 9;
    const TYPE_GEO_NEAR        = 10;
    const TYPE_COUNT           = 11;

    /**
     * The Database instance.
     *
     * @var Database
     */
    protected $database;

    /**
     * The Collection instance.
     *
     * @var Collection
     */
    protected $collection;

    /**
     * Query structure generated by the Builder class.
     *
     * @var array
     */
    protected $query;

    /**
     * Mongo command prefix
     *
     * @var string
     */
    protected $cmd;

    /**
     * @var Iterator
     */
    protected $iterator;

    /**
     * Query options
     *
     * @var array
     */
    protected $options;

    /**
     * Constructor.
     *
     * @param Database $database
     * @param Collection $collection
     * @param array $query
     * @param array $options
     * @param string $cmd
     * @throws InvalidArgumentException if query type is invalid
     */
    public function __construct(Database $database, Collection $collection, array $query, array $options, $cmd)
    {
        switch ($query['type']) {
            case self::TYPE_FIND:
            case self::TYPE_FIND_AND_UPDATE:
            case self::TYPE_FIND_AND_REMOVE:
            case self::TYPE_INSERT:
            case self::TYPE_UPDATE:
            case self::TYPE_REMOVE:
            case self::TYPE_GROUP:
            case self::TYPE_MAP_REDUCE:
            case self::TYPE_DISTINCT:
            case self::TYPE_GEO_NEAR:
            case self::TYPE_COUNT:
                break;

            default:
                throw new InvalidArgumentException('Invalid query type: ' . $query['type']);
        }

        $this->database   = $database;
        $this->collection = $collection;
        $this->query      = $query;
        $this->cmd        = $cmd;
        $this->options    = $options;
    }

    /**
     * Count the number of results for this query.
     *
     * If the query resulted in a Cursor, the $foundOnly parameter will ignore
     * limit/skip values if false (the default). If the Query resulted in an
     * EagerCursor or ArrayIterator, the $foundOnly parameter has no effect.
     *
     * @param boolean $foundOnly
     * @return integer
     */
    public function count($foundOnly = false)
    {
        return $this->getIterator()->count($foundOnly);
    }

    /**
     * Return an array of information about the query structure for debugging.
     *
     * The $name parameter may be used to return a specific key from the
     * internal $query array property. If omitted, the entire array will be
     * returned.
     *
     * @param string $name
     * @return mixed
     */
    public function debug($name = null)
    {
        return $name !== null ? $this->query[$name] : $this->query;
    }

    /**
     * Execute the query and return its result.
     *
     * The return value will vary based on the query type. Commands with results
     * (e.g. aggregate, inline mapReduce) may return an ArrayIterator. Other
     * commands and operations may return a status array or a boolean, depending
     * on the driver's write concern. Queries and some mapReduce commands will
     * return a Cursor.
     *
     * @return mixed
     */
    public function execute()
    {
        $options = $this->options;

        switch ($this->query['type']) {
            case self::TYPE_FIND:
                $cursor = $this->collection->find(
                    $this->query['query'],
                    isset($this->query['select']) ? $this->query['select'] : array()
                );

                return $this->prepareCursor($cursor);

            case self::TYPE_FIND_AND_UPDATE:
                return $this->collection->findAndUpdate(
                    $this->query['query'],
                    $this->query['newObj'],
                    array_merge($options, $this->getQueryOptions('new', 'select', 'sort', 'upsert'))
                );

            case self::TYPE_FIND_AND_REMOVE:
                return $this->collection->findAndRemove(
                    $this->query['query'],
                    array_merge($options, $this->getQueryOptions('select', 'sort'))
                );

            case self::TYPE_INSERT:
                return $this->collection->insert($this->query['newObj'], $options);

            case self::TYPE_UPDATE:
                return $this->collection->update(
                    $this->query['query'],
                    $this->query['newObj'],
                    array_merge($options, $this->getQueryOptions('multiple', 'upsert'))
                );

            case self::TYPE_REMOVE:
                return $this->collection->remove($this->query['query'], $options);

            case self::TYPE_GROUP:
                if ( ! empty($this->query['query'])) {
                    $options['cond'] = $this->query['query'];
                }

                return $this->collection->group(
                    $this->query['group']['keys'],
                    $this->query['group']['initial'],
                    $this->query['group']['reduce'],
                    array_merge($options, $this->query['group']['options'])
                );

            case self::TYPE_MAP_REDUCE:
                if (isset($this->query['limit'])) {
                    $options['limit'] = $this->query['limit'];
                }

                $results = $this->collection->mapReduce(
                    $this->query['mapReduce']['map'],
                    $this->query['mapReduce']['reduce'],
                    $this->query['mapReduce']['out'],
                    $this->query['query'],
                    array_merge($options, $this->query['mapReduce']['options'])
                );

                return ($results instanceof Cursor) ? $this->prepareCursor($results) : $results;

            case self::TYPE_DISTINCT:
                return $this->collection->distinct($this->query['distinct'], $this->query['query'], $options);

            case self::TYPE_GEO_NEAR:
                if (isset($this->query['limit'])) {
                    $options['num'] = $this->query['limit'];
                }

                return $this->collection->near(
                    $this->query['geoNear']['near'],
                    $this->query['query'],
                    array_merge($options, $this->query['geoNear']['options'])
                );

            case self::TYPE_COUNT:
                return $this->collection->count($this->query['query']);
        }
    }

    /**
     * Execute the query and return its result, which must be an Iterator.
     *
     * @see http://php.net/manual/en/iteratoraggregate.getiterator.php
     * @return Iterator
     * @throws BadMethodCallException if the query did not return an Iterator
     */
    public function getIterator()
    {
        if ($this->iterator === null) {
            $iterator = $this->execute();
            if ($iterator !== null && !$iterator instanceof Iterator) {
                throw new BadMethodCallException('Query execution did not return an iterator. This query may not support returning iterators.');
            }
            $this->iterator = $iterator;
        }
        return $this->iterator;
    }

    /**
     * Return the query structure.
     *
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Execute the query and return the first result.
     *
     * @see Iterator::getSingleResult()
     * @return array|object|null
     */
    public function getSingleResult()
    {
        return $this->getIterator()->getSingleResult();
    }

    /**
     * Return the query type.
     *
     * @return integer
     */
    public function getType()
    {
        return $this->query['type'];
    }

    /**
     * Alias of {@link Query::getIterator()}.
     *
     * @return Iterator
     */
    public function iterate()
    {
        return $this->getIterator();
    }

    /**
     * Execute the query and return its results as an array.
     *
     * @see Iterator::toArray()
     * @return array
     */
    public function toArray()
    {
        return $this->getIterator()->toArray();
    }

    /**
     * Prepare the Cursor returned by {@link Query::execute()}.
     *
     * This method will apply cursor options present in the query structure
     * array. The Cursor may also be wrapped with an EagerCursor.
     *
     * @param Cursor $cursor
     * @return Cursor|EagerCursor
     */
    protected function prepareCursor(Cursor $cursor)
    {
        foreach ($this->getQueryOptions('hint', 'immortal', 'limit', 'skip', 'slaveOkay', 'sort') as $key => $value) {
            $cursor->$key($value);
        }

        if ( ! empty($this->query['snapshot'])) {
            $cursor->snapshot();
        }

        if ( ! empty($this->query['eagerCursor'])) {
            $cursor = new EagerCursor($cursor);
        }

        return $cursor;
    }

    /**
     * Returns an array containing the specified keys and their values from the
     * query array, provided they exist and are not null.
     *
     * @param string $key,... One or more option keys to be read
     * @return array
     */
    private function getQueryOptions(/* $key, ... */)
    {
        return array_filter(
            array_intersect_key($this->query, array_flip(func_get_args())),
            function($value) { return $value !== null; }
        );
    }
}
