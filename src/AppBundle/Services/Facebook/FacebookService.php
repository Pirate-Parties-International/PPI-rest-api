<?php
namespace AppBundle\Services\Facebook;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;

class FacebookService
{
    private   $container;
    protected $log;
    protected $connect;
    protected $db;
    protected $images;
    protected $fbPosts;
    protected $fbImages;
    protected $fbEvents;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->log       = $this->container->get('logger');
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        $this->fbPosts   = $this->container->get('FbPostService');
        $this->fbImages  = $this->container->get('FbImageService');
        $this->fbEvents  = $this->container->get('FbEventService');
        @set_exception_handler([$this->connect, 'exception_handler']);
    }


    /**
     * Queries for stats, posts, images and events
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  string $scrapeData
     * @param  bool   $scrapeFull
     * @return array
     */
    public function getFBData($partyCode, $fbPageId, $scrapeData = null, $scrapeFull = false) {
        $fb        = $this->connect->getNewFacebook();
        $graphNode = $this->connect->getFbGraphNode($fbPageId, 'engagement');

        if (empty($graphNode)) {
            return false;
        }

        if ($scrapeData == null || $scrapeData == 'info') {
            $out = $this->getPageInfo($partyCode, $fbPageId);
            $out['postCount']  = $this->fbPosts->getPostCount($partyCode, $fbPageId, $fb, $scrapeFull);
            $out['videoCount'] = $this->fbPosts->getVideoCount($partyCode, $fbPageId, $fb);
            $out['cover'] = isset($out['cover']) ? $out['cover'] : null;
        }

        if ($scrapeData == 'info') {
            $out['imageCount'] = $this->fbImages->getImageCount($partyCode, $fbPageId);
            $out['eventCount'] = $this->fbEvents->getEventCount($partyCode, $fbPageId, $fb);
        }

        if ($scrapeData == null || $scrapeData == 'posts') {
            $temp = $this->fbPosts->getPosts($partyCode, $fbPageId, $fb, $scrapeFull);
            $out['posts']  = isset($temp['posts'])  ? $temp['posts']  : null;
            $out['videos'] = isset($temp['videos']) ? $temp['videos'] : null;
        }

        if ($scrapeData == null || $scrapeData == 'images') {
            $temp = $this->fbImages->getImages($partyCode, $fbPageId, $fb, $scrapeFull);
            $out['imageCount'] = isset($temp['imageCount']) ? $temp['imageCount'] : null;
            $out['images']     = isset($temp['images'])     ? $temp['images']     : null;
        }

        if ($scrapeData == null || $scrapeData == 'events') {
            $temp = $this->fbEvents->getEvents($partyCode, $fbPageId, $fb, $scrapeFull);
            $out['eventCount'] = isset($temp['eventCount']) ? $temp['eventCount'] : null;
            $out['events']     = isset($temp['events'])     ? $temp['events']     : null;
        }

        return $out;
    }


    /**
     * Basic info about a FB page
     * @param  string $partyCode
     * @param  string $fbPageId
     * @return array
     */
    public function getPageInfo($partyCode, $fbPageId) {
        $requestFields = 'cover,engagement,talking_about_count,about,emails,single_line_address';
        $graphNode     = $this->connect->getFbGraphNode($fbPageId, $requestFields);
        $array         = [];

        if (empty($graphNode) || is_null($graphNode->getField('engagement'))) {
            $this->log->notice("    - Facebook info not found for " . $partyCode);
            return false;
        }

        $info = [
            'about'   => $graphNode->getField('about'),
            'address' => $graphNode->getField('single_line_address')
        ];

        $fdEmails = $graphNode->getField('emails');
        if (!empty($fdEmails)) {
            foreach ($fdEmails as $key => $email) {
                $info['email'][] = $email;
            }
        }

        $this->db->addMeta(
            $partyCode,
            Metadata::TYPE_FACEBOOK_INFO,
            json_encode($info)
        );
        $array['info'] = true;


        if (!empty($graphNode->getField('engagement'))) {
            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_LIKES,
                $graphNode->getField('engagement')->getField('count')
            );
            $array['likes'] = true;
        }

        if (!empty($graphNode->getField('talking_about_count'))) {
            $this->db->addStatistic(
                $partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_TALKING,
                $graphNode->getField('talking_about_count')
            );
            $array['talking'] = true;
        }

        $this->log->info("    + Info and stats... ok");

        $array['cover'] = $this->getCover($partyCode, $graphNode);

        return $array;
    }


    /**
     * Retrieves FB cover image and saves to disk
     * @param  string $partyCode
     * @param  object $graphNode
     * @return bool
     */
    public function getCover($partyCode, $graphNode) {
        if (empty($graphNode->getField('cover'))) {
            $this->log->notice("    - No Facebook cover found for " . $partyCode);
            return null;
        }

        $coverId = $graphNode->getField('cover')->getField('cover_id');
        $imgSrc  = !is_null($coverId) ? $this->images->getFbImageSource($coverId, true) : null;
        $cover   = !is_null($imgSrc)  ? $this->images->getFacebookCover($partyCode, $imgSrc) : null;

        if (is_null($cover)) {
            $this->log->notice("    - No Facebook cover found for " . $partyCode);
            return null;
        }

        $this->db->addMeta(
            $partyCode,
            Metadata::TYPE_FACEBOOK_COVER,
            $cover
        );

        $this->log->info("    + Cover retrieved");
        return true;
    }


    /**
    * Counts likes/reactions/comments etc.
    * @param  object $data
    * @return int
    */
    public function getStatCount($data) {
        if (!empty($data)) {
            $meta  = $data->getMetadata();
            $count = isset($meta['summary']['total_count']) ? $meta['summary']['total_count'] : null;
        } else {
            $count = null;
        }

        return $count;
    }

}