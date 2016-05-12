<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;

class BaseController extends Controller
{
	public function getAllParties(
/*      $countryFilter,     // currently obsolete, redundant with getOneParty()
        $regionFilter,      // currently obsolete, always null
        $typeFilter,        // currently obsolete, always 'national'
        $parentFilter,      // currently obsolete, always null
*/      $showDefunct = false,
        $membershipFilter = null,
        $orderBy = 'code'
        ) {

		$parties = $this->getDoctrine()
          ->getRepository('AppBundle:Party');
        $query = $parties->createQueryBuilder('qb')
          ->select('p')->from('AppBundle:Party', 'p');

        switch ($showDefunct) {
            case true:
                $query->where('p.defunct = true'); // show only defunct
                break;
            case null:
                // do nothing, i.e. show all
                break;
            default: // case false
                $query->where('p.defunct = false'); // show only non-defunct
        }

        switch ($membershipFilter) {
            case null:
                // do nothing, i.e. show all
                break;
            default: // case 'ppi', 'ppeu', etc.
                $query
                  ->join('p.intMemberships', 'm')
                  ->innerJoin('m.intOrg', 'o')
                  ->where('o.code = :membership')
                  ->setParameter('membership', $membershipFilter);
        }

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

/*      if (!is-null($countryFilter)) {
            $query->where('p.countryCode = :country')
              ->setParameter('country', $countryFilter);
        }
        if (!is_null($regionFilter)) {
            $query->where('p.region = :region')
              ->setParameter('region', $regionFilter);
        }
        if (!is_null($typeFilter)) {
            $query->where('p.type = :type')
              ->setParameter('type', $typeFilter);
        }
        if (!is_null($parentFilter)) {
            $query->where('p.parentParty = :parent')
              ->setParameter('parent', $parentFilter);
        }
*/

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
