<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

use AppBundle\Entity\Party;
use AppBundle\Entity\InternationalOrg;

/**
 * IntOrgMembership
 *
 * @ORM\Table(name="int_org_membership")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\IntOrgMembershipRepository")
 */
class IntOrgMembership
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
     * @ORM\ManyToOne(targetEntity="Party", inversedBy="intMemberships")
     * @ORM\JoinColumn(name="party_id", referencedColumnName="id")
     */
    private $party;

    /**
     * @ORM\ManyToOne(targetEntity="InternationalOrg", inversedBy="partyMemberships")
     * @ORM\JoinColumn(name="int_org_id", referencedColumnName="id")
     */
    private $intOrg;

    /**
     * @var string
     *
     * @ORM\Column(name="type", type="string", length=20, nullable=true)
     */
    private $type;
    

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
     * Set type
     *
     * @param string $type
     *
     * @return IntOrgMembership
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set party
     *
     * @param \AppBundle\Entity\Party $party
     *
     * @return IntOrgMembership
     */
    public function setParty(\AppBundle\Entity\Party $party = null)
    {
        $this->party = $party;

        return $this;
    }

    /**
     * Get party
     *
     * @return \AppBundle\Entity\Party
     */
    public function getParty()
    {
        return $this->party;
    }

    /**
     * Set intOrg
     *
     * @param \AppBundle\Entity\InternationalOrg $intOrg
     *
     * @return IntOrgMembership
     */
    public function setIntOrg(\AppBundle\Entity\InternationalOrg $intOrg = null)
    {
        $this->intOrg = $intOrg;

        return $this;
    }

    /**
     * Get intOrg
     *
     * @return \AppBundle\Entity\InternationalOrg
     */
    public function getIntOrg()
    {
        return $this->intOrg;
    }
}
