<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;

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

    public function getTwitterFollowers($code) {
        return $this->getStat($code, Stat::TYPE_TWITTER, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getGooglePlusFollowers($code) {
        return $this->getStat($code, Stat::TYPE_GOOGLEPLUS, Stat::SUBTYPE_FOLLOWERS);
    }

    public function getYoutubeStatistics($code) {
        $out = [
            'subscribers' => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_SUBSCRIBERS),
            'views'       => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIEWS),
            'videoCount'  => $this->getStat($code, Stat::TYPE_YOUTUBE, Stat::SUBTYPE_VIDEOS),
        ];

        $videos = $this->getMeta($code, Metadata::TYPE_YOUTUBE_VIDEOS);

        if (!empty($videos)) {
            $out['videos'] = $videos;
        }

        return $out;
    }

    /**
     * Queries for a single statistic
     * @param  string  $code    Party Code
     * @param  string  $type    
     * @param  string $subType  <optional>
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


}
