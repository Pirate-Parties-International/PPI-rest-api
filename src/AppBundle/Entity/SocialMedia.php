<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SocialMedia
 *
 * @ORM\Table(name="social_media")
 * @ORM\Entity(repositoryClass="AppBundle\Repository\SocialMediaRepository")
 */
class SocialMedia
{
    const TYPE_FACEBOOK = 'fb';
    const TYPE_TWITTER  = 'tw';
    const TYPE_YOUTUBE  = 'yt';

    const SUBTYPE_TEXT  = 'T';
    const SUBTYPE_IMAGE = 'I';
    const SUBTYPE_EVENT = 'E';
    const SUBTYPE_VIDEO = 'V';

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
     * @ORM\Column(name="code", type="string", length=10)
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
     * @var string
     *
     * @ORM\Column(name="postId", type="string")
     */
    private $postId;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="postTime", type="datetime")
     */
    private $postTime;

    /**
     * @var string
     *
     * @ORM\Column(name="postText", type="string", nullable=true)
     */
    private $postText;

    /**
     * @var string
     *
     * @ORM\Column(name="postImage", type="string", nullable=true)
     */
    private $postImage;

    /**
    * @var integer
    *
    * @ORM\Column(name="postLikes", type="integer")
    */
    private $postLikes;

    /**
     * @var array
     *
     * @ORM\Column(name="postData", type="json_array", nullable=true)
     */
    private $postData;

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
     * Set code
     *
     * @param string $code
     *
     * @return SocialMedia
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
     * Set type
     *
     * @param string $type
     *
     * @return SocialMedia
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
     * @return SocialMedia
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
     * Set postId
     *
     * @param string $postId
     *
     * @return SocialMedia
     */
    public function setPostId($postId)
    {
        $this->postId = $postId;

        return $this;
    }

    /**
     * Get postId
     *
     * @return string
     */
    public function getPostId()
    {
        return $this->postId;
    }


    /**
     * Set postTime
     *
     * @param \DateTime $postTime
     *
     * @return SocialMedia
     */
    public function setPostTime($postTime)
    {
        $this->postTime = $postTime;

        return $this;
    }

    /**
     * Get postTime
     *
     * @return \DateTime
     */
    public function getPostTime()
    {
        return $this->postTime;
    }


    /**
     * Set postText
     *
     * @param string $postText
     *
     * @return SocialMedia
     */
    public function setPostText($postText)
    {
        $this->postText = $postText;

        return $this;
    }

    /**
    * Get postText
    *
    * @return string
    */
    public function getPostText()
    {
        return $this->postText;
    }


    /**
     * Set postImage
     *
     * @param string $postImage
     *
     * @return SocialMedia
     */
    public function setPostImage($postImage)
    {
        $this->postImage = $postImage;

        return $this;
    }

    /**
     * Get postImage
     *
     * @return string
     */
    public function getPostImage()
    {
        return $this->postImage;
    }


    /**
     * Set postLikes
     *
     * @param int $postLikes
     *
     * @return SocialMedia
     */
    public function setPostLikes($postLikes)
    {
        $this->postLikes = $postLikes;

        return $this;
    }

    /**
     * Get postLikes
     *
     * @return int
     */
    public function getPostLikes()
    {
        return $this->postLikes;
    }


    /**
     * Set postData
     *
     * @param array $postData
     *
     * @return SocialMedia
     */
    public function setPostData($postData)
    {
        $this->postData = $postData;

        return $this;
    }

    /**
     * Get postData
     *
     * @return array
     */
    public function getPostData()
    {
        return json_decode($this->postData, true);
    }


    /**
     * Set timestamp
     *
     * @param \DateTime $timestamp
     *
     * @return SocialMedia
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
        return $this->timestamp->format('Y-m-d H:i:s');
    }

}
