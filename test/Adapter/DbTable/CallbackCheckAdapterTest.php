<?php

/**
 * @see       https://github.com/laminas/laminas-authentication for the canonical source repository
 * @copyright https://github.com/laminas/laminas-authentication/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-authentication/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Authentication\Adapter\DbTable;

use Laminas\Authentication;
use Laminas\Authentication\Adapter\DbTable\CallbackCheckAdapter;
use Laminas\Authentication\Adapter\DbTable\CredentialTreatmentAdapter;
use Laminas\Authentication\Adapter\DbTable\Exception\InvalidArgumentException;
use Laminas\Authentication\Adapter\DbTable\Exception\RuntimeException;
use Laminas\Db\Adapter\Adapter as DbAdapter;
use PHPUnit\Framework\TestCase;

/**
 * @group      Laminas_Auth
 * @group      Laminas_Db_Table
 */
class CallbackCheckAdapterTest extends TestCase
{
    // @codingStandardsIgnoreStart
    /**
     * SQLite database connection
     *
     * @var \Laminas\Db\Adapter\Adapter
     */
    protected $_db = null;

    /**
     * Database table authentication adapter
     *
     * @var \Laminas\Authentication\Adapter\DbTable
     */
    protected $_adapter = null;
    // @codingStandardsIgnoreEnd

    /**
     * Set up test configuration
     */
    public function setUp(): void
    {
        if (! getenv('TESTS_LAMINAS_AUTH_ADAPTER_DBTABLE_PDO_SQLITE_ENABLED')) {
            $this->markTestSkipped('Tests are not enabled in phpunit.xml');
            return;
        } elseif (! extension_loaded('pdo')) {
            $this->markTestSkipped('PDO extension is not loaded');
            return;
        } elseif (! in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver is not available');
            return;
        }

        $this->_setupDbAdapter();
        $this->_setupAuthAdapter();
    }

    public function tearDown(): void
    {
        $this->_adapter = null;
        if ($this->_db instanceof DbAdapter) {
            $this->_db->query('DROP TABLE [users]');
        }
        $this->_db = null;
    }

    /**
     * Ensures expected behavior for authentication success
     *
     * @return void
     */
    public function testAuthenticateSuccess(): void
    {
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $result = $this->_adapter->authenticate();
        $this->assertTrue($result->isValid());
    }


    /**
     * Ensures expected behavior for authentication success
     *
     * @return void
     */
    public function testAuthenticateSuccessWithCallback(): void
    {
        $this->_adapter = new CredentialTreatmentAdapter(
            $this->_db,
            'users',
            'username',
            'password',
            null,
            function ($a, $b) {
                return $a === $b;
            }
        );
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $result = $this->_adapter->authenticate();
        $this->assertTrue($result->isValid());
    }


