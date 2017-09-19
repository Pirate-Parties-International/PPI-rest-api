<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;
use AppBundle\Entity\SocialMedia as Sm;

class BaseController extends Controller
{
    public function getAllParties($showDefunct = false, $membershipFilter = null, $orderBy = 'code') {
        $parties = $this->getDoctrine()
            ->getRepository('AppBundle:Party');

        $query = $parties->createQueryBuilder('qb')
            ->select('p')->from('AppBundle:Party', 'p');

        if (!is_null($membershipFilter)) {
            $query->join('p.intMemberships', 'm')
                ->innerJoin('m.intOrg', 'o')
                ->where(sprintf("o.code = '%s'", $membershipFilter)); // show only 'ppi', 'ppeu' etc.
        } // else do nothing, i.e. show all

        if (!is_null($showDefunct)) {
            $query->andwhere(sprintf("p.defunct = '%s'", $showDefunct)); // true = show only defunct, false = only non-defunct
        } // else do nothing, i.e. show all

        switch ($orderBy) {
            case 'name':
                $query->orderBy('p.name', 'ASC');
                break;
            case 'country':
                $query->orderBy('p.countryName', 'ASC');
                break;
            default: // case 'code' or null
                $query->orderBy('p.code', 'ASC');
        }

        $parties = $query->getQuery()->getResult();

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

        $posts  = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_TEXT);
        if (!empty($posts)) {
            $out['posts'] = $posts;
        }
        $photos = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_IMAGE);
        if (!empty($photos)) {
            $out['photos'] = $photos;
        }
        $videos = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_VIDEO);
        if (!empty($videos)) {
            $out['videos'] = $videos;
        }
        $events = $this->getOneSocial($code, Sm::TYPE_FACEBOOK, Sm::SUBTYPE_EVENT);
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

        $tweets = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_TEXT);
        if (!empty($tweets)) {
            $out['tweets'] = $tweets;
        }
        $images = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_IMAGE);
        if (!empty($images)) {
            $out['images'] = $images;
        }
        $videos = $this->getOneSocial($code, Sm::TYPE_TWITTER, Sm::SUBTYPE_VIDEO);
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

        $videos = $this->getOneSocial($code, Sm::TYPE_YOUTUBE, Sm::SUBTYPE_VIDEO);
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
    * Queries for a single type of social media (used on this page)
    * @param  string $code
    * @param  string $type
    * @param  string $subType
    * @return SocialMedia
    */
    public function getOneSocial($code, $type, $subType) {
        $socialMedia = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia')
            ->findBy(['code' => $code, 'type' => $type, 'subType' => $subType]);

        if (!$socialMedia) {
            return false;
        }

        return $socialMedia;
    }


    /**
    * Queries for all social media (used by ApiController)
    * @param  string $code
    * @param  string $type
    * @param  string $subType
    * @param  string $orderBy
    * @param  int    $limit
    * @param  int    $offset
    * @return array
    */
    public function getAllSocial($code = null, $type = null, $subType = null, $orderBy = 'postTime', $limit = 100, $offset = 0, $fields = null) {
        $social = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia');

        $terms = [];
        $direction = ($orderBy == 'code') ? 'ASC' : 'DESC';

        if ($code) {
            $terms['code'] = $code;
        }
        if ($type) {
            $terms['type'] = $type;
        }
        if ($subType) {
            $terms['subType'] = $subType;
        }

        $socialMedia = $social->findBy($terms, [$orderBy => $direction], $limit, $offset);

        if ($fields) {
            $socialMedia = $this->selectSocial($socialMedia, $fields);
        }

        return $socialMedia;
    }


    /**
    * Builds an array of select fields to return to the API
    * @param  object socialMedia
    * @param  string fields
    * @return array
    */
    public function selectSocial($socialMedia, $fields) {

        $fields = str_replace(' ', '', $fields);
        $terms  = explode(',', $fields);

        foreach ($socialMedia as $social) {
            $data = $social->getPostData();
            $temp = [
                'code'     => $social->getCode(),
                'type'     => $social->getType(),
                'sub_type' => $social->getSubType(),
                'post_id'  => $data['id'],
                // 'postData' => $data
                ];

            foreach ($terms as $field) {

                if ($field == 'time') {
                    if ($temp['sub_type'] != 'E') {
                        $temp['post_'.$field] = isset($data['posted']) ? $data['posted'] : null;

                    } else $temp['post_'.$field] = isset($data['start_time']) ? $data['start_time'] : null;

                } else if ($field == 'shares' && $temp['type'] == 'tw') {
                    $temp['post_'.$field] = isset($data['retweets']) ? $data['retweets'] : null;

                } else $temp['post_'.$field] = isset($data[$field]) ? $data[$field] : null;

            }

            $out[] = $temp;
        }

        return $out;
    }

}
