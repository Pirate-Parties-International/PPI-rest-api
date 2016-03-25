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
     *      {"name"="show_active_parties", "description"="List active parties.", "pattern"="yes|no", "default"="yes"},
     *      {"name"="show_defunct_parties", "description"="List defunct parties.", "pattern"="yes|no", "default"="no"},
     *      {"name"="international_membership", "description"="List members of international organizations.",
     *              "pattern"="ppi|ppeu|any|all", "default"="all"},
     *      {"name"="sort_results_by", "description"="List results in a set order.", "pattern"="name|code|country", "default"="code"}
     *  },
     *  statusCodes={
     *         200="Returned when successful."
     *     }
     * )
     */
    public function partiesAction()
    {
/*      $countryFilter = $_GET['country'];      # currently obsolete, no countries with multiple parties
        $regionFilter = $_GET['region'];        # currently obsolete, always null
        $typeFilter = $_GET['party_type'];      # currently obsolete, always national
        $parentFilter = $_GET['parent_party'];  # currently obsolete, always null
*/      $membershipFilter = $_GET['international_membership'];
        $orderBy = $_GET['sort_results_by'];
        $activeTemp = $_GET['show_active_parties'];
        $defunctTemp = $_GET['show_defunct_parties'];
        switch ($defunctTemp) {
            case ('yes'):
                $includeDefunct = true;
                if ($activeTemp === 'no') {
                    $includeDefunct = 'only';
                }
                break;
            default:
                $includeDefunct = false;
        }

        # run through BaseController
        $allData = $this->getAllParties($includeDefunct, $membershipFilter, $orderBy); // , $countryFilter, $regionFilter, $typeFilter, $parentFilter);

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
