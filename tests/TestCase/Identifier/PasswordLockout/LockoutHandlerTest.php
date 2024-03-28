<?php
declare(strict_types=1);

namespace CakeDC\Users\Test\TestCase\Identifier\PasswordLockout;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use CakeDC\Users\Identifier\PasswordLockout\LockoutHandler;

class LockoutHandlerTest extends TestCase
{
    /**
     * @inheritDoc
     */
    protected array $fixtures = [
        'plugin.CakeDC/Users.Users',
        'plugin.CakeDC/Users.FailedPasswordAttempts',
    ];

    /**
     * @return void
     */
    public function testNewFail()
    {
        $AttemptsTable = TableRegistry::getTableLocator()->get('CakeDC/Users.FailedPasswordAttempts');
        $id = '00000000-0000-0000-0000-000000000002';
        $handler = new LockoutHandler();
        $currentCount = 5;
        $attemptsBefore = $AttemptsTable->find()
            ->where(['user_id' => $id])
            ->orderByAsc('created')
            ->all()
            ->toArray();
        //First time will remove old records and still add a new one
        $handler->newFail($id);
        $this->assertSame($currentCount, count($attemptsBefore));
        $this->assertFalse($AttemptsTable->exists(['id' => $attemptsBefore[0]->id]));

        //Now only add a new one because there is nothing to remove
        $handler = new LockoutHandler();
        $handler->newFail($id);
        $attemptsAfterSecond = $AttemptsTable->find()->where(['user_id' => $id])->count();
        $this->assertSame($currentCount + 1, $attemptsAfterSecond);
    }

    /**
     * @return void
     */
    public function testIsUnlockedYes()
    {
        $handler = new LockoutHandler();
        $UsersTable = TableRegistry::getTableLocator()->get('Users');
        $actual = $handler->isUnlocked($UsersTable->get('00000000-0000-0000-0000-000000000002'));
        $this->assertTrue($actual);
    }

    /**
     * @return void
     */
    public function testIsUnlockedNotSavedLockoutAndLastFailureMax()
    {
        $userId = '00000000-0000-0000-0000-000000000004';
        $UsersTable = TableRegistry::getTableLocator()->get('Users');
        $userBefore = $UsersTable->get($userId);
        $this->assertNull($userBefore->lockout_time);
        $handler = new LockoutHandler();
        $actual = $handler->isUnlocked($UsersTable->get($userId));
        $this->assertFalse($actual);
        $userAfter = $UsersTable->get($userId);
        $this->assertInstanceOf(DateTime::class, $userAfter->lockout_time);
    }

    /**
     * @return void
     */
    public function testIsUnlockedSaveLockoutAndCompleted()
    {
        $handler = new LockoutHandler([
            'numberOfAttemptsFail' => 7,
        ]);
        $UsersTable = TableRegistry::getTableLocator()->get('Users');
        $userId = '00000000-0000-0000-0000-000000000004';
        $UsersTable->updateAll(['lockout_time' => new DateTime('-6 minutes')], ['id' => $userId]);
        $userBefore = $UsersTable->get($userId);
        $this->assertInstanceOf(DateTime::class, $userBefore->lockout_time);

        $actual = $handler->isUnlocked($UsersTable->get($userId));
        $this->assertTrue($actual);
    }

    /**
     * @return void
     */
    public function testIsUnlockedSaveLockoutAndNotCompleted()
    {
        $handler = new LockoutHandler([
            'numberOfAttemptsFail' => 7,
        ]);
        $userId = '00000000-0000-0000-0000-000000000004';
        $UsersTable = TableRegistry::getTableLocator()->get('Users');
        $UsersTable->updateAll(['lockout_time' => new DateTime('-4 minutes')], ['id' => $userId]);
        $userBefore = $UsersTable->get($userId);
        $this->assertInstanceOf(DateTime::class, $userBefore->lockout_time);

        $actual = $handler->isUnlocked($UsersTable->get($userId));
        $this->assertFalse($actual);
        $userAfter = $UsersTable->get($userId);
        $this->assertInstanceOf(DateTime::class, $userAfter->lockout_time);
        $this->assertEquals($userBefore->lockout_time, $userAfter->lockout_time);
    }
}
