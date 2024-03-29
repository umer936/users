<?php
declare(strict_types=1);

/**
 * Copyright 2010 - 2024, Cake Development Corporation (https://www.cakedc.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2010 - 2024, Cake Development Corporation (https://www.cakedc.com)
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

namespace CakeDC\Users\Identifier\PasswordLockout;

use Cake\Core\InstanceConfigTrait;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Query\SelectQuery;
use CakeDC\Users\Model\Entity\FailedPasswordAttempt;

class LockoutHandler implements LockoutHandlerInterface
{
    use InstanceConfigTrait;
    use LocatorAwareTrait;

    /**
     * Default configuration.
     *
     * @var array{timeWindowInSeconds: int, lockoutTimeInSeconds: int, numberOfAttemptsFail:int}
     */
    protected array $_defaultConfig = [
        'timeWindowInSeconds' => 5 * 60,
        'lockoutTimeInSeconds' => 5 * 60,
        'numberOfAttemptsFail' => 6,
        'failedPasswordAttemptsModel' => 'CakeDC/Users.FailedPasswordAttempts',
        'userLockoutField' => 'lockout_time',
        'usersModel' => 'Users',
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * @param \ArrayAccess|array $identity
     * @return bool
     */
    public function isUnlocked(\ArrayAccess|array $identity): bool
    {
        if (!isset($identity['id'])) {
            return false;
        }
        $lockoutField = $this->getConfig('userLockoutField');
        $userLockoutTime = $identity[$lockoutField] ?? null;
        if ($userLockoutTime) {
            if (!$this->checkLockoutTime($userLockoutTime)) {//Still locked?
                return false;
            }
        }
        $timeWindow = $this->getTimeWindow();
        $attemptsCount = $this->getAttemptsCount($identity['id'], $timeWindow);
        $numberOfAttemptsFail = $this->getNumberOfAttemptsFail();
        if ($numberOfAttemptsFail > $attemptsCount) {
            return true;
        }
        $lastAttempt = $this->getLastAttempt($identity['id'], $timeWindow);
        $this->getTableLocator()
            ->get($this->getConfig('usersModel'))
            ->updateAll([$lockoutField => $lastAttempt->created], ['id' => $identity['id']]);

        return $this->checkLockoutTime($lastAttempt->created);
    }

    /**
     * @param string|int $id User's id
     * @return void
     */
    public function newFail(string|int $id): void
    {
        $timeWindow = $this->getTimeWindow();
        $Table = $this->getTable();
        $entity = $Table->newEntity(['user_id' => $id]);
        $Table->saveOrFail($entity);
        $Table->deleteAll($Table->query()->newExpr()->lt('created', $timeWindow));
    }

    /**
     * @return \Cake\ORM\Table
     */
    protected function getTable(): \Cake\ORM\Table
    {
        return $this->getTableLocator()->get('CakeDC/Users.FailedPasswordAttempts');
    }

    /**
     * @param string|int $id
     * @param \Cake\I18n\DateTime $timeWindow
     * @return int
     */
    protected function getAttemptsCount(string|int $id, DateTime $timeWindow): int
    {
        return $this->getAttemptsQuery($id, $timeWindow)->count();
    }

    /**
     * @param string|int $id
     * @param \Cake\I18n\DateTime $timeWindow
     * @return \CakeDC\Users\Model\Entity\FailedPasswordAttempt
     */
    protected function getLastAttempt(int|string $id, DateTime $timeWindow): FailedPasswordAttempt
    {
        /**
         * @var \CakeDC\Users\Model\Entity\FailedPasswordAttempt $attempt
         */
        $attempt = $this->getAttemptsQuery($id, $timeWindow)->first();

        return $attempt;
    }

    /**
     * @param string|int $id
     * @param \Cake\I18n\DateTime $timeWindow
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function getAttemptsQuery(int|string $id, DateTime $timeWindow): SelectQuery
    {
        $query = $this->getTable()->find();

        return $query
            ->where([
                'user_id' => $id,
                $query->newExpr()->gte('created', $timeWindow),
            ])
            ->orderByDesc('created');
    }

    /**
     * @return \Cake\I18n\DateTime
     */
    protected function getTimeWindow(): DateTime
    {
        $timeWindow = $this->getConfig('timeWindowInSeconds');
        if (is_int($timeWindow) && $timeWindow >= 60) {
            return (new DateTime())->subSeconds($timeWindow);
        }

        throw new \UnexpectedValueException(__d('cake_d_c/users', 'Config "timeWindowInSeconds" must be integer greater than 60'));
    }

    /**
     * @return int
     */
    protected function getNumberOfAttemptsFail(): int
    {
        $number = $this->getConfig('numberOfAttemptsFail');
        if (is_int($number) && $number >= 1) {
            return $number;
        }
        throw new \UnexpectedValueException(__d('cake_d_c/users', 'Config "numberOfAttemptsFail" must be integer greater or equal 0'));
    }

    /**
     * @return int
     */
    protected function getLockoutTime(): int
    {
        $lockTime = $this->getConfig('lockoutTimeInSeconds');
        if (is_int($lockTime) && $lockTime >= 60) {
            return $lockTime;
        }

        throw new \UnexpectedValueException(__d('cake_d_c/users', 'Config "lockoutTimeInSeconds" must be integer greater than 60'));
    }

    /**
     * @param \Cake\I18n\DateTime $dateTime
     * @return bool
     */
    protected function checkLockoutTime(DateTime $dateTime): bool
    {
        return $dateTime->addSeconds($this->getLockoutTime())->isPast();
    }
}
