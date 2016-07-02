<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

use AppBundle\Entity\IntOrgMembership;

use JMS\Serializer\Annotation\ExclusionPolicy;
use JMS\Serializer\Annotation\Exclude;
use JMS\Serializer\Annotation\Expose;
use JMS\Serializer\Annotation\AccessorOrder;
use JMS\Serializer\Annotation\SerializedName;
use JMS\Serializer\Annotation\Accessor;
use JMS\Serializer\Annotation\Type as SerializerType;

/**
 * Party
 *
 * @ORM\Table(name="party")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\PartyRepository")
 * @ExclusionPolicy("none")
 * @AccessorOrder("custom", custom = {"code"})
 */
class Party
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @Exclude
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="code", type="string", length=20, unique=true)
     */
    private $code;

    /**
     * @var string
     *
     * @ORM\Column(name="countryCode", type="string", length=2)
     */
    private $countryCode;

    /**
     * @var string
     *
     * @ORM\Column(name="countryName", type="string", length=50)
     */
    private $countryName;

    /**
     * @var array
     *
     * @ORM\Column(name="name", type="json_array")
     */
    private $name;

    /**
     * @var string
     *
     * @ORM\Column(name="type", type="string", length=10)
     */
    private $type;

    /**
     * @var string
     *
     * @ORM\Column(name="region", type="string", length=30, nullable=true)
     */
    private $region;

    /**
     * @var string
     *
     * @ORM\Column(name="parentParty", type="string", length=20, nullable=true)
     */
    private $parentParty;

    /**
     * @var array
     *
     * @ORM\Column(name="headquarters", type="json_array", nullable=true)
     */
    private $headquarters;

    /**
     * @var array
     *
     * @ORM\Column(name="websites", type="json_array", nullable=true)
     */
    private $websites;

    /**
     * @var array
     *
     * @ORM\Column(name="socialNetworks", type="json_array", nullable=true)
     */
    private $socialNetworks;

    /**
     * @var array
     *
     * @ORM\Column(name="contact", type="json_array", nullable=true)
     */
    private $contact;

    /**
     * @ORM\OneToMany(targetEntity="IntOrgMembership", mappedBy="party", cascade={"persist"})
     * @Accessor(getter="getMembership")
     * @SerializedName("membership")
     * @SerializerType("array")
     */
    private $intMemberships;

    /**
     * @var string
     *
     * @ORM\Column(name="logo", type="string", length=50, nullable=true)
     */
    private $logo;

    /**
     * @var string
     * @Expose
     * @ORM\Column(name="countryFlag", type="string", length=50, nullable=true)
     */
    private $countryFlag;

    /**
     * @var string
     * @Expose
     */
    private $membership;

    /**
     * @var string
     *
     * @ORM\Column(name="defunct", type="boolean", nullable=false)
     */
    private $defunct;

    /**
     * NON ORM property
     * @var array
     */
    public $socialReach;

    /**
     * NON ORM property
     * @var array
     */
    public $socialData;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->intMemberships = new \Doctrine\Common\Collections\ArrayCollection();
        $this->defunct = false;
    }

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
     * Get membership
     *
     * @return array
     */
    public function getMembership() {
        $out = [];
        foreach ($this->intMemberships as $key => $value) {
            $out[strtolower($value->getIntOrg()->getCode())] = $value->getType();
        }
        return $out;
    }

    public function getCleanOfficialWebsite() {
        if (!empty($this->getWebsites()) && !empty($this->getWebsites()['official'])) {
            $official = $this->getWebsites()['official'];
            $official = parse_url($official, PHP_URL_HOST);
            $official = str_replace('www.', '', $official);
            return $official;
        }
        return false;

    }

    public function getSocialReach() {
        return $this->socialReach;
    }

    public function getSocialData() {
        return $this->socialData;
    }

    public function getNativeNames() {
        $names = $this->getName();
        unset($names['en']);
        foreach ($names as &$name) {
            $name = str_replace(' && ', ', ', $name);
        }
        return $names;
    }

    /**
     * Set code
     *
     * @param string $code
     *
     * @return Party
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
     * Set countryCode
     *
     * @param string $countryCode
     *
     * @return Party
     */
    public function setCountryCode($countryCode)
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    /**
     * Get countryCode
     *
     * @return string
     */
    public function getCountryCode()
    {
        return $this->countryCode;
    }

    /**
     * Set countryName
     *
     * @param string $countryName
     *
     * @return Party
     */
    public function setCountryName($countryName)
    {
        $this->countryName = $countryName;

        return $this;
    }

    /**
     * Get countryName
     *
     * @return string
     */
    public function getCountryName()
    {
        return $this->countryName;
    }

    /**
     * Set name
     *
     * @param array $name
     *
     * @return Party
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return array
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set type
     *
     * @param string $type
     *
     * @return Party
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
     * Set region
     *
     * @param string $region
     *
     * @return Party
     */
    public function setRegion($region)
    {
        $this->region = $region;

        return $this;
    }

    /**
     * Get region
     *
     * @return string
     */
    public function getRegion()
    {
        return $this->region;
    }

    /**
     * Set parentParty
     *
     * @param string $parentParty
     *
     * @return Party
     */
    public function setParentParty($parentParty)
    {
        $this->parentParty = $parentParty;

        return $this;
    }

    /**
     * Get parentParty
     *
     * @return string
     */
    public function getParentParty()
    {
        return $this->parentParty;
    }

    /**
     * Set headquarters
     *
     * @param array $headquarters
     *
     * @return Party
     */
    public function setHeadquarters($headquarters)
    {
        $this->headquarters = $headquarters;

        return $this;
    }

    /**
     * Get headquarters
     *
     * @return array
     */
    public function getHeadquarters()
    {
        return $this->headquarters;
    }

    /**
     * Set websites
     *
     * @param array $websites
     *
     * @return Party
     */
    public function setWebsites($websites)
    {
        $this->websites = $websites;

        return $this;
    }

    /**
     * Get websites
     *
     * @return array
     */
    public function getWebsites()
    {
        return $this->websites;
    }

    /**
     * Set socialNetworks
     *
     * @param array $socialNetworks
     *
     * @return Party
     */
    public function setSocialNetworks($socialNetworks)
    {
        $this->socialNetworks = $socialNetworks;

        return $this;
    }

    /**
     * Get socialNetworks
     *
     * @return array
     */
    public function getSocialNetworks()
    {
        return $this->socialNetworks;
    }

    /**
     * Set contact
     *
     * @param array $contact
     *
     * @return Party
     */
    public function setContact($contact)
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * Get contact
     *
     * @return array
     */
    public function getContact()
    {
        return $this->contact;
    }

    /**
     * Add intMembership
     *
     * @param \AppBundle\Entity\IntOrgMembership $intMembership
     *
     * @return Party
     */
    public function addIntMembership(\AppBundle\Entity\IntOrgMembership $intMembership)
    {
        $this->intMemberships[] = $intMembership;

        return $this;
    }

    /**
     * Remove intMembership
     *
     * @param \AppBundle\Entity\IntOrgMembership $intMembership
     */
    public function removeIntMembership(\AppBundle\Entity\IntOrgMembership $intMembership)
    {
        $this->intMemberships->removeElement($intMembership);
    }

    /**
     * Get intMemberships
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getIntMemberships()
    {
        return $this->intMemberships;
    }

    /**
     * Set logo
     *
     * @param string $logo
     *
     * @return Party
     */
    public function setLogo($logo)
    {
        $this->logo = $logo;

        return $this;
    }

    /**
     * Get logo
     *
     * @return string
     */
    public function getLogo()
    {
        return $this->logo;
    }

    /**
     * Set countryFlag
     *
     * @param string $countryFlag
     *
     * @return Party
     */
    public function setCountryFlag($countryFlag)
    {
        $this->countryFlag = $countryFlag;

        return $this;
    }

    /**
     * Get countryFlag
     *
     * @return string
     */
    public function getCountryFlag()
    {
        return $this->countryFlag;
    }

    /**
     * Set defunct
     *
     * @param boolean $defunct
     *
     * @return Party
     */
    public function setDefunct($defunct)
    {
        $this->defunct = $defunct;

        return $this;
    }

    /**
     * Get defunct
     *
     * @return boolean
     */
    public function getDefunct()
    {
        return $this->defunct;
    }
}
