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
     *      {"name"="show_active_parties", "description"="List active parties.", "pattern"="true|false", "dataType"="boolean", "default"="true"},
     *      {"name"="show_defunct_parties", "description"="List defunct parties.", "pattern"="true|false", "dataType"="boolean", "default"="false"},
     *      {"name"="international_membership", "description"="List only members of an international organization.",
     *              "pattern"="ppi|ppeu|false", "default"="false"},
     *      {"name"="sort_results_by", "description"="List results in a set order.", "pattern"="name|code|country", "default"="code"}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         400="Returned upon bad request.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partiesAction()
    {
/*      $countryFilter = $_GET['country'];      // currently obsolete, no countries with multiple parties
        $regionFilter = $_GET['region'];        // currently obsolete, always null
        $typeFilter = $_GET['party_type'];      // currently obsolete, always national
        $parentFilter = $_GET['parent_party'];  // currently obsolete, always null
*/        $includeActive = $_GET['show_active_parties'];
        $includeDefunct = $_GET['show_defunct_parties'];
        $membershipFilter = $_GET['international_membership'];
        $orderBy = $_GET['sort_results_by'];

        if (!is_bool($includeActive)) {
            switch ($includeActive) {
                case ('true'):
                case ('1'):
                    $includeActive = (bool) true;
                    break;
                case ('false'):
                case ('0'):
                    $includeActive = (bool) false;
                    break;
                default:
                    return new JsonResponse(array("error"=>"Bad request: invalid parameter for the field 'show_active_parties' (boolean expected)."), 400);
            }
        }

        if (!is_bool($includeDefunct)) {
            switch ($includeDefunct) {
                case ('true'):
                case ('1'):
                    $includeDefunct = (bool) true;
                    break;
                case ('false'):
                case ('0'):
                    $includeDefunct = (bool) false;
                    break;
                default:
                    return new JsonResponse(array("error"=>"Bad request: invalid parameter for the field 'show_defunct_parties' (boolean expected)."), 400);
            }
        }

        if ($includeActive == false && $includeDefunct == false) {
            return new JsonResponse(array("error"=>"Search returned no results."), 404);
        }

        switch ($membershipFilter) {
            case ('false'):
            case ('0'):
                $membershipFilter = (bool) false;
                break;
            case (false):
            case ('ppi'):
            case ('ppeu'):
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$membershipFilter."' is not a valid parameter for the field 'international_membership'."), 400);
        }

        switch ($orderBy) {
            case ('name'):
            case ('code'):
            case ('country'):
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$orderBy."' is not a valid parameter for the field 'sort_results_by'."), 400);
        }

        // run through BaseController
        $allData = $this->getAllParties($includeActive, $includeDefunct, $membershipFilter, $orderBy); // , $countryFilter, $regionFilter, $typeFilter, $parentFilter);

        $serializer = $this->get('jms_serializer');
        $allData = $serializer->serialize($allData, 'json');

        if ($allData === null) {
            return new JsonResponse(array("error"=>"Search returned no results."), 404);
        }

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
