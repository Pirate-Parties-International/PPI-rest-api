<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

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


}