    /**
     * Ensures expected behavior for an invalid callback
     *
     * @return void
     */
    public function testAuthenticateCallbackThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid callback provided');
        $this->_adapter->setCredentialValidationCallback('This is not a valid callback');
    }

    /**
     * Ensures expected behavior for for authentication failure
     * reason: Identity not found.
     *
     * @return void
     */
    public function testAuthenticateFailureIdentityNotFound(): void
    {
        $this->_adapter->setIdentity('non_existent_username');
        $this->_adapter->setCredential('my_password');

        $result = $this->_adapter->authenticate();
        $this->assertEquals(Authentication\Result::FAILURE_IDENTITY_NOT_FOUND, $result->getCode());
    }

    /**
     * Ensures expected behavior for for authentication failure
     * reason: Identity not found.
     *
     * @return void
     */
    public function testAuthenticateFailureIdentityAmbiguous(): void
    {
        $sqlInsert = 'INSERT INTO users (username, password, real_name) '
            . 'VALUES ("my_username", "my_password", "My Real Name")';
        $this->_db->query($sqlInsert, DbAdapter::QUERY_MODE_EXECUTE);

        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');

        $result = $this->_adapter->authenticate();
        $this->assertEquals(Authentication\Result::FAILURE_IDENTITY_AMBIGUOUS, $result->getCode());
    }

    /**
     * Ensures expected behavior for authentication failure because of a bad password
     *
     * @return void
     */
    public function testAuthenticateFailureInvalidCredential(): void
    {
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password_bad');
        $result = $this->_adapter->authenticate();
        $this->assertFalse($result->isValid());
    }

    /**
     * Ensures that getResultRowObject() works for successful authentication
     *
     * @return void
     */
    public function testGetResultRow(): void
    {
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $this->_adapter->authenticate();
        $resultRow = $this->_adapter->getResultRowObject();
        $this->assertEquals($resultRow->username, 'my_username');
    }

    /**
     * Ensure that ResultRowObject returns only what told to be included
     *
     * @return void
     */
    public function testGetSpecificResultRow(): void
    {
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $this->_adapter->authenticate();
        $resultRow = $this->_adapter->getResultRowObject(['username', 'real_name']);
        $this->assertEquals(
            'O:8:"stdClass":2:{s:8:"username";s:11:"my_username";s:9:"real_name";s:12:"My Real Name";}',
            serialize($resultRow)
        );
    }

    /**
     * Ensure that ResultRowObject returns an object has specific omissions
     *
     * @return void
     */
    public function testGetOmittedResultRow(): void
    {
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $this->_adapter->authenticate();
        $resultRow = $this->_adapter->getResultRowObject(null, 'password');
        $this->assertEquals(
            // @codingStandardsIgnoreStart
            'O:8:"stdClass":3:{s:2:"id";s:1:"1";s:8:"username";s:11:"my_username";s:9:"real_name";s:12:"My Real Name";}',
            // @codingStandardsIgnoreEnd
            serialize($resultRow)
        );
    }

    /**
     * @group Laminas-5957
     *
     * @return void
     */
    public function testAdapterCanReturnDbSelectObject(): void
    {
        $this->assertInstanceOf('Laminas\Db\Sql\Select', $this->_adapter->getDbSelect());
    }

    /**
     * @group Laminas-5957
     *
     * @return void
     */
    public function testAdapterCanUseModifiedDbSelectObject(): void
    {
        $select = $this->_adapter->getDbSelect();
        $select->where('1 = 0');
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');

        $result = $this->_adapter->authenticate();
        $this->assertEquals(Authentication\Result::FAILURE_IDENTITY_NOT_FOUND, $result->getCode());
    }

    /**
     * @group Laminas-5957
     *
     * @return void
     */
    public function testAdapterReturnsASelectObjectWithoutAuthTimeModificationsAfterAuth(): void
    {
        $select = $this->_adapter->getDbSelect();
        $select->where('1 = 1');
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->setCredential('my_password');
        $this->_adapter->authenticate();
        $selectAfterAuth = $this->_adapter->getDbSelect();
        $whereParts      = $selectAfterAuth->where->getPredicates();
        $this->assertEquals(1, count($whereParts));

        $lastWherePart  = array_pop($whereParts);
        $expressionData = $lastWherePart[1]->getExpressionData();
        $this->assertEquals('1 = 1', $expressionData[0][0]);
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionNoTable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A table must be supplied for');
        $adapter = new CredentialTreatmentAdapter($this->_db);
        $adapter->authenticate();
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionNoIdentityColumn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An identity column must be supplied for the');
        $adapter = new CredentialTreatmentAdapter($this->_db, 'users');
        $adapter->authenticate();
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionNoCredentialColumn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A credential column must be supplied');
        $adapter = new CredentialTreatmentAdapter($this->_db, 'users', 'username');
        $adapter->authenticate();
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionNoIdentity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A value for the identity was not provided prior');
        $this->_adapter->authenticate();
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionNoCredential(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A credential value was not provided prior');
        $this->_adapter->setIdentity('my_username');
        $this->_adapter->authenticate();
    }

    /**
     * Ensure that exceptions are caught
     *
     * @return void
     */
    public function testCatchExceptionBadSql(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The supplied parameters to');
        $this->_adapter->setTableName('bad_table_name');
        $this->_adapter->setIdentity('value');
        $this->_adapter->setCredential('value');
        $this->_adapter->authenticate();
    }

    /**
     * Test to see same usernames with different passwords can not authenticate
     * when flag is not set. This is the current state of
     * Laminas_Auth_Adapter_DbTable (up to Laminas 1.10.6)
     *
     * @group Laminas-7289
     *
     * @return void
     */
    public function testEqualUsernamesDifferentPasswordShouldNotAuthenticateWhenFlagIsNotSet(): void
    {
        $sqlInsert = 'INSERT INTO users (username, password, real_name) '
                   . 'VALUES ("my_username", "my_otherpass", "Test user 2")';
        $this->_db->query($sqlInsert, DbAdapter::QUERY_MODE_EXECUTE);

        // test if user 1 can authenticate
        $this->_adapter->setIdentity('my_username')
                       ->setCredential('my_password');
        $result = $this->_adapter->authenticate();
        $this->assertContains(
            'More than one record matches the supplied identity.',
            $result->getMessages()
        );
        $this->assertFalse($result->isValid());
    }

    /**
     * Test to see same usernames with different passwords can authenticate when
     * a flag is set
     *
     * @group Laminas-7289
     *
     * @return void
     */
    public function testEqualUsernamesDifferentPasswordShouldAuthenticateWhenFlagIsSet(): void
    {
        $sqlInsert = 'INSERT INTO users (username, password, real_name) '
                   . 'VALUES ("my_username", "my_otherpass", "Test user 2")';
        $this->_db->query($sqlInsert, DbAdapter::QUERY_MODE_EXECUTE);

        // test if user 1 can authenticate
        $this->_adapter->setIdentity('my_username')
                       ->setCredential('my_password')
                       ->setAmbiguityIdentity(true);
        $result = $this->_adapter->authenticate();
        $this->assertNotContains(
            'More than one record matches the supplied identity.',
            $result->getMessages()
        );
        $this->assertTrue($result->isValid());
        $this->assertEquals('my_username', $result->getIdentity());

        $this->_adapter = null;
        $this->_setupAuthAdapter();

        // test if user 2 can authenticate
        $this->_adapter->setIdentity('my_username')
                       ->setCredential('my_otherpass')
                       ->setAmbiguityIdentity(true);
        $result2 = $this->_adapter->authenticate();
        $this->assertNotContains(
            'More than one record matches the supplied identity.',
            $result->getMessages()
        );
        $this->assertTrue($result2->isValid());
        $this->assertEquals('my_username', $result2->getIdentity());
    }

    // @codingStandardsIgnoreStart
    protected function _setupDbAdapter($optionalParams = []): void
    {
        // @codingStandardsIgnoreEnd
        $params = [
            'driver' => 'pdo_sqlite',
            'dbname' => getenv('TESTS_LAMINAS_AUTH_ADAPTER_DBTABLE_PDO_SQLITE_DATABASE'),
        ];

        if (! empty($optionalParams)) {
            $params['options'] = $optionalParams;
        }

        $this->_db = new DbAdapter($params);

        $sqlCreate = 'CREATE TABLE IF NOT EXISTS [users] ( '
                   . '[id] INTEGER  NOT NULL PRIMARY KEY, '
                   . '[username] VARCHAR(50) NOT NULL, '
                   . '[password] VARCHAR(32) NULL, '
                   . '[real_name] VARCHAR(150) NULL)';
        $this->_db->query($sqlCreate, DbAdapter::QUERY_MODE_EXECUTE);

        $sqlDelete = 'DELETE FROM users';
        $this->_db->query($sqlDelete, DbAdapter::QUERY_MODE_EXECUTE);

        $sqlInsert = 'INSERT INTO users (username, password, real_name) '
                   . 'VALUES ("my_username", "my_password", "My Real Name")';
        $this->_db->query($sqlInsert, DbAdapter::QUERY_MODE_EXECUTE);
    }

    // @codingStandardsIgnoreStart
    protected function _setupAuthAdapter(): void
    {
        // @codingStandardsIgnoreEnd
        $this->_adapter = new CallbackCheckAdapter($this->_db, 'users', 'username', 'password');
    }
}
