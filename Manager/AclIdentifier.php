<?php

namespace Nuxia\AclBundle\Manager;

use Nuxia\AclBundle\Exception\OidTypeException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Acl\Dbal\MutableAclProvider;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Acl\Util\ClassUtils;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Util\ClassUtils as DeprecatedClassUtils;

class AclIdentifier implements AclIdentifierInterface
{
    /**
     * @var TokenStorageInterface|SecurityContextInterface
     */
    protected $tokenStorage;

    /**
     * @var MutableAclProvider
     */
    protected $aclProvider;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var string[]
     */
    protected $aclTables;

    /**
     * @param TokenStorageInterface|SecurityContextInterface $tokenStorage
     * @param MutableAclProvider                             $aclProvider
     * @param Connection                                     $connection
     * @param string[]                                       $aclTables
     */
    public function __construct(
        $tokenStorage,
        MutableAclProvider $aclProvider,
        Connection $connection,
        array $aclTables
    ) {
        if (!$tokenStorage instanceof TokenStorageInterface && !$tokenStorage instanceof SecurityContextInterface) {
            throw new \InvalidArgumentException('Argument 1 should be an instance of Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface or Symfony\Component\Security\Core\SecurityContextInterface');
        }

        $this->tokenStorage = $tokenStorage;
        $this->aclProvider = $aclProvider;
        $this->connection = $connection;
        $this->aclTables = $aclTables;
    }

    /**
     * {@inheritdoc}
     */
    public function getObjectIdentity($type, $classOrObject)
    {
        if ($classOrObject instanceof ObjectIdentityInterface) {
            return $classOrObject;
        }

        switch ($type) {
            case self::OID_TYPE_CLASS:
                if (is_object($classOrObject)) {
                    if (class_exists('Symfony\Component\Security\Acl\Util\ClassUtils')) {
                        $classOrObject = ClassUtils::getRealClass($classOrObject);
                    } else {
                        $classOrObject = DeprecatedClassUtils::getRealClass($classOrObject);
                    }
                }

                return new ObjectIdentity($type, $classOrObject);
            case self::OID_TYPE_OBJECT:
                return ObjectIdentity::fromDomainObject($classOrObject);
        }

        throw new OidTypeException($type);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserSecurityIdentity(UserInterface $user = null)
    {
        return null === $user
            ? UserSecurityIdentity::fromToken($this->tokenStorage->getToken())
            : UserSecurityIdentity::fromAccount($user);
    }

    /**
     * {@inheritdoc}
     */
    public function getRoleSecurityIdentity($role)
    {
        return new RoleSecurityIdentity($role);
    }

    /**
     * {@inheritdoc}
     */
    public function updateUserSecurityIdentity($oldUsername, UserInterface $user = null)
    {
        // $this->aclProvider->updateUserSecurityIdentity() is only available in symfony/security-acl >= 2.5
        if (method_exists($this->aclProvider, 'updateUserSecurityIdentity')) {
            $this->aclProvider->updateUserSecurityIdentity(
                $this->getUserSecurityIdentity($user),
                $oldUsername
            );
        } else {
            // only for symfony/security-acl < 2.5
            $usid = $this->getUserSecurityIdentity($user);

            if ($usid->getUsername() == $oldUsername) {
                throw new \InvalidArgumentException('There are no changes.');
            }

            $oldIdentifier = $usid->getClass().'-'.$oldUsername;
            $newIdentifier = $usid->getClass().'-'.$usid->getUsername();

            $query = sprintf(
                'UPDATE %s SET identifier = %s WHERE identifier = %s AND username = %s',
                $this->aclTables['sid'],
                $this->connection->quote($newIdentifier),
                $this->connection->quote($oldIdentifier),
                $this->connection->getDatabasePlatform()->convertBooleans(true)
            );

            $this->connection->executeQuery($query);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateRoleSecurityIdentity($oldRole, $role)
    {
        $this->connection->executeQuery(sprintf(
            'UPDATE %s SET identifier = %s WHERE username = %s AND identifier = %s',
            $this->aclTables['sid'],
            $this->connection->quote($this->getRoleSecurityIdentity($role)->getRole()),
            $this->connection->getDatabasePlatform()->convertBooleans(false),
            $this->connection->quote($oldRole)
        ));
    }
}
