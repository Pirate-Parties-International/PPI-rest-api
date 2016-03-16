<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

/**
 * @Route("/api/v1/")
 */
class ApiController extends BaseController
{
    /**
     * Lists data about ALL parties
     * 
     * @Route("parties/", name="ppi_api_parties")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Party",
     *  filters={
     *      {"name"="active_parties", "description"="List active parties.", "default"="yes"},
     *      {"name"="defunct_parties", "description"="List defunct parties.", "default"="no"},
     *      {"name"="ppi_members_only", "description"="Only list PPI members.", "default"="no"},
     *      {"name"="ppeu_members_only", "description"="Only list PPEU members.", "default"="no"}
     *  },
     *  statusCodes={
     *         200="Returned when successful."
     *     }
     * )
     */
    public function partiesAction()
    {
        // collect filter data
        $active = $_GET['active_parties'];
        $defunct = $_GET['defunct_parties'];
        $ppi = $_GET['ppi_members_only'];
        $ppeu = $_GET['ppeu_members_only'];

        // set default parameters before applying filters
        $includeDefunct = false; $membership = false;

        // set 'includeDefunct' param
        if ($defunct == 'yes') {
            $includeDefunct = true;
            if ($active != 'yes') $includeDefunct = 'only';
        }
        
        // set 'membership' param
        if ($ppi == 'yes' && $ppeu == 'yes') $membership = 'ppi+ppeu';
        else if ($ppi == 'yes') $membership = 'ppi';
        else if ($ppeu == 'yes') $membership = 'ppeu';
        
        // run through BaseController
        $allData = $this->getAllParties($includeDefunct, $membership);

        $serializer = $this->get('jms_serializer');
        $allData = $serializer->serialize($allData, 'json');
	    return new Response($allData, 200);
    }

    /**
     * List data about ONE party
     * 
     * @Route("parties/{id}", name="ppi_api_parties_id")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=true,
     *  section="Party",
     *  requirements={
     *      {"name"="id", "dataType"="string", "required"=true, "description"="Party code (i.e. 'ppsi')."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partyAction($id) {
    	
        $data = $this->getOneParty($id);

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($data, 'json');

    	if ($data === null) {
    		return new JsonResponse(array("error"=>"Party with this ID does not exsist"), 404);
    	}

    	return new Response($data, 200);
    }



}
