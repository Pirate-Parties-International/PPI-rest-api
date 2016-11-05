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
     *  statusCodes={
     *         200="Returned when successful."
     *     }
     * )
     */
    public function partiesAction()
    {
    	$allData = $this->getAllParties();

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
     *      {"name"="page", "dataType"="int", "required"="false", "description"="Page of results to get (100 results per page)"}
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
        $page    = isset($_GET['page'])     ? $_GET['page']     : null;

        $data = $this->getAllSocial($code, $type, $subType, $page);

        $serializer = $this->get('jms_serializer');
        $data = $serializer->serialize($data, 'json');

        if ($data == empty) {
            return new JsonResponse(array("error"=>"No data found"), 404);
        }

        return new Response($data, 200);
    }


}
