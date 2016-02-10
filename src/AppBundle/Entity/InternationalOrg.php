<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

use AppBundle\Entity\IntOrgMembership;

/**
 * InternationalOrg
 *
 * @ORM\Table(name="international_org")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\InternationalOrgRepository")
 */
class InternationalOrg
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="code", type="string", length=6, unique=true)
     */
    private $code;

    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=50, unique=true)
     */
    private $name;

    /**
     * @ORM\OneToMany(targetEntity="IntOrgMembership", mappedBy="intOrg")
     */
    private $partyMemberships;


    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set code
     *
     * @param string $code
     *
     * @return InternationalOrg
     */
    public function setCode($code)
    {
        $this->code = $code;

        return $this;
    }

    /**
     * Get code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return InternationalOrg
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->partyMemberships = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add partyMembership
     *
     * @param \AppBundle\Entity\IntOrgMembership $partyMembership
     *
     * @return InternationalOrg
     */
    public function addPartyMembership(\AppBundle\Entity\IntOrgMembership $partyMembership)
    {
        $this->partyMemberships[] = $partyMembership;

        return $this;
    }

    /**
     * Remove partyMembership
     *
     * @param \AppBundle\Entity\IntOrgMembership $partyMembership
     */
    public function removePartyMembership(\AppBundle\Entity\IntOrgMembership $partyMembership)
    {
        $this->partyMemberships->removeElement($partyMembership);
    }

    /**
     * Get partyMemberships
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPartyMemberships()
    {
        return $this->partyMemberships;
    }
}
