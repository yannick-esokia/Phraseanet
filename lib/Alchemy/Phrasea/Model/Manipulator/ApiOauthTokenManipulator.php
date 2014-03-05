<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Model\Manipulator;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Authentication\ACLProvider;
use Alchemy\Phrasea\Model\Entities\ApiAccount;
use Alchemy\Phrasea\Model\Entities\ApiOauthToken;
use Alchemy\Phrasea\Model\Entities\Session;
use Alchemy\Phrasea\Model\Entities\User;
use Alchemy\Phrasea\Model\Manipulator\TokenManipulator;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityRepository;
use RandomLib\Generator;

class ApiOauthTokenManipulator implements ManipulatorInterface
{
    private $om;
    private $repository;
    private $randomGenerator;

    public function __construct(ObjectManager $om, EntityRepository $repo, Generator $random)
    {
        $this->om = $om;
        $this->repository = $repo;
        $this->randomGenerator = $random;
    }

    public function create(ApiAccount $account, Session $session = null , \DateTime $expire = null, $scope = null)
    {
        $token = new ApiOauthToken();
        $token->setOauthToken($this->getNewToken());
        $token->setExpires($expire);
        $token->setScope($scope);
        $token->setAccount($account);
        $token->setSession($session);

        $this->update($token);

        return $token;
    }

    public function delete(ApiOauthToken $token)
    {
        $this->om->remove($token);
        $this->om->flush();
    }

    public function update(ApiOauthToken $token)
    {
        $this->om->persist($token);
        $this->om->flush();
    }

    public function renew(ApiOauthToken $token, \DateTime $expire = null)
    {
        $token->setOauthToken($this->getNewToken());
        $token->setExpires($expire);

        $this->update($token);
    }

    public function setOauthToken(ApiOauthToken $token, $oauthToken)
    {
        $token->setOauthToken($oauthToken);
        $this->update($token);
    }

    private function getNewToken()
    {
        do {
            $token = $this->randomGenerator->generateString(32, TokenManipulator::LETTERS_AND_NUMBERS);
        } while (null !== $this->repository->find($token));

        return $token;
    }
}
