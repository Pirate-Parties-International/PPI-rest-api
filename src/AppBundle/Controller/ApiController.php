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
     *      {"name"="show_defunct", "dataType"="bool", "required"="false", "description"="List defunct parties."},
     *      {"name"="int_membership", "dataType"="string", "required"="false", "description"="List only members of an international organization. Use slug of the org (i.e. 'ppi', 'ppeu')"},
     *      {"name"="sort_results", "dataType"="string", "pattern"="name|code|country", "required"="false", "description"="List results in a set order."}
     *  },
     *  statusCodes={
     *         200="Returned when successful.",
     *         400="Returned upon bad request.",
     *         404="Returned when not found."
     *     }
     * )
     */
    public function partiesAction() {

        $showDefunct = (!isset($_GET['show_defunct'])) ? null : $_GET['show_defunct'];
        switch($showDefunct) {
            case null:
                break;
            case "true":
                $showDefunct = true;
                break;
            case "false":
                $showDefunct = false;
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: invalid parameter for the field 'show_defunct' (boolean expected)."), 400);
        }

        $membershipFilter = (!isset($_GET['int_membership'])) ? null : $_GET['int_membership'];

        $orderBy = (!isset($_GET['sort_results'])) ? null : $_GET['sort_results'];
        switch ($orderBy) {
            case null:
            case 'name':
            case 'code':
            case 'country':
                break;
            default:
                return new JsonResponse(array("error"=>"Bad request: '".$orderBy."' is not a valid parameter for the field 'sort_results'."), 400);
        }

        // run through BaseController
        $allData = $this->getAllParties($showDefunct, $membershipFilter, $orderBy);

        $serializer = $this->get('jms_serializer');
        $allData = $serializer->serialize($allData, 'json');

        if (empty($allData)) {
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
    	
        $party = $this->getOneParty($id);

        $social['twitter']  = $this->getTwitterFollowers($id);     
        $social['facebook'] = $this->getFacebookLikes($id);     
        $social['gplus']    = $this->getGooglePlusFollowers($id);
        $social['youtube']  = $this->getYoutubeData($id);

        $party->socialData = $social;

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($party, 'json');

    	if ($data === null) {
    		return new JsonResponse(array("error"=>"Party with this ID does not exsist"), 404);
    	}

    	return new Response($data, 200);
    }

    /**
     * List data about social media
     *
     * @Route("social/", name="ppi_api_social")
     * @Method({"GET"})
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Social",
     *  filters={
     *      {"name"="code", "dataType"="string", "required"="false", "description"="Get only posts from one party (by code, i.e. ppsi, ppse)"},
     *      {"name"="type", "dataType"="string", "required"="false", "description"="Get only Facebook, Twitter or YouTube posts", "pattern"="fb | tw | yt"},
     *      {"name"="sub_type", "dataType"="string", "required"="false", "description"="Get only text posts, images, videos or events", "pattern"="t | i | v | e"},
     *      {"name"="order_by", "dataType"="string", "required"="false", "description"="Order to return results", "pattern"="code | likes | date"},
     *      {"name"="limit", "dataType"="int", "required"="false", "description"="Number of results to return (default 100)"},
     *      {"name"="offset", "dataType"="int", "required"="false", "description"="Start point of results"}
     *  },
     *  statusCodes={
     *          200="Returned when successful.",
     *          404="Returned when not found."
     *  }
     * )
     */
    public function socialAction() {

        $code    = isset($_GET['code'])     ? $_GET['code']     : null;
        $type    = isset($_GET['type'])     ? $_GET['type']     : null;
        $subType = isset($_GET['sub_type']) ? $_GET['sub_type'] : null;
        $limit   = isset($_GET['limit'])    ? $_GET['limit']    : 100;
        $offset  = isset($_GET['offset'])   ? $_GET['offset']   : 0;

        if (isset($_GET['order_by'])) {
            switch ($_GET['order_by']) {
                case 'time':
                case 'date':
                    $orderBy = 'postTime';
                    break;
                case 'likes':
                    $orderBy = 'postLikes';
                    break;
                default: // case 'code' or null
                    $orderBy = 'code';
            }
        } else $orderBy = 'code';

        $data = $this->getAllSocial($code, $type, $subType, $orderBy, $limit, $offset);

        if (empty($data)) {
            return new JsonResponse(array("error"=>"No data found"), 404);
        }

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($data, 'json');

        return new Response($data, 200);
    }

}
