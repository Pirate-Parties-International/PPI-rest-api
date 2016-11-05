<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;
use AppBundle\Entity\SocialMedia as Sm;

class BaseController extends Controller
{
	public function getAllParties($includeDefunct = false) {
		$parties = $this->getDoctrine()
            ->getRepository('AppBundle:Party');

        if (!$includeDefunct) {
            $parties = $parties->findBy([
                'defunct' => $includeDefunct
            ]);
        } else {
            $parties = $parties->findAll();
        }
    	
    	$allData = array();
    	foreach ($parties as $party) {
    		$allData[strtolower($party->getCode())] = $party;
    	}

    	return $allData;
	}

	public function getOneParty($code) {
		$party = $this->getDoctrine()
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        return $party;
	}


    public function getCoverImage($code) {
        $meta = $this->getMeta($code, Metadata::TYPE_FACEBOOK_COVER);

        if(!$meta) {
            return '/img/generic_cover.jpg';
        }

        return $meta;
    }


    public function getFacebookLikes($code) {
        return $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_LIKES);
    }

    public function getFacebookData($code) {
        $out = [
            'likes'        => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_LIKES),
            'talkingAbout' => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_TALKING),
            'postCount'    => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_POSTS),
            'photoCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_IMAGES),
            'videoCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_VIDEOS),
            'eventCount'   => $this->getStat($code, Stat::TYPE_FACEBOOK, Stat::SUBTYPE_EVENTS),
        ];
        $out['pageInfo'] = $this->getMeta($code, Metadata::TYPE_FACEBOOK_INFO);

        $posts  = $this->getSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_TEXT);
        if (!empty($posts)) {
            $out['posts'] = $posts;
        }
        $photos = $this->getSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_IMAGE);
        if (!empty($photos)) {
            $out['photos'] = $photos;
        }
        $videos = $this->getSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }
        $events = $this->getSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_EVENT);
        if (!empty($events)) {
            $out['events'] = $events;
        }

        return $out;
    }


    public function getTwitterFollowers($code) {
        return $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getTwitterData($code) {
        $out = [
            'likes'      => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_LIKES),
            'followers'  => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS),
            'following'  => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWING),
            'tweetCount' => $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_POSTS),
        ];
        $out['pageInfo']['about'] = $this->getMeta($code, Metadata::TYPE_TWITTER_INFO);

        $tweets = $this->getSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_TEXT);
        if (!empty($tweets)) {
            $out['tweets'] = $tweets;
        }
        $images = $this->getSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_IMAGE);
        if (!empty($tweets)) {
            $out['images'] = $images;
        }
        $videos = $this->getSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }

        return $out;
    }


    public function getGooglePlusFollowers($code) {
        return $this->getStat($code, Stat::TYPE_GOOGLEPLUS, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getYoutubeData($code) {
        $out = [
            'subscribers' => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_SUBSCRIBERS),
            'views'       => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIEWS),
            'videoCount'  => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIDEOS),
        ];

        $videos = $this->getSocial($code, Sm::TYPE_YOUTUBE, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }

        return $out;
    }


    /**
     * Queries for a single statistic
     * @param  string $code    Party Code
     * @param  string $type
     * @param  string $subType <optional>
     * @return Statistic
     */
    public function getStat($code, $type, $subType) {
        $stat = $this->getDoctrine()
            ->getRepository('AppBundle:Statistic')
            ->findOneBy([
                'code'    => strtolower($code),
                'type'    => $type,
                'subType' => $subType
            ],
            [
                'timestamp' => 'DESC'
            ]
        );

        if (!$stat) {
            return '?';
        }

        return $stat->getValue();
    }


    /**
     * Queries for a single meta value
     * @param  string $code
     * @param  string $type
     * @return Metadata
     */
    public function getMeta($code, $type) {
        $meta = $this->getDoctrine()
            ->getRepository('AppBundle:Metadata')
            ->findOneBy([
                'code' => strtolower($code),
                'type' => $type
            ]);

        if(!$meta) {
            return false;
        }

        return $meta->getValue();
    }


    /**
    * Queries for a party's social media posts
    * @param  string $code
    * @param  string $type
    * @param  string $subType
    * @return SocialMedia
    */
    public function getSocial($code, $type, $subType) {
        $socialMedia = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia')
            ->findBy(['code' => $code, 'type' => $type, 'subType' => $subType]);

        if (!$socialMedia) {
            return false;
        }

        return $socialMedia;
    }


    public function getAllSocial($code = null, $type = null, $subType = null, $page = 0) {
        $social = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia');

        if (!$code && !$type && !$subType) {
            $socialMedia = $social->findAll();
        } else {
            if ($code) {
                $terms['code'] = $code;
            }
            if ($type) {
                $terms['type'] = $type;
            }
            if ($subType) {
                $terms['subType'] = $subType;
            }
            $socialMedia = $social->findBy($terms);
        }

        $allData = array();
        foreach ($socialMedia as $post) {
            $allData[] = $post;
        }
        $allData = array_slice($allData, ($page*100), 100);

        return $allData;
    }


    public function getSocialForm($socialMedia) {
        $form = $this->createFormBuilder($socialMedia)
            ->add('display', ChoiceType::class, [
                'choices' => [
                    'All data'       => 'xxx',
                    'All text posts' => 'xxt',
                    'All images'     => 'xxi',
                    'All videos'     => 'xxv',
                    'Facebook' => [
                        'All FB posts'     => 'fbx',
                        'FB statuses only' => 'fbt',
                        'FB images only'   => 'fbi',
                        'FB videos only'   => 'fbv',
                        'FB events only'   => 'fbe',
                        ],
                    'Twitter' => [
                        'All tweets'        => 'twx',
                        'Text tweets only'  => 'twt',
                        'Image tweets only' => 'twi',
                        'Video tweets only' => 'twv',
                        ],
                    'Youtube' => [
                        'YT videos only' => 'ytv',
                        ],
                    ],
                'choices_as_values' => true,
                ]
            )
            ->add('submit', SubmitType::class, array('label' => 'Submit'))
            ->getForm();

        return $form;
    }

}
