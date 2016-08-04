<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Statistic
 *
 * @ORM\Table(name="statistic")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\StatisticRepository")
 */
class Statistic
{
    const TYPE_FACEBOOK   = 'fb';
    const TYPE_TWITTER    = 'tw';
    const TYPE_GOOGLEPLUS = 'g+';
    const TYPE_YOUTUBE    = 'yt';

    const SUBTYPE_LIKES       = 'L';
    const SUBTYPE_FOLLOWERS   = 'F';
    const SUBTYPE_FOLLOWING   = 'G';
    const SUBTYPE_POSTS       = 'P';
    const SUBTYPE_VIEWS       = 'V';
    const SUBTYPE_VIDEOS      = 'M';
    const SUBTYPE_SUBSCRIBERS = 'S';

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
     * @ORM\Column(name="code", type="string", length=20)
     */
    private $code;
    
    /**
     * @var string
     *
     * @ORM\Column(name="type", type="string", length=2)
     */
    private $type;

    /**
     * @var string
     *
     * @ORM\Column(name="subType", type="string", length=1)
     */
    private $subType;

    /**
     * @var int
     *
     * @ORM\Column(name="value", type="integer")
     */
    private $value;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="timestamp", type="datetime")
     */
    private $timestamp;


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
     * @return Statistic
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
     * Set subType
     *
     * @param string $subType
     *
     * @return Statistic
     */
    public function setSubType($subType)
    {
        $this->subType = $subType;

        return $this;
    }

    /**
     * Get subType
     *
     * @return string
     */
    public function getSubType()
    {
        return $this->subType;
    }

    /**
     * Set value
     *
     * @param integer $value
     *
     * @return Statistic
     */
    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Get value
     *
     * @return int
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set timestamp
     *
     * @param \DateTime $timestamp
     *
     * @return Statistic
     */
    public function setTimestamp($timestamp)
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Get timestamp
     *
     * @return \DateTime
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * Set code
     *
     * @param string $code
     *
     * @return Statistic
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
}
