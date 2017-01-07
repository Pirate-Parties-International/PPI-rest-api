<?php
namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use AppBundle\Entity\InternationalOrg as IntOrg;

class LoadIntOrgData implements FixtureInterface, ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * {@inheritDoc}
     */
    public function load(ObjectManager $manager)
    {
        $orgs = [];

        $t = new IntOrg();
        $t->setCode('ppi');
        $t->setName('Pirate Parties International');
        $t->setWebsite('http://www.pp-international.net/');
        $orgs[] = $t;

        $t = new IntOrg();
        $t->setCode('ppeu');
        $t->setName('European Pirate Party');
        $t->setWebsite('http://europeanpirateparty.eu/');
        $orgs[] = $t;

        $t = new IntOrg();
        $t->setCode('ype');
        $t->setName('Young Parties of Europe');
        $t->setWebsite('http://young-pirates.eu/');
        $orgs[] = $t;

        foreach ($orgs as $org) {
            $manager->persist($org);
        }
        
        $manager->flush();
    }

}
