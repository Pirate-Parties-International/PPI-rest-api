<?php

namespace PPI\ApiBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class DefaultController extends BaseController
{
    /**
     * @Route("/")
     * @Template()
     */
    public function indexAction()
    {

    	$allData = $this->getAllPartiesData();

    	$output = array();
    	foreach ($allData as $partyKey => $party) {
    		$output[$partyKey] = array(
    			'label' => $party->partyName->en,
    			'partyCode' => $party->partyCode,
    			'link' => '/party/' . $partyKey,
    			'apiLink' => '/api/v1/parties/' . $partyKey
    		); 
    		// $logo = $this->getPartyLogo($partyKey);
    		// if ($logo !== null) {
    		// 	$output[$partyKey]['logo'] = $logo;
    		// }	
    	}
        return array("parties" => $output);
    }

    /**
     * @Route("/party/{id}")
     * @Template()
     */
    public function partyAction($id)
    {
    	$party = $this->getOnePartyData($id);
		$cc = $party->countryCode;

    	$partyKey = strtolower($party->partyCode);

		$party->label       = $party->partyName->en;
		$party->partyCode   = $party->partyCode;
		$party->link        = '/party/' . $partyKey;
		$party->apiLink     = '/api/v1/parties/' . $partyKey;
		$party->logo        = false;
		$party->nativeLabel = $party->partyName->$cc;
		
		$logo = $this->getPartyLogo($partyKey);
		if ($logo !== null) {
			$party->logo = $logo;
		}	
    	// var_dump($party); die;
        return array("party" => $party);
    }


}
