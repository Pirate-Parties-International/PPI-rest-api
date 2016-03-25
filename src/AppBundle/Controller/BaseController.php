<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;

class BaseController extends Controller
{
	public function getAllParties($includeDefunct = false, $membershipFilter = 'all', $orderBy = 'code') {

		$parties = $this->getDoctrine()
          ->getRepository('AppBundle:Party');
        $query = $parties->createQueryBuilder('qb')
          ->select('p')->from('AppBundle:Party', 'p');

        switch (true) {
            case ($includeDefunct === false):
                $query->where('p.defunct = false'); # show only non-defunct
                break;
            case ($includeDefunct === 'only'):
                $query->where('p.defunct = true'); # show only defunct
                break;
            default:
                # do nothing, i.e. exclude none, show all
        }

        switch ($membershipFilter) {
            case ('all'):
                break; # show all parties, i.e. do nothing
            case ('any'):
                $query->join('p.intMemberships', 'm');
                break;
            default: # if filter = 'ppi', 'ppeu' etc.
                $query
                  ->join('p.intMemberships', 'm')
                  ->innerJoin('m.intOrg', 'o')
                  ->where('o.code = :membership')
                  ->setParameter('membership', $membershipFilter);
        }

        switch ($orderBy) {
            case ('name'):
                $query->orderBy('p.name', 'ASC');
                break;
            case ('country'):
                $query->orderBy('p.countryName', 'ASC');
                break;
            case ('code'):
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
