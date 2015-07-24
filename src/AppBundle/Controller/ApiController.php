<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/api/v1/")
 */
class ApiController extends BaseController
{
    /**
     * @Route("parties/", name="ppi_api_parties")
     * @Method({"GET"})
     */
    public function partiesAction()
    {
    	$allData = $this->getAllPartiesData();

		return new JsonResponse($allData, 200);
    }

    /**
     * @Route("parties/{id}", name="ppi_api_parties_id")
     * @Method({"GET"})
     */
    public function partyAction($id) {
    	
        $data = $this->getOnePartyData($id);

    	if ($data === null) {
    		return new JsonResponse(array("error"=>"Party with this ID does not exsist"), 404);
    	}

    	return new JsonResponse($data, 200);
    }



}
