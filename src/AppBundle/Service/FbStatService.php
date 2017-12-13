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

    protected $partyCode;
    protected $fbPageId;
    protected $fb;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler([$this->db, 'exception_handler']);
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
    public function getPageInfo($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);
        $array = [];

        echo "     + Info and stats.... ";
        if (empty($graphNode)) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

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

        $coverId = !empty($graphNode->getField('cover')) ? $graphNode->getField('cover')->getField('cover_id') : null;
        $array['cover'] = !is_null($coverId) ? $this->images->getFbImageSource($this->fb, $coverId, true) : null;

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
        echo "ok\n";

        // var_dump($array); exit;
        return $array;
    }


    /**
     * Post count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getPostCount($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        echo "     + Counting posts.... ";
        if (empty($graphNode) || !$graphNode->getField('posts')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdPcount  = $graphNode->getField('posts');
        echo "page ";
        $pageCount = 0;
        $temp      = [];

        do {
            echo $pageCount . ', ';
            foreach ($fdPcount as $key => $post) {
                $temp['posts'][] = ['id' => $post->getField('id')]; // count all posts
            }
            $pageCount++;
        } while ($fdPcount = $this->fb->next($fdPcount)); // while next page is not null

        $postCount = isset($temp['posts']) ? count($temp['posts']) : 0;
        if ($postCount == 0) {
            return false;
        }

        $this->db->addStatistic(
            $this->partyCode,
            Statistic::TYPE_FACEBOOK,
            Statistic::SUBTYPE_POSTS,
            $postCount
        );

        echo "...total " . $postCount . " found\n";
        return true;
    }


    /**
     * Image count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getImageCount($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        echo "     + Counting photos... ";
        if (empty($graphNode) || !$graphNode->getField('albums')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdAlbums   = $graphNode->getField('albums');
        echo "page ";
        $pageCount  = 0;
        $photoCount = [];

        foreach ($fdAlbums as $key => $album) {
            echo $pageCount . ", ";
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

        echo "...total " . $imageCount . " found\n";
        return true;
    }


    /**
     * Video count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getVideoCount($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        echo "     + Counting videos... ";
        if (empty($graphNode) || !$graphNode->getField('videos')) {
            echo "not found\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdVcount  = $graphNode->getField('videos');
        echo "page ";
        $pageCount = 0;
        $temp      = [];

        do {
            echo $pageCount . ', ';
            foreach ($fdVcount as $key => $post) {
                $temp['videos'][] = ['id' => $post->getField('id')]; // count all posts
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

        echo "...total " . $videoCount . " found\n";
        return true;
    }


    /**
     * Event count for stats only
     * @param  string $requestFields
     * @return int
     */
    public function getEventCount($requestFields) {
        $graphNode = $this->connect->getFbGraphNode($this->fb, $this->fbPageId, $requestFields);

        echo "     + Counting events... ";
        if (empty($graphNode) || !$graphNode->getField('events')) {
            echo "not found.\n";
            return false;
        }
        // var_dump($graphNode); exit;

        $fdEvents  = $graphNode->getField('events');
        echo "page ";
        $pageCount = 0;
        $temp      = [];

        do {
            echo $pageCount . ", ";
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

        echo "...total " . $eventCount . " found\n";
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