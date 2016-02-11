<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic as Stat;

class BaseController extends Controller
{

	public function getAllParties() {
		
		$parties = $this->getDoctrine()
        ->getRepository('AppBundle:Party')
        ->findAll();
    	
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
        $meta = $this->getDoctrine()
        ->getRepository('AppBundle:Metadata')
        ->findOneBy([
            'code' => strtolower($code),
            'type' => Metadata::TYPE_FACEBOOK_COVER
        ]);

        if(!$meta) {
            return '/img/generic_cover.jpg';
        }

        return $meta->getValue();
    }

    public function getFacebookLikes($code) {
        $stat = $this->getDoctrine()
        ->getRepository('AppBundle:Statistic')
        ->findOneBy([
                'code'    => strtolower($code),
                'type'    => Stat::TYPE_FACEBOOK,
                'subType' => Stat::SUBTYPE_LIKES
            ],
            [
                'timestamp' => 'DESC'
            ]
        );

        if (!$stat) {
            return '????';
        }

        return $stat->getValue();
    }


}
