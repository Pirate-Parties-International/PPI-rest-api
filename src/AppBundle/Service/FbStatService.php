<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;

class FbStatService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $images;
    protected $log;

    protected $partyCode;
    protected $fbPageId;
    protected $fb;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        $this->log       = $this->container->get('logger');
        @set_exception_handler(array($this->connect, 'exception_handler'));
    }


    /**
     * @param  string $partyCode
     * @param  string $fbPageId
     * @param  object $fb
     */
    public function setVariables($partyCode, $fbPageId, $fb) {
        $this->partyCode = $partyCode;
        $this->fbPageId  = $fbPageId;
        $this->fb        = $fb;
    }


    /**
     * Basic info about a FB page
     * @param  stting $requestFields
     * @return array
     */
    public function getPageInfo() {
        $requestFields = 'cover,engagement,talking_about_count,about,emails,single_line_address';
        $graphNode     = $this->connect->getFbGraphNode($this->fbPageId, $requestFields);
        $array         = [];

        if (empty($graphNode) || is_null($graphNode->getField('engagement'))) {
            $this->log->notice("    - Facebook info not found for " . $this->partyCode);
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
            $this->partyCode,
            Metadata::TYPE_FACEBOOK_INFO,
            json_encode($info)
        );
        $array['info'] = true;


        if (!empty($graphNode->getField('engagement'))) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_LIKES,
                $graphNode->getField('engagement')->getField('count')
            );
            $array['likes'] = true;
        }

        if (!empty($graphNode->getField('talking_about_count'))) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_FACEBOOK,
                Statistic::SUBTYPE_TALKING,
                $graphNode->getField('talking_about_count')
            );
            $array['talking'] = true;
        }

        $this->log->info("    + Info and stats... ok");

        $array['cover'] = $this->getCover($graphNode);

        return $array;
    }


    /**
     * Retrieves FB cover image and saves to disk
     * @param  object $graphNode
     * @return bool
     */
    public function getCover($graphNode) {
        if (empty($graphNode->getField('cover'))) {
            $this->log->notice("    - No Facebook cover found for " . $this->partyCode);
            return null;
        }

        $coverId = $graphNode->getField('cover')->getField('cover_id');
        $imgSrc  = !is_null($coverId) ? $this->images->getFbImageSource($coverId, true) : null;
        $cover   = !is_null($imgSrc)  ? $this->images->getFacebookCover($this->partyCode, $imgSrc) : null;

        if (is_null($cover)) {
            $this->log->notice("    - No Facebook cover found for " . $this->partyCode);
            return null;
        }

        $this->db->addMeta(
            $this->partyCode,
            Metadata::TYPE_FACEBOOK_COVER,
            $cover
        );

        $this->log->info("    + Cover retrieved");
        return true;
    }


    /**
     * Post count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getPostCount() {
        $requestFields = 'posts{created_time}';
        $graphNode     = $this->connect->getFbGraphNode($this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('posts'))) {
            $this->log->notice("    - Error while counting Facebook text posts for " . $this->partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $fdPcount = $graphNode->getField('posts');
        if (empty($fdPcount)) {
            $this->log->notice("    - Error while counting Facebook text posts for " . $this->partyCode);
            return false;
        }

        $this->log->info("    + Counting text posts...");
        $oldCount  = $this->db->getStatLimit($this->partyCode, 'fb', 'T');
        $pageCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);

            foreach ($fdPcount as $key => $post) {
                $timeCheck = $post->getField('created_time')->getTimestamp(); // check time of last scraped post

                if ($timeCheck > $oldCount['time']) {
                    $temp['posts'][] = ['time' => $timeCheck];
                }
            }

            $pageCount++;

        } while ($timeCheck > $oldCount['time'] && $fdPcount = $this->fb->next($fdPcount)); // while next page is not null

        $postCount  = isset($temp['posts']) ? count($temp['posts']) : 0;
        $totalCount = $oldCount['value'] + $postCount;

        if ($totalCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $this->partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_POSTS,
            $totalCount
        );

        $this->log->debug("       + " . $postCount . " new text posts found");
        $this->log->info("      + Total " . $totalCount . " text posts to date");
        return true;
    }


    /**
     * Image count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getImageCount() {
        $requestFields = 'albums{count}';
        $graphNode     = $this->connect->getFbGraphNode($this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('albums'))) {
            $this->log->notice("    - Error while counting Facebook images for " . $this->partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $this->log->info("    + Counting images...");
        $fdAlbums   = $graphNode->getField('albums');
        $pageCount  = 0;
        $photoCount = [];

        foreach ($fdAlbums as $key => $album) {
            $this->log->debug("       + Page " . $pageCount);
            $photoCount[] = $album->getField('count');
            $pageCount++;
        }

        $imageCount = array_sum($photoCount);
        if ($imageCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $this->partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_IMAGES,
            $imageCount
        );

        $this->log->info("      + Total " . $imageCount . " images found");
        return true;
    }


    /**
     * Video count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getVideoCount() {
        $requestFields = 'videos{id}';
        $graphNode     = $this->connect->getFbGraphNode($this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('videos'))) {
            $this->log->notice("    - Error while counting Facebook videos for " . $this->partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $this->log->info("    + Counting videos...");
        $fdVcount  = $graphNode->getField('videos');
        $pageCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);
            foreach ($fdVcount as $key => $post) {
                $temp['videos'][] = ['id' => $post->getField('id')];
            }
            $pageCount++;
        } while ($fdVcount = $this->fb->next($fdVcount)); // while next page is not null

        $videoCount = isset($temp['videos']) ? count($temp['videos']) : 0;
        if ($videoCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $this->partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_VIDEOS,
            $videoCount
        );

        $this->log->info("      + Total " . $videoCount . " videos found");
        return true;
    }


    /**
     * Event count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getEventCount() {
        $requestFields = 'events{id}';
        $graphNode     = $this->connect->getFbGraphNode($this->fbPageId, $requestFields);

        if (empty($graphNode) || is_null($graphNode->getField('events'))) {
            $this->log->notice("    - Error while counting Facebook events for " . $this->partyCode);
            return false;
        }
        // var_dump($graphNode); exit;

        $this->log->info("    + Counting events...");
        $fdEvents  = $graphNode->getField('events');
        $pageCount = 0;
        $temp      = [];

        do {
            $this->log->debug("       + Page " . $pageCount);
            foreach ($fdEvents as $key => $event) {
                $temp['events'][] = ['id' => $event->getField('id')];
            }
            $pageCount++;
        } while ($fdEvents = $this->fb->next($fdEvents)); // while next page is not null

        $eventCount = isset($temp['events']) ? count($temp['events']) : 0;
        if ($eventCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $this->partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_IMAGES,
            $eventCount
        );

        $this->log->info("      + Total " . $eventCount . " events found");
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