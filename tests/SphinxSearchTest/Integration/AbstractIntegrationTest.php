<?php
/**
 * Sphinx Search
 *
 * @link        https://github.com/ripaclub/sphinxsearch
 * @copyright   Copyright (c) 2014, Leonardo Di Donato <leodidonato at gmail dot com>, Leonardo Grasso <me at leonardograsso dot com>
 * @license     http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */
namespace SphinxSearchTest\IntegrationTest;

use SphinxSearch\Db\Adapter\AdapterServiceFactory;
use SphinxSearch\Query\QueryExpression;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\Config;
use SphinxSearch\Search;
use SphinxSearchTest\Db\Sql\SelectTest;
use SphinxSearch\Db\Sql\Select;
use Zend\Db\Sql\Expression;
use SphinxSearch\Db\Sql\Sql;
use SphinxSearch\Indexer;
use Zend\Db\Sql\Insert;
use SphinxSearch\Db\Sql\Replace;
use Zend\Db\Adapter\Adapter;
use SphinxSearch\Db\Sql\Predicate\Match;

abstract class AbstractIntegrationTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var \Zend\ServiceManager\ServiceLocatorInterface
     */
    private $serviceManager;

    /**
     * @var \Zend\Db\Adapter\Adapter
     */
    private $adapter;

    /**
     * @var Search
     */
    protected $search = null;

    /**
     * @var Sql
     */
    protected $sql = null;

    protected $config = array();


    public function setUp()
    {
        $this->serviceManager = new ServiceManager(new Config(array(
            'factories' => array(
                'SphinxSearch\Db\Adapter\Adapter' => 'SphinxSearch\Db\Adapter\AdapterServiceFactory'
            ),
            'aliases' => array(
                'sphinxql' => 'SphinxSearch\Db\Adapter\Adapter'
            )
        )));
        $this->serviceManager->setService('Config', array(
            'sphinxql' => $this->config
        ));

        $this->adapter = $this->serviceManager->get('sphinxql');

        $this->search = new Search($this->adapter);

        $this->sql = $this->search->getSql();
    }

    public function testConnection()
    {
        $this->assertInstanceOf('\Zend\Db\Adapter\Adapter', $this->adapter);

        $connection = $this->adapter->getDriver()->getConnection();

        $connection->connect();

        $this->assertTrue($connection->isConnected());

        $result = $this->adapter->query('SELECT 1+1', Adapter::QUERY_MODE_EXECUTE)->current();

        $this->assertArrayHasKey('1+1', $result);
        $this->assertTrue(2 == $result['1+1']);
    }

    /**
     * @depends testConnection
     */
    public function testSearchQueries()
    {
        $selectTest = new SelectTest();

        $data = $selectTest->providerData();

//         echo PHP_EOL . 'Testing SphinxQL queries ...' . PHP_EOL;

        foreach ($data as $namedParam) {
            // $select    $sqlPrep    $params     $sqlStr    $internalTests // use named param
            list($select, $sqlPrep, $params, $sqlStr, $internalTests) = $namedParam;

            if (!$select->getRawState('table')) {
                $select = clone $select;
                $select->from('foo');
            }

            // Expr in group by NOT SUPPORTED
            if ($sqlPrep == 'SELECT * FROM `foo` GROUP BY DAY(`c1`)') {
                continue;
            }

            // Buggy
            if (strpos($sqlPrep, 'HAVING')) {
                continue;
            }

            // Not fully supported
            if (strpos($sqlPrep, 'IS NULL') || strpos($sqlPrep, 'ORDER BY isnull(`name`)')) {
                continue;
            }

            // Mixing order col and expr not fully supported
            if (strpos($sqlPrep, 'DESC, RAND()')) {
                continue;
            }


//             echo $sqlStr . PHP_EOL;
            $this->search->searchWith($select);
        }

    }

    /**
     * @depends testConnection
     */
    public function testTypeWithPreparedStatement()
    {

        if ($this->adapter->getDriver() instanceof \Zend\Db\Adapter\Driver\Mysqli\Mysqli) {
            $this->markTestSkipped('Mysqli does not support prepared statement client side emulation');
        }

        $this->adapter->query('DELETE FROM foo WHERE id = 1', Adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($this->adapter);
        $indexer->setQueryMode($indexer::QUERY_MODE_PREPARED);

        $search = clone $this->search;
        $search->setQueryMode($search::QUERY_MODE_PREPARED);

        $affectedRow = $indexer->insert('foo', array(
            'id' => 1,
            'c1' => 10,
            'c2' => true, //will be casted to int
            'c3' => '5', //will be casted to int
            'f1' => '3.333',
        ), true); //replace

        $this->assertEquals(1, $affectedRow);




        //test int in where
        $select = new Select('foo');
        $select->where(array('id' => 1));

        $results = $search->searchWith($select);

        foreach ($results as $result) {
            $this->assertEquals(1, $result['id']);
            $this->assertEquals(10, $result['c1']);
            $this->assertEquals(1, $result['c2']);
            $this->assertEquals(5, $result['c3']);
            $this->assertEquals(3.333, $result['f1']);
            break;
        }

        //test float in where
        $select = new Select('foo');
        $select->where(array('f1' => 3.333));

        $results = $search->searchWith($select);

        foreach ($results as $result) {
            $this->assertEquals(1, $result['id']);
            $this->assertEquals(10, $result['c1']);
            $this->assertEquals(1, $result['c2']);
            $this->assertEquals(5, $result['c3']);
            $this->assertEquals(3.333, $result['f1']);
            break;
        }
    }

    /**
     * @depends testConnection
     */
    public function testTypeWithSql()
    {

        $this->adapter->query('DELETE FROM foo WHERE id = 1', Adapter::QUERY_MODE_EXECUTE);

        $search = new Search($this->adapter);
        $search->setQueryMode($search::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($this->adapter);
        $indexer->setQueryMode($indexer::QUERY_MODE_EXECUTE);


        $sql = new Sql($this->adapter);


        //test Replace with sql query
        $insert = new Replace('foo');
        $insert->values(array(
            'id' => 1,
            'c1' => 10,
            'c2' => true, //will be casted to int
            'c3' => '5', //will be casted to int
            'f1' => 3.333,
        ));

        $affectedRow = $indexer->insertWith($insert);
        $this->assertEquals(1, $affectedRow);


        $select = new Select('foo');
        $select->where(array('id' => 1));


        //test select sql
        $results = $search->searchWith($select);


        foreach ($results as $result) {
            $this->assertEquals(1, $result['id']);
            $this->assertEquals(10, $result['c1']);
            $this->assertEquals(1, $result['c2']);
            $this->assertEquals(5, $result['c3']);
            $this->assertEquals(3.333, $result['f1']);
            break;
        }


        $select = new Select('foo');
        $select->where(array('f1' => 3.333));

        //test select sql with float in where and direct adapter execution
        $results = $this->adapter->query(
            $sql->getSqlStringForSqlObject($select),
            Adapter::QUERY_MODE_EXECUTE
        );


        foreach ($results as $result) {
            $this->assertEquals(1, $result['id']);
            $this->assertEquals(10, $result['c1']);
            $this->assertEquals(1, $result['c2']);
            $this->assertEquals(5, $result['c3']);
            $this->assertEquals(3.333, $result['f1']);
            break;
        }

    }

    public function testBoolean()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);

        $dataset = array(
            array('id' => 1, 'short' => 'hello world', 'c1' => true),
            array('id' => 2, 'short' => 'hello world', 'c1' => false),
        );

        foreach ($dataset as $values) {
            $indexer->insert('foo', $values, true);
        }

        $search = new Search($adapter);

        //test TRUE
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id', 'c1'))
            ->where(array('c1' => true));
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 1, 'c1' => true), $rowset->current()->getArrayCopy());

        //test FALSE
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id', 'c1'))
            ->where(array('c1' => false));
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $result = $rowset->current()->getArrayCopy();
        //array('id' => 2, 'c1' => false) == array('id' => '2', 'c1' => '0') but assertEquals doesn't work
        $this->assertEquals(2, $result['id']);
        $this->assertTrue(false == $result['c1']);

        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }

    public function testInteger()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);

        $dataset = array(
            array('id' => 1, 'short' => 'hello world', 'c1' => 1000),
            array('id' => 2, 'short' => 'hello world', 'c1' => -1000),
        );

        foreach ($dataset as $values) {
            $indexer->insert('foo', $values, true);
        }

        $search = new Search($adapter);



        //1: simple int
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id', 'c1'))
            ->where(array('c1' => 1000));
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 1, 'c1' => 1000), $rowset->current()->getArrayCopy());

        //1: overflow with unsigned
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id', 'c1'))
            ->where(array('c1' => pow(2, 32) - 1000)); //sphinx has 32-bit unsigned integer
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 2, 'c1' => pow(2, 32) - 1000), $rowset->current()->getArrayCopy());



        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }

    public function testFloat()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);

        $dataset = array(
            array('id' => 1, 'short' => 'hello world', 'c1' => 11, 'f1' => 55.55),
            array('id' => 2, 'short' => 'hello world', 'c1' => 11, 'f1' => 10), //integers for float column are working in insert
            array('id' => 3, 'short' => 'hello world', 'c1' => 11, 'f1' => pi()),
        );

        foreach ($dataset as $values) {
            $indexer->insert('foo', $values, true);
        }

        $search = new Search($adapter);




        /*
          floating point values (32-bit, IEEE 754 single precision)
          @link http://en.wikipedia.org/wiki/Single-precision_floating-point_format

          Keep in mind float precision issues:

            mysql> insert into foo (id, f1) values (1, 55.55);
            Query OK, 1 row affected (0.01 sec)

            mysql> select * from foo;
            +------+------+------+------+------+------+-----------+------+
            | id   | baz  | bam  | c1   | c2   | c3   | f1        | bar  |
            +------+------+------+------+------+------+-----------+------+
            |    1 |    0 |    0 |    0 |    0 |    0 | 55.549999 |      |
            +------+------+------+------+------+------+-----------+------+
            1 row in set (0.00 sec)

            mysql> select * from foo where f1 = 55.55;
            +------+------+------+------+------+------+-----------+------+
            | id   | baz  | bam  | c1   | c2   | c3   | f1        | bar  |
            +------+------+------+------+------+------+-----------+------+
            |    1 |    0 |    0 |    0 |    0 |    0 | 55.549999 |      |
            +------+------+------+------+------+------+-----------+------+
            1 row in set (0.00 sec)

         */


        //1: float with few decimals
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id'))
            ->where(array('f1' => 55.55));
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 1), $rowset->current()->getArrayCopy()); //Due to precision issue we can't assert against f1 value in result


        //2: special case (no decimals)
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id', 'f1'))
                   ->where(array('f1' => 10.00));
        });

        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 2,'f1' => 10), $rowset->current()->getArrayCopy());



        //3: precision of irrational number
        $rowset = $search->search('foo', function(Select $select) {
            $select->columns(array('id'))
                ->where(array('f1' => pi()));
        });


        $this->assertCount(1, $rowset);
        //Assume not identical but equal (result values are strings)
        $this->assertEquals(array('id' => 3), $rowset->current()->getArrayCopy());

        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }


    public function testDocUseCase1()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);

        $indexer->insert('foo', array(
            'id'    => 11,
            'short' => 'hello world',
            'text'  => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'c1'    => 10,
            'c2'    => 100,
            'c3'    => 1000,
            'f1'    => pi(),
        ), true);

        $search = new Search($adapter);
        $rowset = $search->search('foo', new Match('ipsum dolor'));
        $current = $rowset->current();
        $this->assertEquals(11, $current['id']);

        $search = new Search($adapter);
        $rowset = $search->search('foo', function(Select $select){
            $select->where(new Match('ipsum dolor'))
                   ->where(array('c1 > ?' => 5))
                   ->limit(1);
        });
        $this->assertEquals(1, $rowset->count());
        $current = $rowset->current();
        $this->assertEquals(11, $current['id']);

        $expr = new QueryExpression('? ?', array('ipsum', 'dolor'));
        /** @var $select Select */
        $select = new Select('foo');
        $select->where(new Match($expr));
        $sql = new Sql($adapter);
        $query = $sql->getSqlStringForSqlObject($select);
        $results = $adapter->query(
            $query,
            Adapter::QUERY_MODE_EXECUTE
        );
        $current = $results->current();
        $this->assertEquals(11, $current['id']);

        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }

    public function testOrderWithCompoundName()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);
        $indexer->insert(
            'foo',
            array(
                'id'    => 11,
                'short' => 'hello world',
                'text'  => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
                'c1'    => 10,
                'c2'    => 100,
                'c3'    => 1000,
                'f1'    => pi(),
            ),
            true
        );
        $indexer->insert(
            'foo',
            array(
                'id'    => 12,
                'short' => 'hello world 2',
                'text'  => 'Lorem ipsum dolor sit amet ...',
                'c1'    => 10,
                'c2'    => 100,
                'c3'    => 2000,
                'f1'    => pi(),
            ),
            true
        );

        $select29 = new Select;
        $select29->from('foo')->order('c1.c2');
        $search = new Search($adapter);
        $this->setExpectedException('Zend\Db\Adapter\Exception\InvalidQueryException');
        $search->searchWith($select29);

        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }

    public function testGroupWithCompoundName()
    {
        $adapter = $this->adapter;
        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);

        $indexer = new Indexer($adapter);
        $indexer->insert(
            'foo',
            array(
                'id'    => 11,
                'short' => 'hello world',
                'text'  => 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
                'c1'    => 10,
                'c2'    => 100,
                'c3'    => 1000,
                'f1'    => pi(),
            ),
            true
        );
        $indexer->insert(
            'foo',
            array(
                'id'    => 12,
                'short' => 'hello world 2',
                'text'  => 'Lorem ipsum dolor sit amet ...',
                'c1'    => 10,
                'c2'    => 100,
                'c3'    => 2000,
                'f1'    => pi(),
            ),
            true
        );

        $select30 = new Select;
        $select30->from('foo')->group('c1.d2');
        $search = new Search($adapter);
        $this->setExpectedException('Zend\Db\Adapter\Exception\InvalidQueryException');
        $search->searchWith($select30);

        $adapter->query('TRUNCATE RTINDEX foo', $adapter::QUERY_MODE_EXECUTE);
    }

    public function testShowMeta()
    {
        $search = $this->search;

        $search->search('foo');
        $resultSet = $search->execute('SHOW META');
        $result = $resultSet->toArray();

        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('Variable_name', $result[0]);
        $this->assertArrayHasKey('Value', $result[0]);
    }


}
