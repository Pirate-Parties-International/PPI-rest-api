<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class DefaultController extends BaseController
{
    /**
     * @Route("/")
     * @Template()
     */
    public function indexAction()
    {

    	$parties = $this->getAllParties();

        return array("parties" => $parties);
    }

    /**
     * @Route("party/{id}", name="papi_party_show")
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
        $party->websites = (array) $party->websites;
    	// var_dump($party); die;
        return array("party" => $party);
    }

}
