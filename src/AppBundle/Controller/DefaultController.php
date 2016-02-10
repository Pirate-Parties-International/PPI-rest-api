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

    	$allData = $this->getAllPartiesData();

    	$output = array();
    	foreach ($allData as $partyKey => $party) {

    		$output[$partyKey] = array(
                'label'      => $party->partyName->en,
                'partyCode'  => $party->partyCode,
                'link'       => '/party/' . $partyKey,
                'apiLink'    => '/api/v1/parties/' . $partyKey,
                'logo'       => false,
                'membership' => [
                    'ppeu' => isset($party->membership->ppeu) ? $party->membership->ppeu : false,
                    'ppi' => isset($party->membership->ppi) ? $party->membership->ppi : false
                ],
                'type'       => $party->type
    		); 
    		$logo = $this->getPartyLogo($partyKey);
    		if ($logo !== null) {
    			$output[$partyKey]['logo'] = $logo;
    		}
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
        $party->websites = (array) $party->websites;
    	// var_dump($party); die;
        return array("party" => $party);
    }

}